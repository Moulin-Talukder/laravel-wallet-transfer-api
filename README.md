# laravel-wallet-transfer-api

A small Laravel API that transfers money between user wallets: safely, exactly once, with a double-entry ledger.

> **This is a standalone portfolio sample.** It was written from scratch to show how I approach money-moving code.
> It is not employer or client code and does not contain any proprietary logic.
> It was built with Claude Code as an AI pair programmer; I reviewed every change, and verified it with 31 feature tests (SQLite and MySQL), a MySQL concurrency stress test, and deliberate fault-injection experiments.

The scope is deliberately small (one service, three endpoints) so the important parts are easy to review:
integer money, row locking, idempotency, an append-only ledger, authorization and validation.

## Stack

- PHP 8.3+ / Laravel 13
- Laravel Sanctum (API tokens)
- SQLite by default for development and tests; MySQL for the [concurrency test](#concurrency-test)
- PHPUnit feature tests

## Getting started

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed   # creates alice@example.com (10,000 BDT) and bob@example.com (2,500 BDT)
php artisan serve
```

Create a token for Alice:

```bash
php artisan tinker --execute 'echo App\Models\User::firstWhere("email", "alice@example.com")->createToken("demo")->plainTextToken;'
```

Run the tests:

```bash
php artisan test
```

## API

All endpoints require `Authorization: Bearer <token>` and `Accept: application/json`.
**All amounts are integers in paisa** (1 BDT = 100 paisa).

| Method | Path             | Description                                               | Rate limit |
|--------|------------------|-----------------------------------------------------------|------------|
| GET    | `/api/wallet`    | Your own wallet balance                                   | 60/min     |
| GET    | `/api/transfers` | Transfers you sent or received, newest first, 20 per page | 60/min     |
| POST   | `/api/transfers` | Send money. Requires an `Idempotency-Key` header          | 10/min     |

Rate limits are per user, and reads and transfers have separate counters (see [Security notes](#security-notes)).

### Example: send 1,500 BDT to Bob

```bash
curl -i -X POST http://127.0.0.1:8000/api/transfers \
  -H "Authorization: Bearer $TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: 3f1c9a52-8d4e-4b7a-9c2f-6e1d0b5a7c43" \
  -d '{"receiver_email": "bob@example.com", "amount": 150000}'
```

```http
HTTP/1.1 201 Created

{
  "data": {
    "reference": "01a181c8-5409-70bf-b3f4-1d332cf62b1d",
    "amount": 150000,
    "currency": "BDT",
    "status": "completed",
    "direction": "out",
    "created_at": "2026-10-28T09:12:32.000000Z"
  }
}
```

Sending the **same request with the same key again** does not move money a second time. It returns the original transfer:

```http
HTTP/1.1 200 OK
Idempotent-Replayed: true
```

### Responses

| Status | When                                                                                     |
|--------|------------------------------------------------------------------------------------------|
| 201    | Transfer created                                                                         |
| 200    | Idempotent replay of an earlier transfer (`Idempotent-Replayed: true`)                   |
| 401    | Missing, invalid or expired token (tokens expire after 24 hours)                         |
| 403    | Your wallet is frozen                                                                    |
| 422    | Validation error (`errors` object) or business-rule rejection (`code`, see below)        |
| 429    | Rate limit exceeded: 10 transfers or 60 reads per minute                                 |

Business-rule rejections share one stable shape, so clients can branch on `code` rather than on the message:

```json
{ "message": "Insufficient balance.", "code": "INSUFFICIENT_BALANCE" }
```

| Code                     | Meaning                                                              |
|--------------------------|----------------------------------------------------------------------|
| `INSUFFICIENT_BALANCE`   | Sender balance is lower than the amount                              |
| `SAME_WALLET`            | Sender and receiver are the same wallet                              |
| `RECEIVER_UNAVAILABLE`   | Receiver is frozen, has no wallet, or uses another currency          |
| `SENDER_FROZEN`          | Sender was frozen between the authorization check and the row lock  |
| `IDEMPOTENCY_KEY_REUSED` | Key was already used for a transfer with a different receiver or amount |

## Design decisions

### Money is an integer

Balances and amounts are `unsignedBigInteger` columns holding **paisa**, and PHP only ever sees `int`.
Floats cannot represent `0.10` exactly, and rounding errors in a ledger are bugs.
The column is unsigned, so even a buggy code path cannot store a negative balance on MySQL.

### Row locking

All of the work happens inside a single `DB::transaction()` in
[`TransferService`](app/Services/TransferService.php). Both wallets are loaded with `SELECT … FOR UPDATE`,
and every rule (frozen flags, sufficient balance) is **re-checked on those freshly locked rows**,
not on the models the controller passed in. A check made before the lock could be stale
by the time the money moves: this is the classic double-spend race.

### Lock ordering

The two wallets are always locked in **ascending id order**, whoever is the sender.
Without this, a transfer A→B (locks A, waits for B) and a concurrent B→A (locks B, waits for A) deadlock.
With a fixed global order, both requests queue on the same first lock instead.

### Idempotency

Mobile networks drop responses. A client that retries a timed-out request must not pay twice.

- The client sends a UUID in the `Idempotency-Key` header. Keys are scoped per sender:
  there is a unique index on `(sender_wallet_id, idempotency_key)`.
- The key is looked up **after** the sender row is locked. Concurrent retries from the same sender are
  serialized on that lock, so the second one always sees the first one's committed transfer.
- A replay returns the original transfer with `200` and `Idempotent-Replayed: true`.
- Reusing a key for a **different** receiver or amount is a client bug, and is rejected with `IDEMPOTENCY_KEY_REUSED`
  instead of silently returning the old transfer.
- If a duplicate still reaches the database, the unique index rejects it. The service catches
  `UniqueConstraintViolationException` **outside** the transaction (after it has rolled back) and returns the existing transfer.
  This is belt and braces. The tests confirm that idempotency only breaks when *both* layers are removed.

### Double-entry ledger

Every transfer writes exactly two `ledger_entries` rows in the same transaction:
a **debit** on the sender and a **credit** on the receiver, each with `balance_after`.

- `wallets.balance` is a fast cache; the ledger is the audit trail it can be reconciled against.
- The ledger is append-only: the table has no `updated_at`, and the model throws on update or delete.
- A test checks the invariants: for every wallet, opening balance + credits − debits = current balance,
  and total debits = total credits.

### Authorization

- Every route is behind `auth:sanctum`. Tokens expire after 24 hours (`SANCTUM_EXPIRATION`, in minutes).
- [`WalletPolicy`](app/Policies/WalletPolicy.php) decides who may `view` a wallet and who may `transferFrom` it
  (the owner, and only while the wallet is not frozen). A frozen sender gets `403` before validation even runs.
- Controllers use `Gate::authorize()`, because the Laravel 11+ base controller no longer has an `authorize()` helper.
- `GET /api/transfers` is scoped by query to the user's own wallet, so other users' transfers can't leak.

### Validation and data exposure

- [`StoreTransferRequest`](app/Http/Requests/StoreTransferRequest.php) copies the `Idempotency-Key` header into the
  validated data. The header is the only source: an `idempotency_key` sent in the body is overwritten.
- `amount` must be an integer between 100 and 5,000,000 paisa (1 to 50,000 BDT). `"150.75"` and `100.5` are rejected, not rounded.
- `receiver_email` is trimmed and lowercased, then must exist and must not equal the sender's lowercased email,
  so `ALICE@example.com` cannot get around the self-transfer rule. User emails are stored lowercased,
  so lookups behave the same on case-sensitive SQLite and case-insensitive MySQL.
  The service still checks `SAME_WALLET` as a final guard.
- `balance` and `is_frozen` are not mass-assignable on `Wallet`.
- [`TransferResource`](app/Http/Resources/TransferResource.php) exposes only a public UUID `reference`.
  Auto-increment ids (which would reveal transfer volume) and idempotency keys never leave the server.

## Concurrency test

**SQLite ignores `FOR UPDATE`.** It locks the whole database for writes instead, so the PHPUnit suite proves the business rules
but **not** the row locking. [`wallet:stress-test`](app/Console/Commands/WalletStressTest.php) proves it on MySQL with real,
simultaneous HTTP requests, each sent by its own `curl` process to a multi-worker server.

### Running it

```bash
# 1. A MySQL database and user (once)
sudo mysql -e "CREATE DATABASE wallet_transfer_api; CREATE USER 'wallet'@'127.0.0.1' IDENTIFIED BY 'secret';
               GRANT ALL ON wallet_transfer_api.* TO 'wallet'@'127.0.0.1';"

# 2. A separate environment file, so your normal .env stays on SQLite
cp .env.stress.example .env.stress          # set DB_PASSWORD
php artisan key:generate --env=stress
php artisan migrate --env=stress

# 3. Terminal 1: a server with 24 PHP workers (from PHP_CLI_SERVER_WORKERS in .env.stress)
APP_ENV=stress php artisan serve --no-reload

# 4. Terminal 2: the test
php artisan wallet:stress-test --env=stress
```

`--no-reload` is required: without it, `artisan serve` ignores `PHP_CLI_SERVER_WORKERS` and runs a single worker,
which would process the requests one after another and prove nothing. `.env.stress` also raises the transfer
rate limit. Otherwise 10 of the 20 requests would get a `429`, and "exactly 10 succeeded" would be true for the wrong reason.

### What it checks

**Scenario 1, double spend:** a wallet holding 100 BDT receives 20 simultaneous requests to send 10 BDT.

- exactly 10 succeed, and the other 10 fail with `INSUFFICIENT_BALANCE`. Any `429` or `500` is a failure
- the final balance is 0 and no `balance_after` is negative
- the sender's debit rows read `balance_after` = 9000, 8000, …, 0 in order, so every debit saw the previous one and no update was lost
- the sum of ledger debits equals the money that left the wallet

**Scenario 2, deadlocks:** 20 × A→B and 20 × B→A at the same time.

- all 40 succeed, and the server logs no MySQL `1213 Deadlock found` errors
- for both wallets, opening balance + credits − debits = balance

The command also prints MySQL's `Innodb_row_lock_waits` counter. A non-zero value means transactions really did
collide and wait on the row locks, so the requests were not just being handled one at a time.

### Results

MySQL 8.4, 24 PHP workers, WSL2:

```text
Scenario 1: 20 × 10 BDT from one wallet holding 100 BDT
 Processes launched ................................................ 20
 Average requests in flight ...................................... 17.2
 Row lock waits (Innodb_row_lock_waits) ........................... +36
 Responses ......................... 201 ×10, 422 INSUFFICIENT_BALANCE ×10
 Exactly 10 transfers succeeded ............................ PASS 10 × 201
 Every other request was rejected with INSUFFICIENT_BALANCE  PASS 10 × 422
 Sender balance is 0 ........................................ PASS 0 paisa
 Balance never went negative (min balance_after) ............ PASS 0 paisa
 Each debit saw the previous one  PASS 9000, 8000, 7000, 6000, 5000, 4000, 3000, 2000, 1000, 0
 Sum of ledger debits equals money that left the wallet  PASS 10000 = 10000 paisa
 Receiver balance equals its ledger credits ....... PASS 10000 = 10000 paisa

Scenario 2: 20 × A->B and 20 × B->A in parallel
 Processes launched ................................................ 40
 Average requests in flight ...................................... 19.0
 Row lock waits (Innodb_row_lock_waits) ........................... +42
 Responses ............................................... 201 ×40
 Every transfer succeeded (no deadlock 500s) ......... PASS 40 / 40 × 201
 Server logged no deadlocks ........................... PASS 0 × MySQL 1213
 Wallet A: opening + credits − debits = balance  PASS 20000 + 20000 − 20000 = 20000
 Wallet B: opening + credits − debits = balance  PASS 20000 + 20000 − 20000 = 20000

 SUCCESS  All concurrency checks passed.
```

### Proof that the checks can fail

A test that never fails proves nothing, so I temporarily broke `TransferService` twice and re-ran the command.
These changes were not committed.

| Change                                     | Result                                                                                                                                                                        |
|--------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Removed `lockForUpdate()`                  | Scenario 1: only 5 × 201, 15 × 500 (deadlocks), and `balance_after` read **9000, 9000, 7000, 7000, 7000**: stale reads produced ledger rows for balances that never existed |
| Locked sender first (removed the id sort)  | Scenario 2: 20 × 201, **20 × 500**, and 20 MySQL `1213` deadlocks were logged                                                                                                    |

Without `FOR UPDATE`, the `INSERT INTO transfers` takes **shared** locks on both wallet rows through the foreign keys,
and the following `UPDATE wallets` needs **exclusive** locks on them. Two transactions doing this at once deadlock each other.
In both experiments the ledger still balanced, because every failed transaction rolled back completely. But users got errors, and in
the first case the audit trail recorded balances that never existed.

## Security notes

These were found in a review of the first version and have since been fixed, each with a test:

- **Tokens never expired.** Sanctum's default `expiration` is `null`. Tokens now expire after 24 hours. The test uses a real
  bearer token, because `Sanctum::actingAs()` skips the expiry check.
- **The self-transfer check was case-sensitive.** On MySQL, `ALICE@example.com` got past the rule and was only stopped
  by the service's `SAME_WALLET` check. Emails are now normalized before validation and stored lowercased.
- **Read endpoints had no rate limit.** They now allow 60 requests per minute.
- **Rate limiters shared one counter.** Plain `throttle:X,Y` middleware keys every limiter on the user id alone, so adding
  `throttle:60,1` to the reads would have let 10 balance checks block a transfer. The app now uses named limiters
  (`transfers`, `wallet-reads`) with separate counters, configured in [`config/wallet.php`](config/wallet.php).

Known risks that are still open:

- **Email enumeration.** The `exists` rule on `receiver_email` reveals whether an address is registered.
  The 10/min limit slows this down. A production app would use a separate "resolve recipient" step with its own tight limit, or a generic error.
- **The ledger is append-only only in application code.** Raw queries can still change it (see below).
- **`APP_DEBUG=true` in `.env.example`** is the Laravel default for local development. It must be `false` in production,
  or error pages will show stack traces.

## What I would add in production

- **Queued notifications dispatched `afterCommit`.** "You received 1,500 BDT" must never be sent for a transfer that was rolled back.
- **Daily and monthly limits per wallet**, checked inside the same lock as the balance.
- **OTP / step-up confirmation** for transfers above a threshold.
- **A scheduled ledger reconciliation job** that checks every wallet's balance against the sum of its ledger rows and alerts on drift.
- **Signed gateway webhooks** for top-ups and withdrawals: verify the HMAC signature, reject stale timestamps, and process each event idempotently.
- **A login and token refresh flow**, instead of creating tokens by hand.
- Database-level protection for the ledger (revoke `UPDATE`/`DELETE` from the app user), audit logging, and `wallet:stress-test` running against MySQL in CI.
