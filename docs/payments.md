# BOOKKAM payments — current status, policies, deployment

## What works today
| Capability | Status |
|---|---|
| Cash (settled with the driver; admin confirms) | Works. Booking stays `pending` until an admin confirms. Repeated initiation does not create duplicate rows. |
| Wallet payment | Works. One DB transaction: claim booking → atomic conditional debit → payment row. No double charge; paid/cancelled/completed bookings are refused. |
| Cancellation refund | Works. Paid price + deposit return to the wallet exactly once, in the same transaction that cancels. |
| OPay online payment | **Disabled and not production-ready** (see below). |
| Wallet top-up (production) | **Disabled.** Returns HTTP 503. No verified provider-backed top-up flow exists. |
| Wallet top-up (development) | Only when `APP_ENV` is `development`/`local`/`test`/`testing`. Labelled test credit (`method=test`, `is_test=1`, `booking_kind=topup`, reference `test_wallet_<user>_<key>`), idempotent per `idempotency_key`, max ₦1,000,000. |
| Payment simulator | Development only (`PaymentGateway` refuses to build it in production). Drives the real verify/settlement path with scripted provider results. |
| Paystack | Removed. Historical `paystack_reference` data is preserved (migration 006). |

## Provider layer (`api/services`)
`PaymentProviderInterface` (`initiate`, `verify`; amounts in kobo), `PaymentGateway` (explicit provider selection, unknown names fail closed), `OpayPaymentProvider`, `SimulatorPaymentProvider`.
`verify()` returns `success|pending|failed|cancelled|unavailable`. `unavailable` (timeout, non-2xx, malformed body, unknown status) never changes a booking and makes the OPay webhook answer 503 so the provider retries.

## Settlement rules (`api/api/payments.php`)
* A payment is confirmed **only** from `verify()` output: a webhook body is an untrusted hint (only the reference is read from it) and the browser's return to the site is never proof.
* The result must match: reference known, **currency = NGN**, **amount = booking total + deposit, exactly**. A missing amount or currency counts as a mismatch.
* The payment row is set to `success` in the same transaction that settles the booking (or credits the wallet). A failure rolls both back; the payment stays `pending` and a retry is safe.
* Duplicate / concurrent confirmations: the row is claimed once (`UPDATE … WHERE status<>'success'`), the second caller is a no-op.
* **Late-payment policy:** if the booking is cancelled or already paid when a verified payment arrives, the booking is **not** revived; the amount is credited to the customer's wallet with a notification (event bookings have no wallet: logged for manual refund). Chosen because it is safe and automatic; change it if the business prefers refund-to-source.
* Failed/cancelled provider results are logged (reference and status only; no secrets or personal data) and leave the booking `pending`. They are **not** written to `payments.status`, because the production enum for that column is not in the repository (see "Data needed").

## OPay (Nigeria): what the adapter does, and what is NOT verified
**Status: disabled by default, and NOT verified against OPay.** No OPay credential or sandbox transaction was ever available when this was written. Everything below was checked against OPay's published Nigeria documentation (`documentation.opaycheckout.com`) and exercised against a fake local server only. Do not call it production-ready.

**Correction to earlier notes:** OPay has two documentation sites. `doc.opaycheckout.com` is the Egypt one (EGP, hosts `sandboxapi`/`api`). Nigeria's is `documentation.opaycheckout.com` (NGN, kobo, hosts `testapi.opaycheckout.com` for sandbox and `liveapi.opaycheckout.com` for production). The adapter uses the Nigeria hosts.

| Topic | What the Nigeria documentation says | Where it is implemented / tested |
|---|---|---|
| Create | `POST /api/v1/international/cashier/create`, `Authorization: Bearer <public key>`, `MerchantId`; `country` NG, `amount.total` in kobo (100 kobo = 1 NGN), `returnUrl`, `product.name` + `product.description` required; success code `00000` | `OpayPaymentProvider::initiate`; `opay_tests.py` O3-O11 |
| Status | `POST /cashier/status`, `Bearer HMAC-SHA512(exact JSON body, secret key)`; statuses INITIAL, PENDING, SUCCESS, FAIL, CLOSE | `verify`; V1-V17, S2 (independent HMAC) |
| Callback | `{payload, sha512, type}`; `sha512` = HMAC-SHA3-512 over `{Amount:"..",Currency:"..",Reference:"..",Refunded:t/f,Status:"..",Timestamp:"..",Token:"..",TransactionID:".."}`; non-2xx answers are retried for 72 hours | `verifyCallback`; known-answer test reproduces the signature printed in OPay's own sample (`opay-adapter-contract.php` K1-K2); W1-W13 |
| Refund | `POST /payment/refund/create` (+ `/refund/query`), signed like status; `refundWay` Original or BankAccount; card, bank-transfer and USSD payments can only be refunded to a bank account the customer gives | `refund`, `refundStatus`, admin `opay_refund`; R1-R14 |

