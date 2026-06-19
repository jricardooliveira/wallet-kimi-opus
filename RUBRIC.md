# Scoring Rubric — Wallet Debit Endpoint

A reusable rubric for grading any implementation of the
`POST /api/wallets/{walletId}/debit` task, followed by the scored card for this
submission (`wallet-kimi-opus`).

## Rubric (100 points)

### 1. Functional correctness — acceptance criteria (40 pts)

| Item | Pts | What earns full marks |
|------|-----|-----------------------|
| Happy-path debit + response shape | 6 | Correct fields, status 200, balance reduced |
| Amount validation (zero / negative / non-numeric / >4 dp) → 422 | 6 | All four rejected, balance unchanged |
| Currency mismatch → 422, no change | 5 | Checked before any mutation |
| Insufficient funds → 409, no change | 6 | Exact-balance spend allowed; no negative balance |
| Idempotent retry (same key + same body → original, no new row) | 9 | Returns original txn id **on the production DB**, not just SQLite |
| Idempotency conflict (same key + different body) → 409 | 4 | No debit performed |
| Audit/ledger row written once, immutable | 4 | One row per successful debit, none on retry |

### 2. PHP 7.1 compatibility (15 pts)

| Item | Pts |
|------|-----|
| No 7.4+ syntax (typed properties, `fn()`, `??=`, spread tweaks) | 6 |
| No 8.x syntax (`match`, enums, `readonly`, `?->`, ctor promotion, `str_contains`) | 6 |
| Dependencies / framework pinned to a 7.1-compatible set | 3 |

### 3. Concurrency & idempotency robustness (20 pts)

| Item | Pts | What earns full marks |
|------|-----|-----------------------|
| Pessimistic lock (`SELECT … FOR UPDATE`) inside a transaction | 8 | Prevents overdraft under real concurrency |
| Idempotency stored durably + unique DB constraint | 6 | Unique `(wallet_id, idempotency_key)` as last line of defense |
| Race-window handling (duplicate first request) | 3 | Unique-violation caught and resolved gracefully |
| Body-fingerprint comparison is **value-correct** across DB drivers | 3 | Numeric comparison, not driver-dependent string equality |

### 4. Money precision (10 pts)

| Item | Pts |
|------|-----|
| Exact decimal arithmetic, no float math | 7 |
| Consistent, deterministic response formatting | 3 |

### 5. Test quality (10 pts)

| Item | Pts |
|------|-----|
| Every acceptance criterion covered | 5 |
| Concurrency tested with a real race (not mocked) | 3 |
| Tests exercise the production code path, not an environment that masks it | 2 |

### 6. Code quality & documentation (5 pts)

| Item | Pts |
|------|-----|
| Clear structure, separation of concerns | 3 |
| Honest, accurate implementation notes | 2 |

## Scored card — `wallet-kimi-opus`

| Category | Max | Awarded | Notes |
|----------|-----|---------|-------|
| 1. Functional correctness | 40 | **35** | −5: idempotent retry returns 409 instead of 200 on MySQL (`DECIMAL` trailing-zero round-trip). All other AC pass. |
| 2. PHP 7.1 compatibility | 15 | **15** | Clean — no modern syntax; platform pinned to 7.1.0. |
| 3. Concurrency & idempotency robustness | 20 | **16** | Lock + transaction + unique index + race fallback all present (17/17). −1 of the value-correct fingerprint sub-item (2/3): string equality is driver-dependent. |
| 4. Money precision | 10 | **9** | Exact `bcmath` math (7/7). −1: response mixes 2-dp `amount` with 4-dp `balance_after`. |
| 5. Test quality | 10 | **8** | All AC covered + a real multi-process race (8/8 for those). −2: suite runs only on SQLite, which masks Bug 1 and makes `lockForUpdate` a no-op. |
| 6. Code quality & docs | 5 | **5** | Clear layering; `IMPLEMENTATION.md` honest and accurate. |
| **Total** | **100** | **88** | **B+** |

### Score summary

- **88 / 100 (B+).**
- **Single highest-impact fix:** replace the strict-string amount comparison in
  `WalletController::matchesRequest()` (and the `:98` fallback) with
  `bccomp($transactionAmount, $amount, 4) === 0`. This recovers the 5 functional
  points and 1 robustness point — raising the score to **94 (A)**.
- **Second fix:** add a MySQL-backed idempotency test so the regression cannot
  reappear silently (recovers the remaining test-quality points).

See `EVALUATION.md` for the full narrative and evidence.
