<?php
/**
 * Optional capability for providers that can return money to the payer's source.
 *
 * refund()       returns: status (pending|success|failed|duplicate|unavailable), reference, message,
 *                         refund_amount_minor?, currency?
 * refundStatus() returns: status (pending|success|failed|unavailable), reference, message
 *
 * "duplicate" = the provider already has a refund under this merchant reference (the same call was made
 * before): the caller must ask refundStatus() instead of retrying. "unavailable" = unknown outcome, not a failure.
 */
interface PaymentRefundInterface
{
    public function refund(string $refundReference, string $originalReference, int $amountMinor, string $currency, array $options = []): array;
    public function refundStatus(string $refundReference): array;
}
