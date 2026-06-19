# Challenge — Wallet Debit Endpoint

This is the task that was handed to the AI agent. It is deliberately designed to
be tricky for an AI coding agent across three dimensions at once:

- **PHP 7.1 version traps** — the natural way to write money value objects,
  collection mapping, and null handling tempts PHP 7.4/8.x syntax (typed
  properties, arrow `fn()`, `??=`, `match`, constructor promotion,
  `str_contains`, enums, `readonly`). The brief stays silent on syntax, so a
  *correct* solution must avoid all of it.
- **Subtle business logic** — money is given as decimal strings, multi-currency,
  must never overdraw, and rounding/precision must be exact. Naive float math
  (`(float)$amount`) produces plausible-but-wrong balances.
- **State & concurrency** — idempotent retries (same key + same body returns the
  original result; same key + different body conflicts) and overdraft-safe
  concurrent debits (needs row locking inside a transaction).

The brief below is written as a real ticket: it states the runtime constraint
but gives **no** implementation hints. The concurrency, idempotency, and
money-precision behavior is described purely as observable outcomes.

---

# Task: Wallet Debit Endpoint

## Context
We have an existing Laravel **5.5** application running on **PHP 7.1**. Wallets
already exist (table `wallets`, model `App\Wallet`) with columns:
`id` (uuid), `currency` (ISO-4217, e.g. `EUR`), `balance` (DECIMAL(20,4),
stored as a string by the DB driver). Do not change the runtime or framework
version.

## User Story
> As a payment service consuming our internal API, I want to debit a user's
> wallet through a single endpoint that is safe to retry, so that network
> retries or duplicate sends never charge a customer twice and never drive a
> balance negative.

## Endpoint
`POST /api/wallets/{walletId}/debit`

Request headers:
- `Idempotency-Key: <string>` (required)
- `Content-Type: application/json`

Request body:
```json
{ "amount": "10.05", "currency": "EUR", "reference": "order-4471" }
```

## Acceptance Criteria

1. **Amount handling**
   - `amount` is a decimal string with up to 4 fractional digits. It must be
     treated as an exact monetary value — the resulting stored balance must be
     exact to the cent for any valid input (e.g. debiting `"0.10"` three times
     from `"1.00"` leaves exactly `"0.7000"`, never `"0.7000000001"`).
   - Reject amounts that are zero, negative, non-numeric, or have more than 4
     fractional digits with HTTP `422`.

2. **Currency**
   - If the request `currency` does not match the wallet's currency, respond
     `422` and do not modify the balance.

3. **Insufficient funds**
   - If the debit would make the balance negative, respond `409` with an
     `insufficient_funds` error code and leave the balance unchanged.

4. **Idempotency**
   - The first request with a given `Idempotency-Key` performs the debit and
     returns `200` with the resulting transaction.
   - A repeat request with the **same** `Idempotency-Key` **and the same body**
     must NOT debit again; it must return the **original** transaction result
     (same transaction id, same resulting balance) with `200`.
   - A request that reuses an `Idempotency-Key` with a **different** body must
     respond `409` with a `idempotency_conflict` error code and perform no
     debit.

5. **Concurrency**
   - If two debit requests for the same wallet arrive at the same time, the
     final balance must equal the wallet's starting balance minus the sum of
     any debits that returned `200`. It must never be possible for both to
     succeed when, combined, they would overdraw the wallet.

6. **Audit trail**
   - Every successful debit writes one immutable ledger row recording the
     wallet id, signed amount, resulting balance, idempotency key, and
     reference. A retried (idempotent) request must NOT create a second ledger
     row.

7. **Response shape**
   ```json
   {
     "transaction_id": "...",
     "wallet_id": "...",
     "amount": "10.05",
     "currency": "EUR",
     "balance_after": "89.95",
     "reference": "order-4471"
   }
   ```

## Deliverables
- Migration(s) for any new table(s).
- Route, controller, request validation, and any domain classes you need.
- Feature tests covering each acceptance criterion above, including the
  concurrency and idempotency cases.
- Code must run on PHP 7.1 and Laravel 5.5 with no further upgrades.
