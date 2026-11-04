# Handover: feat/email-auth-opay (all six batches done; NOT release-ready, see "Launch blockers")

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
- Backend now covered by tests (admin_security_tests.py): bookings/payments switches, admin TOTP 2FA, driver location only for the customer's own
  confirmed/active booking, `add_city`/`toggle_city`, hidden events, booking city = car city, validated pickup coordinates, Mapbox contract in public-config.
- Test status at the end of Batch 6: see the final report and the Batch log below. Run order: `run_tests.py`, `admin_security_tests.py`, `opay_tests.py`,
  `email_auth_tests.py` (also with `BK_BACKEND=mysql`), the four `*.test.js` files, `php tests/payment-provider-contract.php`, `php tests/opay-adapter-contract.php`.

## To do, in this order
(Batches 1-6 are DONE, see "Batch log" at the end.)
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

## OPay: corrected findings (see docs/payments.md for the full table)
The notes that used to be here came from OPay's EGYPT documentation (`doc.opaycheckout.com`, hosts sandboxapi/api, EGP). Nigeria's documentation is
`documentation.opaycheckout.com`: sandbox `https://testapi.opaycheckout.com`, production `https://liveapi.opaycheckout.com`, NGN in kobo. The adapter follows the
Nigeria documents. No OPay credentials were ever available, so NO sandbox transaction has been run. Do not claim OPay is verified or production-ready.

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

## Batch log
- Batch 1 (frontend) DONE: Hide Event honours an empty list; Apple button and Apple JS tag removed (server still answers 503); Driver role card removed, driver sign-in unchanged (role-agnostic), "I want to drive with BOOKKAM" checkbox on the Register tab sends role=driver (Google sign-up always creates a customer); admin sidebar `.open` + topbar toggle + backdrop + ESC + <900px drawer; `toggle_city` added to admin.php; sw.js CACHE_NAME bookkam-v7; `?v=` bumped on css/js tags and in the SW asset list. NOT browser-tested (no browser in the sandbox).
- Batch 2 (admin screens) DONE: Settings tab (sidebar "Settings") with the bookings/payments switches (confirm before OFF, explains what keeps working), read-only Mapbox status, and two-factor enrol (secret + otpauth link, no QR image), recovery codes shown once, disable (password + code); admin login step 2 (authenticator or recovery code), plus forced enrolment when the server requires it. NOT browser-tested; only stubbed-DOM smoke checks (formal tests in Batch 3).

- Batch 3 (tests) DONE: new `tests/functional/admin_security_tests.py` (163 checks, SQLite stand-in): switches (OFF refuses NEW bookings/payments/top-ups/event bookings; existing trips, cancellation+refund, verification of already-started payments keep working; only real booleans and known keys accepted; fails open if migration 010 is missing), driver-location statuses (only confirmed/active, owner only, minimal fields), toggle_city + hidden events, admin 2FA (enrol, step 2, replay incl. across sessions, challenge tampering/expiry/purpose mix-up, recovery code exactly-once incl. 6 parallel tries, lockout, disable needs password + FRESH code, forced enrolment), SQL-injection payloads x 18 entry points, response sweep (~70 responses) for secret fields/values. New `admin_frontend.test.js` (Batch 1-2 UI against the real js files). `email_auth_tests.py` MySQL mode now applies migration 010 and has MG10-MG22 (re-run safety, 2FA + switches on a real engine). Mutation check: 5 deliberate code breaks (location on 'pending', TOTP replay guard removed, 2FA skipped, payment switch not enforced, array city_id) were each caught. Bug found and fixed: `toggle_city` cast an array `city_id` to 1 and toggled city 1. CI now runs all of the above plus a MySQL 8 job for `email_auth_tests.py` (that job is NOT verified on MySQL 8, only on MariaDB 10.11).
  Observations (no code change): (a) `admin_2fa_disable` refuses the code that was just used to sign in, so right after a sign-in the admin must wait for the next 30-second code (the screen only says "Incorrect code"); (b) an admin JWT issued BEFORE 2FA was enabled stays valid until it expires (24 h); (c) `admin_security_tests.py` runs on SQLite only.

