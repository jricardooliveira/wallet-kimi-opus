# Wallet Debit — an AI-vs-AI coding challenge

This repository is an experiment: **one AI model designed a coding challenge,
another solved it, and the first one graded the result.**

- **Challenge author & evaluator:** Claude Opus 4.8
- **Solver:** Kimi Code 3.7 (run via [requesty.ai](https://requesty.ai) as a proxy)

The brief was a deliberately tricky task for an AI agent: a safe, idempotent
wallet-debit endpoint on a legacy stack (**Laravel 5.5 / PHP 7.1**) with exact
decimal money handling, overdraft and duplicate-charge protection, an immutable
ledger, and full feature-test coverage.

## The result

**Score: 88 / 100 (B+)**

Kimi avoided the float-math trap (used `bcmath`), implemented correct
row-locking for concurrency, and stayed fully PHP 7.1-compatible. It lost points
on one subtle idempotency bug that only manifests on a real database and was
masked by its own SQLite-based test suite.

## Documents

| File | What it is |
|------|------------|
| [CHALLENGE.md](CHALLENGE.md) | The exact task brief handed to the solver |
| [IMPLEMENTATION.md](IMPLEMENTATION.md) | The solver's own notes on what it built and the problems it hit |
| [EVALUATION.md](EVALUATION.md) | Criterion-by-criterion review, PHP 7.1 check, and bug findings |
| [RUBRIC.md](RUBRIC.md) | Reusable 100-point scoring rubric + the scored card |

## The endpoint

`POST /api/wallets/{walletId}/debit`

```json
{ "amount": "10.05", "currency": "EUR", "reference": "order-4471" }
```

Header: `Idempotency-Key: <string>` (required). Debits a wallet using exact
decimal arithmetic, rejects overdrafts (`409 insufficient_funds`), is safe to
retry (same key + same body returns the original transaction; different body
returns `409 idempotency_conflict`), and writes one immutable ledger row per
successful debit. See `CHALLENGE.md` for the full acceptance criteria.

## Running the tests

The host needs PHP 7.1 with the `bcmath` and `pdo_sqlite` extensions. Because
PHP 7.1 is end-of-life, the suite was verified inside Docker:

```bash
# Build a PHP 7.1 image with bcmath and pdo_sqlite
docker build -t wallet-php:7.1 .

# Install dependencies (Composer 2, pinned to PHP 7.1, scripts skipped)
docker run --rm -v "$(pwd)":/app -w /app composer:2 \
  composer install --no-scripts --ignore-platform-reqs

# Run the suite
docker run --rm -v "$(pwd)":/app -w /app wallet-php:7.1 vendor/bin/phpunit
```

> Built on the Laravel 5.5 application skeleton. The feature work lives in
> `app/Http/Controllers/WalletController.php`, `app/Http/Requests/DebitRequest.php`,
> the `app/Wallet*.php` models, the `database/migrations/2026_*` migrations, and
> `tests/Feature/WalletDebitTest.php`.
