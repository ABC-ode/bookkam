<?php
/**
 * Provider contract. Amounts are integer minor units (kobo for NGN).
 * Implementations must never mark a payment successful without provider-side verification.
 */
interface PaymentProviderInterface
{
    public function initiate(string $reference, int $amountMinor, string $currency, string $email, array $metadata = []): array;
    public function verify(string $reference): array;
}
