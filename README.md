# laravel-wallet-transfer-api

A small Laravel API that transfers money between user wallets: safely, exactly once, with a double-entry ledger.

> **This is a standalone portfolio sample.** It was written from scratch to show how I approach money-moving code.
> It is not employer or client code and does not contain any proprietary logic.

The scope is deliberately small (one service, three endpoints) so the important parts are easy to review:
integer money, row locking, idempotency, an append-only ledger, authorization and validation.

## Stack

- PHP 8.3+ / Laravel 13
- Laravel Sanctum (API tokens)
- SQLite by default (see the [concurrency note](#a-note-on-sqlite-and-concurrency))
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

| Method | Path             | Description                                                         |
|--------|------------------|---------------------------------------------------------------------|
| GET    | `/api/wallet`    | Your own wallet balance                                             |
| GET    | `/api/transfers` | Transfers you sent or received, newest first, 20 per page           |
| POST   | `/api/transfers` | Send money. Requires an `Idempotency-Key` header. Limited to 10/min |

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
| 401    | Missing or invalid token                                                                 |
| 403    | Your wallet is frozen                                                                    |
| 422    | Validation error (`errors` object) or business-rule rejection (`code`, see below)        |
| 429    | More than 10 transfer requests per minute                                                |

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

- Every route is behind `auth:sanctum`.
- [`WalletPolicy`](app/Policies/WalletPolicy.php) decides who may `view` a wallet and who may `transferFrom` it
  (the owner, and only while the wallet is not frozen). A frozen sender gets `403` before validation even runs.
- Controllers use `Gate::authorize()`, because the Laravel 11+ base controller no longer has an `authorize()` helper.
- `GET /api/transfers` is scoped by query to the user's own wallet, so other users' transfers can't leak.

### Validation and data exposure

- [`StoreTransferRequest`](app/Http/Requests/StoreTransferRequest.php) copies the `Idempotency-Key` header into the
  validated data. The header is the only source: an `idempotency_key` sent in the body is overwritten.
- `amount` must be an integer between 100 and 5,000,000 paisa (1 to 50,000 BDT). `"150.75"` and `100.5` are rejected, not rounded.
- `receiver_email` must exist and must not be the sender's own email. The service checks `SAME_WALLET` again in case the email check is bypassed (for example, case differences on MySQL).
- `balance` and `is_frozen` are not mass-assignable on `Wallet`.
- [`TransferResource`](app/Http/Resources/TransferResource.php) exposes only a public UUID `reference`.
  Auto-increment ids (which would reveal transfer volume) and idempotency keys never leave the server.

## A note on SQLite and concurrency

**SQLite ignores `FOR UPDATE`.** It locks the whole database for writes instead, so the tests here prove the business rules
but **not** the row-locking behaviour. To test real concurrency (parallel A→B / B→A transfers, simultaneous retries
with the same key), run the app on MySQL or PostgreSQL and fire concurrent requests, for example from parallel
processes in a test or with a load tool such as `k6`.

## What I would add in production

- **Queued notifications dispatched `afterCommit`.** "You received 1,500 BDT" must never be sent for a transfer that was rolled back.
- **Daily and monthly limits per wallet**, checked inside the same lock as the balance.
- **OTP / step-up confirmation** for transfers above a threshold.
- **A scheduled ledger reconciliation job** that checks every wallet's balance against the sum of its ledger rows and alerts on drift.
- **Signed gateway webhooks** for top-ups and withdrawals: verify the HMAC signature, reject stale timestamps, and process each event idempotently.
- **Token expiry** (`sanctum.expiration` is `null` by default) and a real login/refresh flow.
- **Less email enumeration.** The `exists` rule on `receiver_email` reveals whether an address is registered.
  The 10/min limit slows this down. A production app would use a separate "resolve recipient" step with its own tight limit, or a generic error.
- Database-level protection for the ledger (revoke `UPDATE`/`DELETE` from the app user), audit logging, and tests that run concurrent transfers on MySQL in CI.
