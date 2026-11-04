# Functional tests (local only)

`python3 tests/functional/run_tests.py` — needs `php` (with `pdo_sqlite`), `node` and `python3`. Nothing else.

It copies `api/` to a temp directory, replaces **only** `config/db.php` with a SQLite stand-in, builds a throw-away database from `schema.sql`, and starts the real PHP code twice (production-mode and development-mode servers on 127.0.0.1). No production data, no network, no real payment credentials. Provider results come from the development-only simulator.

Covers booking security, payment verification/settlement (amount, currency, unknown/duplicate/concurrent/late/failed/timeout), wallet and test top-ups, auth/config failure modes, admin-portal key, and XSS defences (`esc.test.js` plus a source lint).

Limits: SQLite is a stand-in. MySQL-only SQL (`request_payout`'s `DATE_SUB`, `FOR UPDATE` in discount-code booking) is not exercised, `schema.sql` is inferred from the code, and SQLite locking only approximates MySQL row locking (the concurrency checks assert invariants, not timing). MySQL migrations are checked separately by `tests/migrations/run_migration_test.sh` (CI, MySQL 8 service). `tests/payment-provider-contract.php` checks the provider layer.

## More suites
- `python3 tests/functional/admin_security_tests.py` — admin ON/OFF switches (bookings, payments: what is refused and what keeps working), driver-location privacy by booking status, `toggle_city` and hidden events, admin two-factor (enrol, sign-in step 2, replay, challenge tampering, recovery codes, lockout, disable, forced enrolment), SQL-injection payloads against 18 entry points, and a sweep proving no secret field or value appears in ~70 API responses. SQLite stand-in; reuses `run_tests.py`.
- `python3 tests/functional/email_auth_tests.py` — e-mail verification, reset, throttling, Google linking. `BK_BACKEND=mysql ...` runs it on a real MySQL/MariaDB, applies migrations 008, 009 and 010 on top of legacy rows and re-runs them (see the docstring for the env variables).
- `node tests/functional/auth_frontend.test.js`, `node tests/functional/admin_frontend.test.js`, `node tests/functional/location_frontend.test.js` (Mapbox only when the server enables it, tap-only GPS, city suggestion without switching, map button disabled while Mapbox is off) — real `auth.js` / `admin.js` against a stubbed DOM and `api()`; they do not replace a browser check.
- `python3 tests/functional/opay_tests.py` (+ `php tests/opay-adapter-contract.php`) — the OPay (Nigeria) adapter against `tests/fake_opay/router.php`, a FAKE local server that enforces what OPay's public documentation says (public key on create, HMAC-SHA512 of the exact body on status/refund, NG/NGN/kobo, duplicates). Covers create, verify, signed callbacks, duplicate and parallel notifications, refunds, reconciliation, hostile and broken answers. It never contacts OPay and does not prove the real integration works.