**How the app uses it**
* A callback is only a hint. After its signature is verified, status, amount and currency come from OPay's signed status API, through the same settlement code as the customer-facing verify. A forged or tampered callback gets 400 and makes no call to OPay. If OPay cannot confirm yet the answer is 503 so OPay retries. Duplicate callbacks are harmless.
* Provider values must match the documentation exactly (`SUCCESS`, `NGN`); anything else is never success. A voided payment is not money received. The cashier URL must be https on an OPay host (`*.opaycheckout.com`, or hosts you list in `OPAY_CASHIER_HOSTS`).
* Admin-only actions in `payments.php` (no screen for them yet): `opay_refund` (full amount; the refund reference `BKM-RF-<payment id>` is fixed per payment, so a repeat can never refund twice), `opay_refund_status`, `opay_reconcile` (asks OPay about pending OPay payments of the last 7 days, newest first, limit 1-100). Bank details typed for a refund are sent to OPay and are not stored or logged. These actions do not change `payments.status` or the booking, because the production enum is not in the repository: record refunds in your books. The default cancellation refund is still wallet credit.

**Not verified (needs real sandbox keys; the owner adds them to the server `.env`, never to chat or git)**
1. One real sandbox payment, one real callback and one real refund. Until then, treat every behaviour above as an assumption that matches the documents.
2. The production cashier host. Only the sandbox cashier (`sandboxcashier.opaycheckout.com`) appears in the documents; if production uses another host, creating a payment fails closed until it is added to `OPAY_CASHIER_HOSTS`.
3. Whether OPay honours the per-request `callbackUrl` or needs the webhook URL set in the OPay dashboard (`<APP_URL>/api/payments.php?action=opay_webhook`), and what OPay appends to `returnUrl`.
4. The shape and signature of refund-status notifications (they are acknowledged and ignored; use `opay_refund_status`), the exact set of error codes, rate limits, and OPay's source IP addresses.
5. Refunds to the original source for each pay method, and which pay method a given customer used (not stored today).

`OPAY_ENABLED` is only honoured when `OPAY_PUBLIC_KEY`, `OPAY_SECRET_KEY` and `OPAY_MERCHANT_ID` are all set. Tests: `python3 tests/functional/opay_tests.py` (161 checks against `tests/fake_opay/router.php`) and `php tests/opay-adapter-contract.php`.

## Every code path that changes `users.wallet_balance`
| Path | Direction | Guard |
|---|---|---|
| `payments.php` wallet payment | − | atomic `wallet_balance >= amount`, same transaction as booking claim |
| `payments.php` late/duplicate payment | + | payment row claimed once, same transaction |
| `payments.php` test top-up | + | dev only, idempotency key + unique reference, same transaction |
| `bookings.php` cancel / cancelled via update_status | + | booking status claim `pending/confirmed → cancelled`, same transaction |
| `bookings.php` release_deposit | + | `deposit_released` claim, completed bookings only |
| `admin.php` add_damage_report | + | `deposit_released` claim, same transaction as the report |
| `security.php` grantWelcomeCredit | + ₦1,000 | **welcome credit, once per verified identity** (see "OTP limits and welcome credit" below). `createUser` itself no longer credits anything. |

## Sign-in and booking hardening (added after the payment work)
* `set_city` no longer trusts the browser. `google_login`/`apple_login` return a 15-minute signed `pending_token`; `set_city` takes the provider id, e-mail, name, photo and role **from that token only** (role can only be customer/driver, never admin). Before this, an anonymous request could create an admin account and receive a session.
* **Sign in with Apple is disabled in production (HTTP 503).** The Apple identity token's signature was never verified, so a forged token could log in as any account with a matching e-mail. Re-enable only after implementing and testing Apple JWKS, issuer, audience and expiry checks.
* Google sign-in links to an existing account by e-mail only when Google reports `email_verified`.
* `bookings.php create` validates type, hours (0.5-168), days (1-60) and text lengths, and claims the car with one conditional `UPDATE` inside the booking transaction (no double booking). Before this, `duration_hours=-5` produced a **negative** total.

