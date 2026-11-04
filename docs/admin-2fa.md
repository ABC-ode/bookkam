# Admin two-factor authentication and the service switches

## Two-factor sign-in (admins only)
Authenticator-app codes (TOTP: 6 digits, 30 seconds, HMAC-SHA1, the standard used by Google Authenticator, Microsoft Authenticator, Authy, 1Password).

**Turn it on (Admin > Settings > Two-factor authentication)**
1. "Set up two-factor" shows a setup key and an `otpauth://` link (there is no QR image). Add the key to the authenticator app.
2. Enter the 6-digit code. The server then shows 8 recovery codes ONCE (format `xxxx-xxxx-xxxx`). Save them somewhere safe. Only SHA-256 hashes are stored.

**Sign in.** Step 1 is the password. For an enrolled admin the server answers with a short-lived challenge and NO session. Step 2 takes the authenticator code or one recovery code. A recovery code works exactly once. The challenge is held in memory only and expires after a few minutes.

**Forced mode.** With `ADMIN_2FA_REQUIRED=1` in the server `.env`, an admin who has not enrolled is taken through enrolment at sign-in and gets a session only after finishing it. Default is off.

**Turn it off.** Settings > "Turn two-factor OFF" needs the password AND a current code. This clears the stored secret and recovery codes.

## Behaviour worth knowing
- **Codes are single use.** A code that was already accepted (also the one used to enrol or to sign in) is refused again. Right after signing in with a code you must wait for the next 30-second code before you can turn 2FA off. The screen only says "Incorrect code".
- **Lockout.** Five wrong attempts lock the admin out for the throttle window; the lock is shared with the password step, so even the right password gets 429 until it passes.
- **Older sessions.** A session issued before 2FA was turned on stays valid until it expires (24 h).
- **Secret at rest.** AES-256-GCM, key derived from `JWT_SECRET`. **Rotating `JWT_SECRET` makes every stored 2FA secret unreadable** and locks enrolled admins out; reset them with the statement below and have them enrol again.
- **Lost phone and no recovery codes (owner, on the server database, not from the app):**
  `UPDATE users SET admin_totp_enabled=0, admin_totp_secret=NULL, admin_totp_last_step=NULL, admin_recovery_hashes=NULL WHERE email='the-admin@example.com';`
  Do this only after confirming the person's identity. Migration 010 adds these columns.

## Service switches (Admin > Settings > Service switches)
- **New bookings OFF:** new bookings, new event bookings, new payments and wallet top-ups are refused with 503 (`bookings_paused`).
- **New payments OFF:** starting a payment or a top-up is refused with 503 (`payments_paused`); new bookings are still accepted.
- **Always keeps working while OFF:** trips that already exist (start/complete), cancellation and its refund, and verification of payments that were already started.
- Only real booleans are accepted and only these two keys can be changed. If migration 010 has not been applied the app fails OPEN (bookings keep working) and saving a switch answers that the migration is missing.
- The Mapbox line on that screen is read-only: it is set in the server `.env` (`MAPBOX_ENABLED`, `MAPBOX_PUBLIC_TOKEN`) and is OFF by default.

## Tests
`python3 tests/functional/admin_security_tests.py` (switches, location privacy, 2FA, SQL injection, response hygiene, Mapbox contract), `node tests/functional/admin_frontend.test.js` (the screens), and `BK_BACKEND=mysql ... python3 tests/functional/email_auth_tests.py` (migration 010 and 2FA on a real MySQL-family engine).
