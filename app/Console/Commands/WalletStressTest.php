<?php

namespace App\Console\Commands;

use App\Enums\LedgerEntryType;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Process\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Fires real concurrent HTTP requests, each from its own process, at a running
 * multi-worker server, then checks the database. Requires MySQL: SQLite ignores
 * FOR UPDATE, so a green run there would prove nothing.
 */
#[Signature('wallet:stress-test
    {--url=http://127.0.0.1:8000 : Base URL of the running server}
    {--requests=20 : Concurrent 10 BDT transfers fired at a 100 BDT wallet}
    {--pairs=20 : Concurrent transfers in each direction for the deadlock scenario}')]
#[Description('Prove row locking and lock ordering hold under real concurrency (MySQL only)')]
class WalletStressTest extends Command
{
    /** 10 BDT in paisa. */
    private const AMOUNT = 1_000;

    /** @var list<string> */
    private array $failures = [];

    /** @var array<int, string> Plain-text tokens keyed by wallet id. */
    private array $tokens = [];

    public function handle(): int
    {
        if ($this->laravel->isProduction()) {
            $this->components->error('Refusing to run in production: this command creates users and moves money.');

            return self::FAILURE;
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->components->error('This command needs MySQL. SQLite ignores FOR UPDATE, so the result would be meaningless.');

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'MySQL %s, database "%s", server %s',
            DB::scalar('select version()'),
            DB::connection()->getDatabaseName(),
            $this->option('url'),
        ));

        $this->doubleSpendScenario();
        $this->oppositeTransfersScenario();

        $this->newLine();
        if ($this->failures !== []) {
            $this->components->error(count($this->failures).' check(s) failed.');

            return self::FAILURE;
        }

        $this->components->success('All concurrency checks passed.');

        return self::SUCCESS;
    }

    /**
     * Many simultaneous debits from one wallet that can only afford some of them.
     */
    private function doubleSpendScenario(): void
    {
        $requests = (int) $this->option('requests');
        $opening = 10_000; // 100 BDT
        $affordable = intdiv($opening, self::AMOUNT);

        $sender = $this->makeWallet($opening);
        $receiver = $this->makeWallet(0);

        $this->heading("Scenario 1: {$requests} × 10 BDT from one wallet holding 100 BDT");
        $results = $this->fire(array_fill(0, $requests, [$sender, $receiver]));

        $sender->refresh();
        $receiver->refresh();
        $debits = LedgerEntry::query()
            ->where('wallet_id', $sender->id)
            ->where('type', LedgerEntryType::Debit)
            ->orderBy('id')
            ->get();
        $credits = (int) LedgerEntry::query()
            ->where('wallet_id', $receiver->id)
            ->where('type', LedgerEntryType::Credit)
            ->sum('amount');

        $succeeded = $results->where('status', 201)->count();
        $insufficient = $results->where('status', 422)->where('code', 'INSUFFICIENT_BALANCE')->count();
        $expectedBalances = array_map(fn (int $n) => $opening - $n * self::AMOUNT, range(1, $affordable));
        $moneyLeft = $opening - $sender->balance;

        $this->check("Exactly {$affordable} transfers succeeded", $succeeded === $affordable, "{$succeeded} × 201");
        $this->check(
            'Every other request was rejected with INSUFFICIENT_BALANCE',
            $insufficient === $requests - $affordable,
            "{$insufficient} × 422",
        );
        $this->check('Sender balance is 0', $sender->balance === 0, "{$sender->balance} paisa");
        $this->check(
            'Balance never went negative (min balance_after)',
            $debits->isNotEmpty() && $debits->min('balance_after') >= 0,
            $debits->min('balance_after').' paisa',
        );
        $this->check(
            'Each debit saw the previous one (balance_after strictly 9000 -> 0)',
            $debits->pluck('balance_after')->all() === $expectedBalances,
            $debits->pluck('balance_after')->implode(', '),
        );
        $this->check(
            'Sum of ledger debits equals money that left the wallet',
            (int) $debits->sum('amount') === $moneyLeft,
            $debits->sum('amount')." = {$moneyLeft} paisa",
        );
        $this->check('Receiver balance equals its ledger credits', $receiver->balance === $credits, "{$receiver->balance} = {$credits} paisa");
    }

    /**
     * A->B and B->A at the same time. Without a global lock order this deadlocks.
     */
    private function oppositeTransfersScenario(): void
    {
        $pairs = (int) $this->option('pairs');
        $opening = $pairs * self::AMOUNT; // Each side can afford all of its own transfers in any order.

        $a = $this->makeWallet($opening);
        $b = $this->makeWallet($opening);

        $this->heading("Scenario 2: {$pairs} × A->B and {$pairs} × B->A in parallel");

        // A deadlock aborts one transaction with MySQL error 1213, which the server logs and answers with a 500.
        $log = storage_path('logs/laravel.log');
        $logOffset = is_file($log) ? filesize($log) : 0;

        $jobs = [];
        for ($i = 0; $i < $pairs; $i++) {
            $jobs[] = [$a, $b];
            $jobs[] = [$b, $a];
        }
        $results = $this->fire($jobs);

        clearstatcache();
        $newLogLines = is_file($log) ? (string) file_get_contents($log, offset: $logOffset) : '';
        // Count log entries, not raw matches: each entry repeats the message in its stack trace.
        $deadlocks = preg_match_all('/^\[[^\]]+\] \w+\.ERROR: .*Deadlock found when trying to get lock/m', $newLogLines);

        $succeeded = $results->where('status', 201)->count();
        $this->check('Every transfer succeeded (no deadlock 500s)', $succeeded === 2 * $pairs, "{$succeeded} / ".(2 * $pairs).' × 201');
        $this->check('Server logged no deadlocks', $deadlocks === 0, "{$deadlocks} × MySQL 1213");

        foreach (['A' => $a, 'B' => $b] as $name => $wallet) {
            $wallet->refresh();
            $credits = (int) $wallet->ledgerEntries()->where('type', LedgerEntryType::Credit)->sum('amount');
            $debits = (int) $wallet->ledgerEntries()->where('type', LedgerEntryType::Debit)->sum('amount');

            $this->check(
                "Wallet {$name}: opening + credits − debits = balance",
                $opening + $credits - $debits === $wallet->balance,
                "{$opening} + {$credits} − {$debits} = {$wallet->balance}",
            );
        }
    }

    /**
     * Launch one curl process per job, all at once, and wait for every response.
     *
     * @param  list<array{0: Wallet, 1: Wallet}>  $jobs  [sender, receiver] pairs.
     * @return Collection<int, array{status: int, code: ?string, seconds: float}>
     */
    private function fire(array $jobs): Collection
    {
        $url = rtrim($this->option('url'), '/').'/api/transfers';
        $commands = array_map(fn (array $job) => [
            'curl', '-sS', '-X', 'POST', $url,
            '-H', 'Accept: application/json',
            '-H', 'Content-Type: application/json',
            '-H', 'Authorization: Bearer '.$this->tokenFor($job[0]),
            '-H', 'Idempotency-Key: '.Str::uuid(),
            '-d', json_encode(['receiver_email' => $job[1]->user->email, 'amount' => self::AMOUNT]),
            '-w', "\n%{http_code} %{time_total}",
        ], $jobs);

        $lockWaitsBefore = $this->innodbStatus('Innodb_row_lock_waits');
        $started = microtime(true);

        $responses = Process::pool(function (Pool $pool) use ($commands) {
            foreach ($commands as $command) {
                $pool->timeout(60)->command($command);
            }
        })->start()->wait();

        $wallSeconds = microtime(true) - $started;
        $lockWaits = $this->innodbStatus('Innodb_row_lock_waits') - $lockWaitsBefore;

        $results = $responses->collect()->map(function ($process) {
            $lines = explode("\n", rtrim($process->output()));
            [$status, $seconds] = explode(' ', array_pop($lines)) + [1 => 0];
            $body = json_decode(implode("\n", $lines), true);

            return ['status' => (int) $status, 'code' => $body['code'] ?? null, 'seconds' => (float) $seconds];
        })->values();

        $this->components->twoColumnDetail('Processes launched', (string) count($commands));
        $this->components->twoColumnDetail('Wall time', sprintf('%.0f ms', $wallSeconds * 1000));
        $this->components->twoColumnDetail('Average requests in flight', sprintf('%.1f', $results->sum('seconds') / $wallSeconds));
        $this->components->twoColumnDetail('Row lock waits (Innodb_row_lock_waits)', "+{$lockWaits}");
        $this->components->twoColumnDetail('Responses', $results
            ->countBy(fn (array $r) => trim($r['status'].' '.$r['code']))
            ->map(fn (int $count, string $outcome) => "{$outcome} ×{$count}")
            ->implode(', '));

        return $results;
    }

    private function makeWallet(int $balance): Wallet
    {
        return Wallet::factory()
            ->withBalance($balance)
            ->for(User::factory()->state(['email' => 'stress-'.Str::lower(Str::random(12)).'@example.test']))
            ->create();
    }

    private function tokenFor(Wallet $wallet): string
    {
        return $this->tokens[$wallet->id] ??= $wallet->user->createToken('stress-test')->plainTextToken;
    }

    private function innodbStatus(string $name): int
    {
        return (int) DB::scalar(
            'select VARIABLE_VALUE from performance_schema.global_status where VARIABLE_NAME = ?',
            [$name],
        );
    }

    private function heading(string $title): void
    {
        $this->newLine();
        $this->line("<options=bold>{$title}</>");
    }

    private function check(string $label, bool $passed, string $detail): void
    {
        $this->components->twoColumnDetail(
            $label,
            ($passed ? '<fg=green;options=bold>PASS</>' : '<fg=red;options=bold>FAIL</>')." <fg=gray>{$detail}</>",
        );

        if (! $passed) {
            $this->failures[] = $label;
        }
    }
}
