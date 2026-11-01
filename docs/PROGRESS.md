# Work in progress: feat/email-auth-opay (checkpoint, not release-ready)

DONE and covered by the existing suite (tests/functional/run_tests.py 166/166 at this commit):
- add_city returns the real new id; driver location only for the customer's own confirmed/active booking (403 otherwise)
- app-wide bookings switch + separate payments switch (server-enforced; migration 010; admin actions get_app_settings / set_app_setting)
- hidden events cannot be booked; booking city must equal the car's city; pickup coordinates validated
- admin two-factor (TOTP + recovery codes, encrypted secret, lockout shared with password step); suspended users' sessions rejected
- Mapbox flags server-side (off by default, public pk. token only) in public-config.php

NOT DONE YET (in this order):
1. New tests for everything above (platform_tests.py): switches, driver-location statuses, admin 2FA flow, SQL injection, data exposure
2. Frontend: admin sidebar toggle (CSS is hover-only), Hide Event fix (index.php falls back to default card when API returns an empty list),
   hide Apple button, remove Driver role card (keep driver sign-in/sign-up), switches + 2FA screens in admin, bump sw.js cache + ?v= tags
3. Location / city / map button / confirm-pickup UI; load Mapbox only when enabled; remove hard-coded token from maps.js and index.php
4. OPay: rewrite adapter to official docs (public key for create, HMAC-SHA512 status, sandbox host, callback signature); fake-server tests
5. Docs (admin-2fa.md, payments.md), final full test run, push, final report
