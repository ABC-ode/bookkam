<?php
require_once dirname(__DIR__) . "/PaymentProviderInterface.php";

/**
 * Development simulator only. Scenario: success|pending|failed|cancelled|mismatch.
 * This class never contacts a payment network and must not be used in production.
 */
final class SimulatorPaymentProvider implements PaymentProviderInterface
{
    private const SCENARIOS = ["success", "pending", "failed", "cancelled", "mismatch"];

    public function initiate(string $reference, int $amountMinor, string $currency, string $email, array $metadata = []): array
    {
        if ($amountMinor <= 0 || !preg_match('/^[A-Z]{3}$/', $currency)) {
            return ["status" => "failed", "reference" => $reference, "message" => "Invalid amount or currency."];
        }
        return ["status" => "pending", "reference" => $reference, "checkout_url" => "",
            "message" => "SIMULATED ONLY: no money has been moved."];
    }

    public function verify(string $reference): array
    {
        $scenario = strtolower((string)(getenv("BOOKKAM_SIMULATOR_SCENARIO") ?: "pending"));
        if (!in_array($scenario, self::SCENARIOS, true)) $scenario = "pending";
        $result = ["status" => $scenario, "reference" => $reference, "currency" => "NGN",
            "message" => "SIMULATED ONLY: this result is not evidence of a real payment."];
        if ($scenario === "success") {
            $result["amount_minor"] = (int)(getenv("BOOKKAM_SIMULATOR_AMOUNT_MINOR") ?: 10000);
        } elseif ($scenario === "mismatch") {
            $result["amount_minor"] = max(0, (int)(getenv("BOOKKAM_SIMULATOR_AMOUNT_MINOR") ?: 10000) - 1);
        }
        return $result;
    }
}
