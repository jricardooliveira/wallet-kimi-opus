# Wallet Debit Endpoint — Implementation Notes

## Overview

This document describes the implementation of the `POST /api/wallets/{walletId}/debit` endpoint, the design decisions taken, the problems encountered, and how they were resolved.

The endpoint is part of a Laravel 5.5 application running on PHP 7.1. It provides:

- Exact decimal arithmetic for monetary amounts.
- Currency validation against the wallet.
- Concurrency-safe debits via database row locking.
- Idempotent retries with conflict detection.
- An immutable audit trail in the form of `wallet_transactions` rows.

---

## Files added or modified

### Migrations

| File | Purpose |
|------|---------|
| `database/migrations/2026_06_19_170000_create_wallets_table.php` | Creates the `wallets` table with UUID primary key, currency, and decimal balance. |
| `database/migrations/2026_06_19_170001_create_wallet_transactions_table.php` | Creates the `wallet_transactions` ledger table with UUID primary key, wallet reference, signed amount, resulting balance, idempotency key, reference, and timestamps. |

### Domain layer

| File | Purpose |
|------|---------|
| `app/Wallet.php` | Eloquent model for the wallet. Uses UUID primary key and exposes the wallet’s transactions. |
| `app/WalletTransaction.php` | Eloquent model for the immutable ledger row. Uses UUID primary key and casts monetary fields to strings. |

### HTTP layer

| File | Purpose |
|------|---------|
| `app/Http/Requests/DebitRequest.php` | Form request that validates the `Idempotency-Key` header and the request body (`amount`, `currency`, `reference`). |
| `app/Http/Controllers/WalletController.php` | Handles the debit logic: locking, idempotency checks, currency/funds validation, balance update, and ledger creation. |
| `routes/api.php` | Registers the `POST /api/wallets/{walletId}/debit` route. |

### Tests

| File | Purpose |
|------|---------|
| `tests/Feature/WalletDebitTest.php` | Feature tests covering every acceptance criterion, including a real concurrent-request test. |
| `phpunit.xml` | Added SQLite in-memory configuration for the testing environment. |

### Project tooling

| File | Purpose |
|------|---------|
| `composer.json` | Added `config.platform.php = 7.1.0` so Composer resolves PHP 7.1-compatible dependencies, and disabled Packagist advisory blocking so legacy Laravel 5.5 packages can still be installed with Composer 2. |

---

## Design decisions

### Decimal arithmetic

PHP floats cannot represent decimal values such as `0.10` exactly. To guarantee that debiting `0.10` three times from `1.0000` leaves exactly `0.7000`, all arithmetic is performed with the `bcmath` extension:

- `bccomp($wallet->balance, $amount, 4)` to check sufficiency.
- `bcsub($wallet->balance, $amount, 4)` to compute the new balance.

The scale of `4` matches the `DECIMAL(20,4)` columns in the database.

### Concurrency safety

The controller wraps every debit in a database transaction and acquires a pessimistic lock on the wallet row using `Wallet::lockForUpdate()->find($walletId)`. This guarantees that two simultaneous requests for the same wallet are serialized. The second request sees the balance already reduced by the first request and therefore either succeeds (if enough funds remain) or returns `409 insufficient_funds`.

### Idempotency

Idempotency is scoped per wallet because the endpoint URL itself identifies the wallet. The ledger table has a unique composite index on `(wallet_id, idempotency_key)`.

When a request arrives, the controller first looks for an existing `WalletTransaction` with the same wallet and idempotency key:

- If the stored transaction matches the current request body (`amount`, `currency`, `reference`), the stored result is returned with HTTP `200` and **no new ledger row** is created.
- If the idempotency key is reused with a different body, HTTP `409 idempotency_conflict` is returned and no debit occurs.

A fallback unique-violation handler catches the very small race window where two identical first requests could both try to insert the same key.

### Response formatting

The ledger stores `balance_after` as returned by the database driver. SQLite returns strings such as `89.95` instead of the canonical `89.9500`. To keep responses deterministic and consistent with the `DECIMAL(20,4)` storage, the controller normalizes the response value with `bcadd($transaction->balance_after, '0', 4)`.

---

## Problems encountered and solutions

### 1. The working directory was empty

**Problem:** The repository root contained no Laravel application, only an empty directory.

**Solution:** Created a fresh Laravel 5.5 project using Composer. Because the host machine had no local PHP installed, all Composer and artisan commands were run inside Docker containers.

### 2. Composer 1 support on Packagist has been shut down

**Problem:** The first attempt to install Laravel 5.5 with the `composer:1.10` image failed with:

```
Could not find package laravel/laravel with version 5.5.*
```

Packagist ended Composer 1 support in September 2025.

**Solution:** Used the `composer:2` image to resolve and install packages, while pinning the runtime platform to PHP 7.1 in `composer.json`:

```json
"config": {
    "platform": { "php": "7.1.0" }
}
```

This ensures the installed dependencies are compatible with PHP 7.1 even though Composer 2 itself runs on a newer PHP version.

### 3. Composer 2 blocks legacy packages due to security advisories

**Problem:** Composer 2 refused to install Laravel 5.5, PHPUnit 6, and several transitive dependencies because they are flagged in Packagist security advisories.

**Solution:** Disabled advisory blocking in `composer.json` for this legacy project:

```json
"policy": {
    "advisories": { "block": false }
}
```

This is an installation-time workaround only; it does not affect runtime behavior.

### 4. PHP 7.1 Docker image lacked required extensions

**Problem:** The stock `php:7.1-alpine` image did not include `bcmath` or `pdo_sqlite`. Running the tests produced:

```
Call to undefined function App\Http\Requests\bccomp()
```

