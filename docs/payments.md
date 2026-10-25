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

## OPay: what is NOT verified
The endpoint paths, auth header scheme, amount units, response codes (`00000`), status names and webhook semantics are the assumptions already in the code. They have **not** been checked against current official OPay documentation or a sandbox. Do not set `OPAY_ENABLED=true` in production until:
1. Official merchant docs and sandbox credentials are obtained and the adapter is checked against them (paths, signing, units, statuses, webhook authentication, refunds).
2. Provider contract tests with sanitized recorded fixtures are added.
3. Success, pending, failure, cancellation, wrong amount/currency, duplicate callback, unknown reference, timeout and outage are exercised end-to-end in staging.
`OPAY_ENABLED` is only honoured when `OPAY_SECRET_KEY` and `OPAY_MERCHANT_ID` are also set. The OPay webhook performs no signature check of its own; it relies on re-verifying with OPay's API, which must be confirmed against the docs.

## Every code path that changes `users.wallet_balance`
| Path | Direction | Guard |
|---|---|---|
| `payments.php` wallet payment | − | atomic `wallet_balance >= amount`, same transaction as booking claim |
| `payments.php` late/duplicate payment | + | payment row claimed once, same transaction |
| `payments.php` test top-up | + | dev only, idempotency key + unique reference, same transaction |
| `bookings.php` cancel / cancelled via update_status | + | booking status claim `pending/confirmed → cancelled`, same transaction |
| `bookings.php` release_deposit | + | `deposit_released` claim, completed bookings only |
| `admin.php` add_damage_report | + | `deposit_released` claim, same transaction as the report |
| `auth.php` createUser | + ₦1,000 | **welcome credit, unrestricted per signup (known risk: credit farming; see audit)** |

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
