<?php

namespace App\Services;

use App\Enums\LedgerEntryType;
use App\Enums\TransferStatus;
use App\Exceptions\TransferException;
use App\Models\Transfer;
use App\Models\Wallet;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TransferService
{
    /**
     * Move money between two wallets exactly once per (sender, idempotency key).
     *
     * @param  int  $amount  Amount in paisa.
     * @return array{0: Transfer, 1: bool} The transfer, and whether it was an idempotent replay.
     *
     * @throws TransferException
     */
    public function transfer(Wallet $sender, Wallet $receiver, int $amount, string $idempotencyKey): array
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Transfer amount must be a positive number of paisa.');
        }

        if ($sender->is($receiver)) {
            throw TransferException::sameWallet();
        }

        try {
            return DB::transaction(fn () => $this->transferWithinTransaction($sender, $receiver, $amount, $idempotencyKey));
        } catch (UniqueConstraintViolationException $e) {
            // Safety net: a concurrent request with the same key committed first.
            // The sender row lock should prevent this, but the unique index is the final guarantee.
            $existing = $this->findByIdempotencyKey($sender, $idempotencyKey) ?? throw $e;
            $this->assertSameRequest($existing, $receiver, $amount);

            return [$existing, true];
        }
    }

    /**
     * @return array{0: Transfer, 1: bool}
     */
    private function transferWithinTransaction(Wallet $sender, Wallet $receiver, int $amount, string $idempotencyKey): array
    {
        [$sender, $receiver] = $this->lockWallets($sender, $receiver);

        // Checked only after the lock: concurrent requests from this sender are now serialized,
        // so a retry always sees the transfer committed by the first attempt.
        if ($existing = $this->findByIdempotencyKey($sender, $idempotencyKey)) {
            $this->assertSameRequest($existing, $receiver, $amount);

            return [$existing, true];
        }

        // Re-validate against the freshly locked rows, not the possibly stale models we were given.
        if ($sender->is_frozen) {
            throw TransferException::senderFrozen();
        }

        if ($receiver->is_frozen || $receiver->currency !== $sender->currency) {
            throw TransferException::receiverUnavailable();
        }

        if ($sender->balance < $amount) {
            throw TransferException::insufficientBalance();
        }

        $transfer = Transfer::create([
            'sender_wallet_id' => $sender->id,
            'receiver_wallet_id' => $receiver->id,
            'amount' => $amount,
            'idempotency_key' => $idempotencyKey,
            'status' => TransferStatus::Completed,
        ]);

        $sender->decrement('balance', $amount);
        $receiver->increment('balance', $amount);

        $transfer->ledgerEntries()->createMany([
            [
                'wallet_id' => $sender->id,
                'type' => LedgerEntryType::Debit,
                'amount' => $amount,
                'balance_after' => $sender->balance,
            ],
            [
                'wallet_id' => $receiver->id,
                'type' => LedgerEntryType::Credit,
                'amount' => $amount,
                'balance_after' => $receiver->balance,
            ],
        ]);

        return [$transfer, false];
    }

    /**
     * Lock both rows in ascending id order. Two opposite transfers (A→B and B→A)
     * then queue on the same first lock instead of deadlocking.
     *
     * @return array{0: Wallet, 1: Wallet} Fresh, locked [sender, receiver].
     */
    private function lockWallets(Wallet $sender, Wallet $receiver): array
    {
        $ids = [$sender->id, $receiver->id];
        sort($ids);

        $locked = [];
        foreach ($ids as $id) {
            $locked[$id] = Wallet::query()->lockForUpdate()->findOrFail($id);
        }

        return [$locked[$sender->id], $locked[$receiver->id]];
    }

    private function findByIdempotencyKey(Wallet $sender, string $idempotencyKey): ?Transfer
    {
        return Transfer::query()
            ->where('sender_wallet_id', $sender->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * A replay must describe the same transfer; otherwise the client is misusing the key.
     */
    private function assertSameRequest(Transfer $existing, Wallet $receiver, int $amount): void
    {
        if ($existing->receiver_wallet_id !== $receiver->id || $existing->amount !== $amount) {
            throw TransferException::idempotencyKeyReused();
        }
    }
}
