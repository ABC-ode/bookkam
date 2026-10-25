<?php
/**
 * Provider contract. Amounts are integer minor units (kobo for NGN).
 *
 * initiate() returns: status (pending|failed|unavailable), reference, checkout_url?, message
 * verify()   returns: status (success|pending|failed|cancelled|unavailable), reference,
 *                     amount_minor?, currency?, message
 *
 * "unavailable" means the provider could not be reached or its answer could not be understood
 * (timeout, non-2xx, malformed body). It is NOT a payment failure and NOT proof of payment:
 * callers must leave the booking unchanged and retry later.
 * Implementations must never return "success" without provider-side verification.
 */
interface PaymentProviderInterface
{
    public function initiate(string $reference, int $amountMinor, string $currency, string $email, array $metadata = []): array;
    public function verify(string $reference): array;
}
