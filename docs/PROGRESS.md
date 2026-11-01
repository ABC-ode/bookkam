# Handover: feat/email-auth-opay (checkpoint, NOT release-ready)

Repo ABC-ode/bookkam (public). Base: main 022cb50 (untouched). This branch is ahead of main and fast-forwardable.
Read this file first. Do not re-audit or redo finished work.

## Rules
- No merge into main, no deploy, no production migrations, no branch deletion, no secrets or credentials in code.
- Work in batches of 2-3 fixes. After each batch: lint, run the relevant tests, commit, push, report in under 10 lines. Say clearly what was NOT tested.
- Keep Mapbox, OPay and the welcome credit OFF.
- Do not merge older branches. Most are superseded by main. `work/auth-guest-map-admin-fixes` has a driver-location gate that uses booking
  statuses which do not exist in this codebase (real ones: pending, confirmed, active, completed, cancelled), so do not use it.

## Environment (sandbox may be empty)
Install: php-cli php-sqlite3 php-curl php-mbstring php-mysql, mariadb-server, node. Clone the repo.
Tests: `python3 tests/functional/run_tests.py` (SQLite stand-in), `python3 tests/functional/email_auth_tests.py` (SQLite),
`BK_BACKEND=mysql ... python3 tests/functional/email_auth_tests.py` (needs MariaDB/MySQL; see the docstring for env vars),
`node tests/functional/auth_frontend.test.js`, `node tests/functional/legacy_chat_xss.test.js [dir]`, `php tests/payment-provider-contract.php`.

## Done on this branch
- Phone OTP removed. E-mail registration with a verification link, forgot/reset password, throttled login, single-use hashed tokens,
  tokens carried in the URL fragment. Google sign-in hardened (verified e-mail only, pre-registration hijack closed, admin accounts refused).
  Admin-only `issue_account_recovery` for phone-only accounts. Live XSS fix for `last_message`.
- Migrations 008 (backfill on first run only), 009 (e-mail columns and tokens), 010 (app_settings + admin 2FA columns). All re-run safe.
- Backend, NOT yet covered by new tests: bookings ON/OFF and payments ON/OFF switches (server-enforced, admin actions `get_app_settings` /
  `set_app_setting`, flags in `public-config.php`); admin TOTP 2FA (`admin_login` step 1, `admin_login_2fa`, `admin_2fa_begin/enable/disable/status`,
  recovery codes, encrypted secret, lockout shared with the password step); `add_city` returns the real id; driver location only for the customer's own
  confirmed/active booking; booking city must equal the car's city; pickup coordinates validated; hidden events cannot be booked; suspended users'
  sessions rejected; Mapbox flags (off by default, public pk. token only).
- Test status: `run_tests.py` 166/166 after the latest backend changes. `email_auth_tests.py` (69 SQLite / 78 MariaDB) and `auth_frontend.test.js` (14)
  passed at commit fda58fd, BEFORE the newer backend work: rerun them. The MySQL runner does not yet apply migration 010.

## To do, in this order
1. Frontend fixes
   - Hide Event: `index.php` `renderHomeBookingCards` falls back to the built-in card when the API returns an empty list. Use `Array.isArray(data.cards)`.
   - Remove the Apple button (`method-btn-apple` in `js/auth.js`). The server already answers 503 in production.
   - Remove the Driver role card; keep driver sign-in (it is role-agnostic) and add an "I want to drive with BOOKKAM" checkbox on the register tab.
   - Admin sidebar: CSS expands on `:hover` only and is `display:none` under 900px. Use `.sidebar:is(:hover,.open)`, add a topbar toggle, backdrop, ESC to close, and a mobile override.
   - `toggle_city` is called by the admin UI but does not exist in `admin.php`.
   - Bump `sw.js` `CACHE_NAME` (bookkam-v6) and add `?v=` to the script/css tags.
2. Admin screens: bookings and payments switches; 2FA enrol/disable; admin login step 2 (code or recovery code).
3. Tests for steps 1-2 and the backend items above: switches (existing trips, cancellations, refunds, verification must keep working while OFF), driver-location
   statuses, 2FA flow (replay, lockout, recovery code once, tampered challenge), SQL injection payloads, no sensitive fields in any API response.
4. Location / city / map: Use My Current Location, Choose City Manually (location only SUGGESTS a city), Choose Location button disabled with an explanation
   while Mapbox is off, Confirm Pickup Location saved and used by the booking. `index.php` loads Mapbox GL unconditionally and `maps.js` / `index.php` hard-code
   a `pk.` token: load Mapbox only when `/api/public-config.php` says enabled, and remove the hard-coded tokens (rotate or URL-restrict the exposed token).
5. OPay (keep disabled): rewrite the adapter, see below. Fake local server tests only.
6. Update docs (admin-2fa.md, payments.md), final full test run, push, short final report.

## OPay findings (from OPay's public documentation, doc.opaycheckout.com; re-verify before relying on them)
The current adapter is wrong: it sends the SECRET key as Bearer on every call and only knows the production host.
- Hosts: sandbox `https://sandboxapi.opaycheckout.com`, production `https://api.opaycheckout.com`.
- Create: `POST /api/v1/international/cashier/create`, headers `Authorization: Bearer <PublicKey>` and `MerchantId`. Body includes country, reference,
  amount {total in MINOR units, currency}, returnUrl (required), callbackUrl, cancelUrl, userInfo, product. Success code "00000"; the response carries cashierUrl.
- Status: `POST /api/v1/international/cashier/status`, body {reference, country}; `Authorization: Bearer <HMAC-SHA512 hex of the exact JSON body, key = SecretKey>`
  plus `MerchantId`. Statuses INITIAL, PENDING, SUCCESS, FAIL, CLOSE. Check amount, currency and reference on every result.
- Callback signature: HMAC-SHA3-512 with the SecretKey over the string
  `{Amount:"..",Currency:"..",Reference:"..",Refunded:t|f,Status:"..",Timestamp:"..",Token:"..",TransactionID:".."}`; compare with `hash_equals`.
  Always also re-verify through the status API (the callback is only a hint). Duplicate notifications must be idempotent.
- No OPay credentials were ever available to the agent, so NO sandbox transaction has been run. Do not claim OPay is verified or production-ready.

## Decisions already taken
- Welcome credit OFF by default (e-mail addresses are free, so one person could claim it repeatedly). Enable with WELCOME_CREDIT_ENABLED=1 only if acceptable.
- Admin 2FA is optional until each admin enrols (ADMIN_2FA_REQUIRED=0); once enrolled it is always required. Emergency SQL is in migrations/010.
- Phone-only accounts cannot sign in until an admin runs `issue_account_recovery` for them (no admin screen for it yet).
- Mapbox is OFF until the owner explicitly approves it.

## Owner actions (the agent cannot do these)
- Before any real deployment, run migrations 008, then 009, then 010 once on the live database. Set APP_URL and the mail settings in the server `.env`.
- Put OPay sandbox keys in the server `.env` yourself and complete one real sandbox payment before enabling OPay.
- CI wiring needs a token with the `workflow` scope (or apply the patch by hand on GitHub).
- Revoke the old GitHub token; use a new fine-grained one limited to this repo.
