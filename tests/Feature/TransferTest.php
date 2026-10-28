<?php

namespace Tests\Feature;

use App\Enums\LedgerEntryType;
use App\Models\LedgerEntry;
use App\Models\Transfer;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TransferTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_gets_401(): void
    {
        $this->getJson('/api/wallet')->assertUnauthorized();
        $this->getJson('/api/transfers')->assertUnauthorized();
        $this->postJson('/api/transfers', [], ['Idempotency-Key' => (string) Str::uuid()])->assertUnauthorized();
    }

    public function test_api_tokens_expire_after_24_hours(): void
    {
        $wallet = Wallet::factory()->create();
        $headers = ['Authorization' => 'Bearer '.$wallet->user->createToken('test')->plainTextToken];

        $this->travel(24 * 60 - 1)->minutes();
        $this->getJson('/api/wallet', $headers)->assertOk();

        // The guard caches the resolved user between requests within one test.
        $this->app['auth']->forgetGuards();

        $this->travel(2)->minutes();
        $this->getJson('/api/wallet', $headers)->assertUnauthorized();
    }

    public function test_user_can_view_own_wallet(): void
    {
        $wallet = Wallet::factory()->withBalance(12_345)->create();
        Sanctum::actingAs($wallet->user);

        $this->getJson('/api/wallet')
            ->assertOk()
            ->assertExactJson(['data' => ['balance' => 12_345, 'currency' => 'BDT', 'is_frozen' => false]]);
    }

    public function test_successful_transfer_updates_balances_and_writes_both_ledger_rows(): void
    {
        $sender = Wallet::factory()->withBalance(10_000)->create();
        $receiver = Wallet::factory()->withBalance(500)->create();

        $response = $this->postTransfer($sender->user, ['receiver_email' => $receiver->user->email, 'amount' => 2_500]);

        $response->assertCreated()
            ->assertHeaderMissing('Idempotent-Replayed')
            ->assertJsonPath('data.amount', 2_500)
            ->assertJsonPath('data.currency', 'BDT')
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.direction', 'out')
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.idempotency_key')
            ->assertJsonMissingPath('data.sender_wallet_id');

        $this->assertSame(7_500, $sender->fresh()->balance);
        $this->assertSame(3_000, $receiver->fresh()->balance);

        $transfer = Transfer::sole();
        $this->assertSame($response->json('data.reference'), $transfer->reference);

        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertDatabaseHas('ledger_entries', [
            'transfer_id' => $transfer->id,
            'wallet_id' => $sender->id,
            'type' => LedgerEntryType::Debit->value,
            'amount' => 2_500,
            'balance_after' => 7_500,
        ]);
        $this->assertDatabaseHas('ledger_entries', [
            'transfer_id' => $transfer->id,
            'wallet_id' => $receiver->id,
            'type' => LedgerEntryType::Credit->value,
            'amount' => 2_500,
            'balance_after' => 3_000,
        ]);
    }

    public function test_insufficient_balance_changes_nothing(): void
    {
        $sender = Wallet::factory()->withBalance(1_000)->create();
        $receiver = Wallet::factory()->create();

        $this->postTransfer($sender->user, ['receiver_email' => $receiver->user->email, 'amount' => 1_001])
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'Insufficient balance.', 'code' => 'INSUFFICIENT_BALANCE']);

        $this->assertSame(1_000, $sender->fresh()->balance);
        $this->assertSame(0, $receiver->fresh()->balance);
        $this->assertDatabaseEmpty('transfers');
        $this->assertDatabaseEmpty('ledger_entries');
    }

    public function test_same_idempotency_key_charges_only_once(): void
    {
        $sender = Wallet::factory()->withBalance(10_000)->create();
        $receiver = Wallet::factory()->create();
        $payload = ['receiver_email' => $receiver->user->email, 'amount' => 3_000];
        $headers = ['Idempotency-Key' => (string) Str::uuid()];

        $first = $this->postTransfer($sender->user, $payload, $headers)->assertCreated();
        $second = $this->postTransfer($sender->user, $payload, $headers)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json('data'), $second->json('data'));
        $this->assertSame(7_000, $sender->fresh()->balance);
        $this->assertSame(3_000, $receiver->fresh()->balance);
        $this->assertDatabaseCount('transfers', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public function test_reusing_idempotency_key_with_different_amount_is_rejected(): void
    {
        $sender = Wallet::factory()->withBalance(10_000)->create();
        $receiver = Wallet::factory()->create();
        $headers = ['Idempotency-Key' => (string) Str::uuid()];

        $this->postTransfer($sender->user, ['receiver_email' => $receiver->user->email, 'amount' => 3_000], $headers)
            ->assertCreated();

        $this->postTransfer($sender->user, ['receiver_email' => $receiver->user->email, 'amount' => 4_000], $headers)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertSame(7_000, $sender->fresh()->balance);
        $this->assertDatabaseCount('transfers', 1);
    }

    public function test_reusing_idempotency_key_with_different_receiver_is_rejected(): void
    {
        $sender = Wallet::factory()->withBalance(10_000)->create();
        [$receiverA, $receiverB] = Wallet::factory()->count(2)->create();
        $headers = ['Idempotency-Key' => (string) Str::uuid()];

        $this->postTransfer($sender->user, ['receiver_email' => $receiverA->user->email, 'amount' => 3_000], $headers)
            ->assertCreated();

        $this->postTransfer($sender->user, ['receiver_email' => $receiverB->user->email, 'amount' => 3_000], $headers)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertSame(0, $receiverB->fresh()->balance);
    }

    public function test_idempotency_key_header_is_required(): void
    {
        $sender = Wallet::factory()->withBalance(10_000)->create();
        $receiver = Wallet::factory()->create();

        $this->postTransfer($sender->user, ['receiver_email' => $receiver->user->email, 'amount' => 1_000], headers: [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key' => 'The Idempotency-Key header is required.']);

        $this->assertDatabaseEmpty('transfers');
    }

    public function test_idempotency_key_must_be_a_uuid(): void
    {
        $sender = Wallet::factory()->withBalance(10_000)->create();
        $receiver = Wallet::factory()->create();

        $this->postTransfer($sender->user, ['receiver_email' => $receiver->user->email, 'amount' => 1_000], ['Idempotency-Key' => 'not-a-uuid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
    }

    public function test_idempotency_key_in_body_cannot_replace_the_header(): void
    {
        $sender = Wallet::factory()->withBalance(10_000)->create();
        $receiver = Wallet::factory()->create();

        $this->postTransfer($sender->user, [
            'receiver_email' => $receiver->user->email,
            'amount' => 1_000,
            'idempotency_key' => (string) Str::uuid(),
        ], headers: [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
    }

    public function test_cannot_transfer_to_self(): void
    {
        $sender = Wallet::factory()->withBalance(10_000)->create();

        $this->postTransfer($sender->user, ['receiver_email' => $sender->user->email, 'amount' => 1_000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['receiver_email' => 'You cannot transfer money to yourself.']);

        $this->assertSame(10_000, $sender->fresh()->balance);
        $this->assertDatabaseEmpty('transfers');
    }

    public function test_cannot_transfer_to_self_using_different_email_case(): void
    {
        $sender = Wallet::factory()->withBalance(10_000)->create();

        $this->postTransfer($sender->user, ['receiver_email' => '  '.strtoupper($sender->user->email).' ', 'amount' => 1_000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['receiver_email' => 'You cannot transfer money to yourself.']);

        $this->assertDatabaseEmpty('transfers');
    }

    public function test_receiver_email_is_matched_case_insensitively(): void
    {
        $sender = Wallet::factory()->withBalance(10_000)->create();
        $receiver = Wallet::factory()->for(User::factory()->state(['email' => 'Bob.Smith@Example.com']))->create();

        $this->postTransfer($sender->user, ['receiver_email' => 'BOB.SMITH@example.COM', 'amount' => 1_000])
            ->assertCreated();

        $this->assertSame(1_000, $receiver->fresh()->balance);
    }

    public function test_unknown_receiver_is_rejected(): void
    {
        $sender = Wallet::factory()->withBalance(10_000)->create();

        $this->postTransfer($sender->user, ['receiver_email' => 'nobody@example.com', 'amount' => 1_000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('receiver_email');

        $this->assertDatabaseEmpty('transfers');
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidAmounts(): array
    {
        return [
            'missing' => [null],
            'below minimum' => [99],
            'zero' => [0],
            'negative' => [-500],
            'above maximum' => [5_000_001],
            'decimal' => [100.5],
            'decimal string' => ['150.75'],
            'non-numeric string' => ['abc'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amount_is_rejected(mixed $amount): void
    {
        $sender = Wallet::factory()->withBalance(10_000_000)->create();
        $receiver = Wallet::factory()->create();

        $this->postTransfer($sender->user, ['receiver_email' => $receiver->user->email, 'amount' => $amount])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertSame(10_000_000, $sender->fresh()->balance);
        $this->assertDatabaseEmpty('transfers');
    }

    public function test_frozen_sender_gets_403(): void
    {
        $sender = Wallet::factory()->withBalance(10_000)->frozen()->create();
        $receiver = Wallet::factory()->create();

        $this->postTransfer($sender->user, ['receiver_email' => $receiver->user->email, 'amount' => 1_000])
            ->assertForbidden();

        $this->assertSame(10_000, $sender->fresh()->balance);
        $this->assertDatabaseEmpty('transfers');
    }

    public function test_frozen_receiver_gets_422(): void
    {
        $sender = Wallet::factory()->withBalance(10_000)->create();
        $receiver = Wallet::factory()->frozen()->create();

        $this->postTransfer($sender->user, ['receiver_email' => $receiver->user->email, 'amount' => 1_000])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'RECEIVER_UNAVAILABLE');

        $this->assertSame(10_000, $sender->fresh()->balance);
        $this->assertSame(0, $receiver->fresh()->balance);
        $this->assertDatabaseEmpty('transfers');
    }

    public function test_receiver_without_wallet_gets_same_error_as_frozen_receiver(): void
    {
        $sender = Wallet::factory()->withBalance(10_000)->create();
        $walletless = User::factory()->create();

        $this->postTransfer($sender->user, ['receiver_email' => $walletless->email, 'amount' => 1_000])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'RECEIVER_UNAVAILABLE');
    }

    public function test_users_only_see_their_own_transfers(): void
    {
        [$alice, $bob, $carol, $dave] = Wallet::factory()->count(4)->withBalance(10_000)->create();

        $aliceToBob = $this->postTransfer($alice->user, ['receiver_email' => $bob->user->email, 'amount' => 1_000])
            ->json('data.reference');
        $carolToDave = $this->postTransfer($carol->user, ['receiver_email' => $dave->user->email, 'amount' => 2_000])
            ->json('data.reference');

        Sanctum::actingAs($alice->user);
        $this->getJson('/api/transfers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', $aliceToBob)
            ->assertJsonPath('data.0.direction', 'out')
            ->assertJsonPath('meta.total', 1);

        Sanctum::actingAs($bob->user);
        $this->getJson('/api/transfers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', $aliceToBob)
            ->assertJsonPath('data.0.direction', 'in');

        Sanctum::actingAs(Wallet::factory()->create()->user);
        $this->getJson('/api/transfers')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertDontSee($aliceToBob)
            ->assertDontSee($carolToDave);
    }

    public function test_ledger_credits_minus_debits_equal_wallet_balance(): void
    {
        $opening = [10_000, 5_000, 0];
        $wallets = collect($opening)->map(fn (int $balance) => Wallet::factory()->withBalance($balance)->create());
        [$a, $b, $c] = $wallets->all();

        $this->postTransfer($a->user, ['receiver_email' => $b->user->email, 'amount' => 3_000])->assertCreated();
        $this->postTransfer($b->user, ['receiver_email' => $c->user->email, 'amount' => 6_500])->assertCreated();
        $this->postTransfer($c->user, ['receiver_email' => $a->user->email, 'amount' => 1_200])->assertCreated();
        $this->postTransfer($c->user, ['receiver_email' => $b->user->email, 'amount' => 9_999])->assertUnprocessable();

        foreach ($wallets as $i => $wallet) {
            $credits = (int) LedgerEntry::where('wallet_id', $wallet->id)->where('type', LedgerEntryType::Credit)->sum('amount');
            $debits = (int) LedgerEntry::where('wallet_id', $wallet->id)->where('type', LedgerEntryType::Debit)->sum('amount');

            $this->assertSame($opening[$i] + $credits - $debits, $wallet->fresh()->balance);
            $this->assertSame(
                $wallet->fresh()->balance,
                LedgerEntry::where('wallet_id', $wallet->id)->latest('id')->value('balance_after'),
            );
        }

        // Double entry: every debit has a matching credit, and no money was created or destroyed.
        $this->assertSame(
            (int) LedgerEntry::where('type', LedgerEntryType::Debit)->sum('amount'),
            (int) LedgerEntry::where('type', LedgerEntryType::Credit)->sum('amount'),
        );
        $this->assertSame(array_sum($opening), (int) Wallet::sum('balance'));
    }

    public function test_transfer_endpoint_is_rate_limited(): void
    {
        $sender = Wallet::factory()->withBalance(10_000_000)->create();
        $receiver = Wallet::factory()->create();
        $payload = ['receiver_email' => $receiver->user->email, 'amount' => 100];

        for ($i = 0; $i < 10; $i++) {
            $this->postTransfer($sender->user, $payload)->assertCreated();
        }

        $this->postTransfer($sender->user, $payload)->assertTooManyRequests();
    }

    public function test_read_endpoints_are_rate_limited(): void
    {
        Sanctum::actingAs(Wallet::factory()->create()->user);

        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/api/wallet')->assertOk();
            $this->getJson('/api/transfers')->assertOk();
        }

        $this->getJson('/api/wallet')->assertTooManyRequests();
    }

    public function test_reads_do_not_consume_the_transfer_rate_limit(): void
    {
        $sender = Wallet::factory()->withBalance(10_000)->create();
        $receiver = Wallet::factory()->create();

        Sanctum::actingAs($sender->user);
        for ($i = 0; $i < 15; $i++) {
            $this->getJson('/api/wallet')->assertOk();
        }

        $this->postTransfer($sender->user, ['receiver_email' => $receiver->user->email, 'amount' => 1_000])
            ->assertCreated();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>|null  $headers  Defaults to a fresh Idempotency-Key.
     */
    private function postTransfer(User $sender, array $payload, ?array $headers = null): TestResponse
    {
        Sanctum::actingAs($sender);

        return $this->postJson('/api/transfers', $payload, $headers ?? ['Idempotency-Key' => (string) Str::uuid()]);
    }
}
