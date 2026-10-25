# BOOKKAM payment provider plan

## Current status
- Paystack is removed from the active UI/payment-method listing.
- OPay is NOT ready for real payments; API access and the official merchant integration contract have not been validated.
- Provider-neutral interface and development simulator are being introduced.
- Never enable OPay in production or claim that a simulated payment is real.

## Design rules
- Amounts at the provider boundary are integer minor units (NGN kobo).
- Booking code consumes normalized provider statuses, not provider-specific payloads.
- Mark a payment successful only after authenticated provider-side verification.
- Treat callbacks as untrusted notifications; verify the reference and amount with the provider.
- Make confirmation idempotent and reconcile duplicate, late, mismatched, and unknown references.
- Refunds need an auditable record and idempotency key; never silently credit a wallet without one.
- Simulator is development-only and must never be selected in production.

## Simulator scenarios
Set BOOKKAM_SIMULATOR_SCENARIO to success, pending, failed, cancelled, or mismatch.
For success/mismatch, set BOOKKAM_SIMULATOR_AMOUNT_MINOR to the expected amount in kobo.
The simulator is not connected to the public payment flow yet and must not settle real bookings.

## OPay activation checklist
1. Obtain official merchant API docs and test credentials from OPay.
2. Confirm endpoint URLs, authentication/signature scheme, currency units, checkout response fields, status values, webhook verification, and refund support.
3. Implement the adapter from that contract; do not rely on unverified endpoint assumptions.
4. Add provider contract tests with recorded/sanitized fixtures.
5. Test success, pending, failure, cancellation, wrong amount/currency, duplicate callbacks, unknown reference, timeout, and provider outage.
6. Confirm a migration strategy for existing payments rows and legacy paystack_reference naming.
7. Run PHP lint, tests, and staging end-to-end tests before enabling production.
8. Store credentials only in server environment secrets; keep OPAY_ENABLED=false until all gates pass.

## Known issues to resolve before real integration
- Existing payments.paystack_reference is used to store OPay references. Plan a backward-compatible rename or add provider_reference with a migration and dual-read/write period.
- Existing confirmation marks the payment success before booking settlement; refund handling needs an auditable ledger and idempotent refund record.
- TEST_MODE defaults to development when APP_ENV is absent. Production must explicitly set APP_ENV=production; consider failing closed when environment is unspecified.
- Test wallet top-up directly credits balances in test mode. Ensure it cannot be enabled on a production deployment.
