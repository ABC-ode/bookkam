<?php
require_once dirname(__DIR__) . "/PaymentProviderInterface.php";

/**
 * OPay adapter boundary. Do not enable real payments until official merchant
 * documentation, credentials, and a verified test environment are available.
 * Existing endpoint/payload assumptions are intentionally not treated as authoritative.
 */
final class OpayPaymentProvider implements PaymentProviderInterface
{
    public function initiate(string $reference, int $amountMinor, string $currency, string $email, array $metadata = []): array
    {
        if (!defined("OPAY_ENABLED") || !OPAY_ENABLED ||
            !defined("OPAY_SECRET_KEY") || !OPAY_SECRET_KEY ||
            !defined("OPAY_MERCHANT_ID") || !OPAY_MERCHANT_ID) {
            return ["status" => "unavailable", "reference" => $reference,
                "message" => "OPay credentials/API access are not configured."];
        }
        return ["status" => "unavailable", "reference" => $reference,
            "message" => "OPay adapter is not production-ready; validate the official merchant API contract first."];
    }

    public function verify(string $reference): array
    {
        return ["status" => "unavailable", "reference" => $reference,
            "message" => "OPay verification is unavailable until the official merchant API contract is validated."];
    }
}
