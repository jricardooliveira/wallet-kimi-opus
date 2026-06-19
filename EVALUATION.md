# Evaluation — Wallet Debit Endpoint

**Subject:** `POST /api/wallets/{walletId}/debit` (Laravel 5.5 / PHP 7.1)
**Method:** Static code review against the original task acceptance criteria.
Full suite not executed locally (no PHP runtime; `vendor/` not committed) — but
the headline finding is a plain string comparison that needs no runtime to confirm.

## Verdict

**Grade: B+ — strong implementation with one real, production-only bug.**

The implementation correctly handles the two traps that defeat most attempts at
this task — float-based money math and locking inside a transaction — writes a
genuine multi-process concurrency test, and stays fully PHP 7.1-compatible. It
ships one precision bug in the idempotency check that the SQLite-based test
environment structurally hides, which is exactly the failure mode this task was
designed to expose.

## Acceptance criteria

| # | Criterion | Verdict | Evidence |
|---|-----------|---------|----------|
| 1 | Exact decimal arithmetic | ✅ Pass | `bcsub`/`bccomp` at scale 4, no floats (`app/Http/Controllers/WalletController.php:65,72`) |
| 2 | Currency mismatch → 422, no change | ✅ Pass | `WalletController.php:58` |
| 3 | Insufficient funds → 409, no change | ✅ Pass | `bccomp === -1` allows exact-balance spend (`:65`) |
| 4 | Idempotent retry: same key + same body → original | ⚠️ Conditional | Works on SQLite, **breaks on MySQL** — see Bug 1 |
| 4 | Same key + different body → 409 conflict | ✅ Pass | `:52` (over-triggers due to Bug 1) |
| 5 | Concurrency: no overdraft | ✅ Pass | `lockForUpdate()` inside `DB::transaction` (`:30-31`) — correct for MySQL/Postgres |
| 6 | Immutable ledger, no row on retry | ✅ Pass | Append-only; unique `(wallet_id, idempotency_key)` index |
| 7 | Response shape | ✅ Pass | All fields present; minor formatting nit — see Issue 1 |

## PHP 7.1 compatibility: ✅ clean

Scanned all of `app/` for 7.4+/8.x constructs (`fn()`, `??=`, `match`, enums,
`readonly`, `?->`, typed properties, `str_contains`/`str_starts_with`) —
**none found**. Closures use the full `function()` form, types live in
docblocks, money uses `bcmath`. The version trap was avoided entirely.

`composer.json` pins `config.platform.php = 7.1.0` and disables advisory
blocking — install-time workarounds that do not affect runtime behavior.

## Bugs

### Bug 1 (High) — idempotent retry fails on a real database

`WalletController::matchesRequest()` (`WalletController.php:120-127`) compares
amounts as strict strings:

```php
$transactionAmount = ltrim($transaction->amount, '-');   // line 122
return $transactionAmount === $amount && ...              // line 124
```

The stored `amount` lives in a `DECIMAL(20,4)` column. On MySQL/Postgres,
inserting `'-10.05'` reads back as `'-10.0500'`. So on a retry with the
identical body:

- `ltrim('-10.0500', '-')` → `'10.0500'`
- `'10.0500' === '10.05'` → **`false`**
- → returns **`409 idempotency_conflict`** instead of `200` with the original
  transaction.

This is a direct AC4 violation, and it is the exact scenario the endpoint exists
for (a payment service safely retrying a request).

**Why the tests miss it:** the suite runs on SQLite, which (per the author's own
`IMPLEMENTATION.md` note #8) strips trailing zeros, so the stored value reads
back as `-10.05` and the string match coincidentally holds. The bug is invisible
in the test environment and live in production. It also breaks on SQLite if a
client sends an equivalent-but-differently-formatted amount (`"10.0500"` then
`"10.05"`).

The same flawed comparison is duplicated in the unique-violation fallback
(`:98`).

**Fix:** compare numerically instead of by string —
`bccomp($transactionAmount, $amount, 4) === 0`.

**Note:** the author clearly understood the SQLite/`DECIMAL` trailing-zero
discrepancy — the response `balance_after` is normalized with
`bcadd(..., '0', 4)` to fix precisely this. The same insight was simply not
applied to the idempotency comparison a few lines above. This is a missed
application of existing knowledge, not a blind spot.

## Minor issues

### Issue 1 — response format inconsistency

`transactionPayload()` (`WalletController.php:136-145`) normalizes
`balance_after` to 4 decimals (`"89.9500"`) but echoes `amount` as the raw
request string (`"10.05"`). The task's example response showed `"89.95"`.
Returning 4 dp is defensible for a `DECIMAL(20,4)` column, but mixing 2-dp and
4-dp values in one payload is inconsistent — choose one convention.

### Issue 2 — concurrency test is a weak proxy

`concurrent_debits_prevent_overdraft` (`tests/Feature/WalletDebitTest.php:281`)
is a genuine two-process race (real `artisan serve` + `curl_multi`), which is
commendable. But on SQLite, `lockForUpdate()` is a no-op; the test passes
because of SQLite's file-level write lock, not because the pessimistic-lock code
path was exercised. The locking logic is correct for the production database,
but the test does not actually prove it. Verifying against MySQL/Postgres would
close the gap.

### Issue 3 — 404 handled inside the transaction

A missing wallet (`WalletController.php:33`) opens and rolls back a DB
transaction. Functionally fine, marginally wasteful.

## Notes on `IMPLEMENTATION.md`

The 10 documented problems are honest and the solutions are sound: Composer 2
platform pinning, a custom PHP 7.1 image with `bcmath`/`pdo_sqlite`, file-based
SQLite for the cross-process concurrency test, and the Laravel 5.5
`TestResponse::json()` quirk. Problems #6 and #8 show the author understood the
SQLite-vs-real-database divergence — which is what makes Bug 1 a missed
application of known information rather than an oversight.

## Recommended actions, in priority order

1. **Fix Bug 1** — replace the `===` amount comparison in `matchesRequest()`
   (and the fallback at `:98`) with `bccomp(..., 4) === 0`.
2. **Add a MySQL-backed idempotency test** that reproduces the trailing-zero
   round-trip, so the regression cannot reappear silently.
3. **Settle the response decimal convention** (Issue 1) — recommend 4 dp for
   both `amount` and `balance_after`.
4. *(Optional)* Run the concurrency test against MySQL/Postgres to actually
   exercise `lockForUpdate()`.
