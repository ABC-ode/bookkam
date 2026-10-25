# Functional tests (local only)

`python3 tests/functional/run_tests.py` — needs `php` (with `pdo_sqlite`), `node` and `python3`. Nothing else.

It copies `api/` to a temp directory, replaces **only** `config/db.php` with a SQLite stand-in, builds a throw-away database from `schema.sql`, and starts the real PHP code twice (production-mode and development-mode servers on 127.0.0.1). No production data, no network, no real payment credentials. Provider results come from the development-only simulator.

Covers booking security, payment verification/settlement (amount, currency, unknown/duplicate/concurrent/late/failed/timeout), wallet and test top-ups, auth/config failure modes, admin-portal key, and XSS defences (`esc.test.js` plus a source lint).

Limits: SQLite is a stand-in. MySQL-only SQL (`request_payout`'s `DATE_SUB`, `FOR UPDATE` in discount-code booking) is not exercised, `schema.sql` is inferred from the code, and SQLite locking only approximates MySQL row locking (the concurrency checks assert invariants, not timing). MySQL migrations are checked separately by `tests/migrations/run_migration_test.sh` (CI, MySQL 8 service). `tests/payment-provider-contract.php` checks the provider layer.