## Environment
`APP_ENV` is an allow-list: only `development`, `local`, `test`, `testing` enable test behaviour (fixed OTP, dev JWT fallback, simulator, test top-ups). **Anything else, including unset, `prod`, `staging`, is treated as production.** In production the app refuses to start with a missing, short (<32) or `REPLACE_WITH…` `JWT_SECRET`, and refuses `REPLACE_WITH…` database settings. `ADMIN_PORTAL_KEY` (≥16 chars, not a placeholder) is the only way to open the secret-URL admin portal; the old hard-coded key is gone from the code but **was public in the repository history, so never reuse it**.
Runtime: PHP **8.0+** is required (`str_starts_with`, `str_contains`); CI tests 8.1 and 8.3. Extensions: `pdo_mysql`, `curl`.

## Database migrations — apply by hand, in this order, once each
Take a backup first. Migrations are plain SQL for MySQL (phpMyAdmin or CLI). None is run by the application.
| # | File | Needed before deploying this code? | Notes |
|---|---|---|---|
| 003 | `003_payments_booking_kind.sql` | yes — **check first**: `SHOW COLUMNS FROM payments LIKE 'booking_kind';` (skip if the column exists) | adds `booking_kind VARCHAR(10)` |
| 005 | `005_payments_method_paystack_opay.sql` | yes if not applied (check `SHOW COLUMNS FROM payments LIKE 'method';` for `paystack`,`opay`) | **Original content restored.** It had been edited in place to drop `paystack` from the ENUM; that edit would have failed or truncated historical `method='paystack'` rows. Do not re-run it. |
| 006 | `006_provider_reference.sql` | **yes** | adds `provider_reference`, copies `paystack_reference`, keeps the legacy column. Run once: a second run fails at `ADD COLUMN` (duplicate column) and changes nothing. Rollback: `ALTER TABLE payments DROP COLUMN provider_reference;` (safe: legacy column untouched). |
| 007 | `007_provider_reference_unique.sql` | **yes** | run the duplicate check at the top of the file first; the ALTER refuses to run if duplicates exist. Rollback is in the file. |

**Safe deployment order:** backup → run 006 → run 007 (after its duplicate check) → deploy the code → check `?action=get_methods`. Deploying the code before 006 breaks payment lookups (`provider_reference` column missing).
**Legacy compatibility:** the `paystack_reference` column is intentionally kept. Pending Paystack payments created before cut-over are *not* confirmable any more (Paystack is removed): reconcile them manually in the Paystack dashboard before deploying.

## Data needed to finish the failed-payment state
Run `SHOW CREATE TABLE payments;` on production and share it. It is required to (a) add a `failed`/`refunded` status if the enum allows it, and (b) confirm `booking_id` accepts `0` (used by test top-up audit rows; irrelevant in production where top-ups are off).


## OTP limits and welcome credit (migration 008)
**OTP verification (`auth.php verify_otp`).** Each attempt is counted *before* the code is compared, atomically, per account (`OTP_VERIFY_MAX_FAILS`, default 5 per `OTP_VERIFY_WINDOW`, default 900 s) and per IP (`OTP_VERIFY_IP_MAX_FAILS`, default 30). Past the limit the answer is HTTP 429 even for the correct code; reaching the limit also destroys the live OTP. A correct code forgives that account's failures; wrong guesses, resends and unknown user ids never reset a counter. The code is consumed with a conditional `UPDATE`, so only one of several concurrent requests can use it. Unknown user, wrong, expired and used codes all answer the same 401; the "suspended" message is shown only after a correct code. Counters live in `auth_throttle`; if that table can be neither found nor created the endpoint answers 503 (fails closed). The IP is `REMOTE_ADDR` only (never `X-Forwarded-For`); behind a proxy/CDN every visitor would share one IP bucket until a trusted real-IP header is mapped in `clientIp()`.

**Welcome credit.** The only place that ever granted it was `createUser` (called by phone OTP, e-mail registration, Google, Apple and `set_city`). It is now granted by `grantWelcomeCredit()` only to a **customer whose identity has just been proven**: a phone after OTP verification, or a Google-verified subject id. The claim row (`welcome_credit_claims`) and the wallet credit are written in one transaction; `UNIQUE(user_id)` and `UNIQUE(identity_key)` (sha256 of `phone:<last 10 digits>` or `google:<sub>`) make a second credit impossible for the same account or the same verified identity, however many requests race. Not eligible: e-mail/password signups (the address is never verified), Apple (token not verified, see above), drivers, admins. Migration 008 back-fills every existing customer as already claimed so nobody is credited twice. `WELCOME_CREDIT_ENABLED=0` switches the credit off.
**Residual risk:** one person with several SIM cards / Google accounts can still claim once per identity. Nothing in the current schema can prove "one human"; if that cost matters, disable the credit or tie it to a first completed paid booking (a business-rule change, not made here).