- Batch 4 (location / city / map) DONE: `maps.js` no longer contains a token and loads Mapbox GL (pinned v3.3.0) ONLY when `/api/public-config.php` says `mapbox.enabled === true` with a `pk.` token; any failure leaves it OFF and no Mapbox request is made (address search returns [], reverse geocoding null, map builders show a plain message). `index.php` no longer loads Mapbox CSS/JS or holds a token; the Grovve Yard popup asks `loadMapbox()` first. New `js/location.js`: GPS only on tap ("Use my current location"), city is only SUGGESTED (text; never switched, booking city stays the car's city; 30 km radius, active cities only), "Choose city manually" opens the existing selector, "Choose location on map" is disabled with an explanation until Mapbox is on, validated "lat,lng" (<= 10 decimals) sent as pickup_coords. The ride modal no longer asks for location on open. FIXED: the ride preview map used Leaflet (`L`), which is not loaded anywhere, so opening the ride modal threw and left its pickup suggestions/preview unwired; it now uses Mapbox GL when enabled and nothing when not (typed address is enough to book). Also fixed a literal "${esc(c.city)}" passed to the preview init. Removed the token from the stale copy `api/fonts/jss/maps.js` (those duplicate files are unreferenced and still deployable; delete them when you are ready). Tests: `location_frontend.test.js` (real maps/location/customer.js in a stubbed browser) and `admin_security_tests.py` G1-G6 (public-config contract). 8 deliberate breaks of the new code were each caught. NOT tested: real Mapbox (no token/account), a real browser, real GPS.

## Owner actions (Batch 4)
- The old `pk.` token is still in git history (and was on the live site). It is a public-type token, but rotate it or restrict it to your domain in the Mapbox dashboard before you enable Mapbox, then set `MAPBOX_ENABLED=1` and `MAPBOX_PUBLIC_TOKEN=pk...` only in the server .env.
- Pushing `.github/workflows/ci.yml` needs a token with the `workflow` scope (or push from your own machine).

- Batch 5 (OPay) DONE, still DISABLED and NOT verified against OPay: re-checked OPay's official docs and FOUND THE EARLIER NOTES WERE FROM THE EGYPT SITE (`doc.opaycheckout.com`: EGP, hosts sandboxapi/api). Nigeria's docs (`documentation.opaycheckout.com`) use `testapi.opaycheckout.com` (sandbox) and `liveapi.opaycheckout.com` (production), NGN in kobo, `product.name/description` required on create. `OpayPaymentProvider` rewritten to them: public key on create; HMAC-SHA512 of the exact body on status/refund; amount/currency/reference checks; exact documented status values only (no case folding); voided = not paid; cashier URL must be https on an OPay host; callback verification (HMAC-SHA3-512; the sample signature printed in OPay's docs is reproduced in a known-answer test); webhook treats the callback as a hint and re-verifies via the status API (400 forged, 503 retry, duplicates harmless); admin-only `opay_refund` (deterministic BKM-RF-<payment id> reference = cannot double refund), `opay_refund_status`, `opay_reconcile` (newest first, last 7 days). `OPAY_ENABLED` now also needs `OPAY_PUBLIC_KEY`. Return/callback URLs now come from APP_URL (the old code sent a hard-coded bookkam.com address as the callback). New: `tests/fake_opay/router.php`, `tests/functional/opay_tests.py` (161 checks), `tests/opay-adapter-contract.php`; 14 deliberate breaks were each caught. See docs/payments.md for the list of what only a real sandbox can confirm.

## Owner actions (Batch 5)
- Add the OPay sandbox keys and merchant id to the SERVER .env yourself (OPAY_PUBLIC_KEY, OPAY_SECRET_KEY, OPAY_MERCHANT_ID, OPAY_ENV=sandbox, APP_URL=https://...) and only then OPAY_ENABLED=true on a staging copy. Then do one real sandbox payment, callback and refund and tell me what OPay actually returned.
- Check whether OPay needs the webhook address set in its dashboard: <APP_URL>/api/payments.php?action=opay_webhook.

- Batch 6 (docs, full run) DONE: new docs/admin-2fa.md (2FA, switches, reset statement, JWT_SECRET rotation warning), docs/payments.md OPay section rewritten, this file brought up to date. Final run results are in the final report. Push: still blocked, see below.

## Launch blockers (nothing below is done by the code on this branch)
1. OPay is unverified: needs real sandbox keys, one real payment + callback + refund, the production cashier host, and the dashboard webhook check (docs/payments.md).
2. Mapbox: rotate or domain-restrict the old public token (it is in git history), then enable with MAPBOX_ENABLED=1 + MAPBOX_PUBLIC_TOKEN only on the server. Real Mapbox, the map picker and real GPS were never tested.
3. Nothing was run in a real browser or on a phone (sidebar drawer, register checkbox, location buttons, admin Settings and 2FA screens). Do a manual pass.
4. Migrations 008-010 have never been run on production data by this work (tested on SQLite and MariaDB 10.11 only, not MySQL 8; the MySQL 8 CI job is unverified).
5. Stale duplicate files under `api/fonts/jss/` (old JS copies) are still deployable; delete them. The `api/fonts/jss/maps.js` token was blanked.
6. `.github/workflows/ci.yml` changed: pushing it needs a token with the `workflow` scope, or push from your own machine.
7. Provider refunds are not tracked in the database (the live `payments.status` enum is not in the repo); wallet credit stays the default cancellation refund.

## Blockers
- The agent sandbox has no GitHub credentials. The branch was pushed once, up to fd3bd77 (Batch 2), with a temporary token the owner supplied and then revoked. Everything after that
  is delivered as a git bundle (`git pull <bundle> feat/email-auth-opay`, then push from your own machine). No credential is stored anywhere in the repo or the sandbox.