**Solution:** Built a custom Docker image (`wallet-php:7.1`) based on `php:7.1-alpine` with `bcmath` and `pdo_sqlite` installed via `docker-php-ext-install`. The temporary Dockerfile used to build the image was removed after the build.

### 5. Post-installation scripts failed under Composer 2’s PHP 8 runtime

**Problem:** After installing dependencies, the `post-autoload-dump` script (`php artisan package:discover`) crashed because the `composer:2` image runs PHP 8.x, which is incompatible with Laravel 5.5 bootstrap code.

**Solution:** Installed dependencies with `--no-scripts` to skip the PHP 8 execution of artisan, then ran the application and tests under the PHP 7.1 container.

### 6. SQLite `:memory:` databases are not shared across processes

**Problem:** The concurrency test needs to start a real HTTP server (`php artisan serve`) in a separate process. With an in-memory SQLite database, the server process could not see the wallet created by the test process.

**Solution:** In the concurrency test, switched to a temporary file-based SQLite database:

1. Create a temp file.
2. Reconfigure Laravel’s SQLite connection to use that file.
3. Purge and reconnect the database manager so the new config takes effect.
4. Run migrations and seed the wallet.
5. Start `php artisan serve` pointed at the same file via environment variables.
6. Clean up the temp file afterwards.

### 7. Laravel 5.5 `TestResponse::json()` does not accept a key argument

**Problem:** Tests initially used `$response->json('transaction_id')`, which in newer Laravel returns a nested value. In Laravel 5.5, `json()` ignores any argument and always returns the full decoded array, so assertions produced confusing mismatch errors.

**Solution:** Changed all test accesses to array syntax: `$response->json()['transaction_id']`.

### 8. SQLite strips trailing zeros from decimal strings

**Problem:** SQLite’s NUMERIC affinity stores `'89.9500'` as `89.95`, so the API response initially showed `89.95` instead of the canonical `89.9500`.

**Solution:** Normalized the response value in the controller with `bcadd($transaction->balance_after, '0', 4)`, ensuring the API always returns four decimal places regardless of the database driver.

### 9. Default example tests failed without an application key

**Problem:** Laravel’s bundled `Tests\Feature\ExampleTest` failed with:

```
RuntimeException: No application encryption key has been specified.
```

**Solution:** Generated an application key with `php artisan key:generate` and removed the placeholder example tests (`tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php`) since they are not relevant to this feature.

### 10. Global vs per-wallet idempotency keys

**Problem:** The original migration placed a unique index only on `idempotency_key`. If the same key were ever used for two different wallets, the global unique constraint could collide while the lookup logic scoped by `wallet_id` would not find the existing row.

**Solution:** Changed the unique constraint to the composite `(wallet_id, idempotency_key)`, matching the fact that the endpoint URL itself identifies the wallet and idempotency should therefore be scoped per wallet.

---

## How to run the tests

Because the host OS does not have PHP 7.1 installed locally, a custom Docker image was built for verification:

```bash
# Build the PHP 7.1 image with bcmath and pdo_sqlite
docker build -t wallet-php:7.1 .

# Run the full test suite
docker run --rm -v "$(pwd)":/app -w /app wallet-php:7.1 sh -c "vendor/bin/phpunit"
```

Expected output:

```
PHPUnit 6.5.14 by Sebastian Bergmann and contributors.

.............                                                     13 / 13 (100%)

Time: ~400 ms, Memory: ~18.00MB
OK (13 tests, 47 assertions)
```

---

## Test coverage summary

| # | Test | Acceptance criterion covered |
|---|------|------------------------------|
| 1 | `it_debits_a_wallet_and_returns_the_transaction` | Happy-path debit and response shape. |
| 2 | `it_maintains_exact_decimal_precision` | Exact decimal arithmetic (e.g. `1.00 − 3 × 0.10 = 0.7000`). |
| 3 | `it_rejects_zero_amount` | Reject zero amount with `422`. |
| 4 | `it_rejects_negative_amount` | Reject negative amount with `422`. |
| 5 | `it_rejects_non_numeric_amount` | Reject non-numeric amount with `422`. |
| 6 | `it_rejects_too_many_fractional_digits` | Reject amounts with more than 4 fractional digits with `422`. |
| 7 | `it_rejects_currency_mismatch_without_changing_balance` | Currency mismatch returns `422` and leaves balance unchanged. |
| 8 | `it_returns_insufficient_funds_and_leaves_balance_unchanged` | Insufficient funds returns `409 insufficient_funds` and leaves balance unchanged. |
| 9 | `it_returns_the_original_transaction_on_idempotent_retry` | Same idempotency key + same body returns original transaction with no extra ledger row. |
| 10 | `it_conflicts_when_idempotency_key_is_reused_with_different_body` | Same key + different body returns `409 idempotency_conflict` and performs no debit. |
| 11 | `it_requires_the_idempotency_key_header` | Missing `Idempotency-Key` header returns `422`. |
| 12 | `it_returns_404_for_missing_wallet` | Unknown wallet returns `404`. |
| 13 | `concurrent_debits_prevent_overdraft` | Two simultaneous debits cannot overdraw the wallet; final balance equals starting balance minus successful debits. |

---

## Notes for production deployment

- The `wallet_transactions` table is intentionally append-only. No updates or deletions are performed by the application code.
- The `amount` stored in the ledger is signed (negative for debits) to satisfy the audit-trail requirement.
- The unique database constraint on `(wallet_id, idempotency_key)` provides a last-line-of-defense against duplicate ledger rows even if application-level locking were bypassed.
- The controller returns `JsonResponse` directly for all non-200 paths, so the behavior is consistent regardless of the request’s `Accept` header.
