<?php
require_once dirname(__DIR__) . "/PaymentProviderInterface.php";

/**
 * Development simulator ONLY. It never contacts a payment network and PaymentGateway refuses
 * to build it in production. Results are clearly labelled and are not evidence of real payment.
 *
 * Stateless: the scenario and expected amount travel inside the reference
 *   SIM-<scenario>-<amountMinor>-<anything>
 * Scenarios: success|pending|failed|cancelled|mismatch|currency|timeout|malformed
 * (CLI/contract tests may instead use BOOKKAM_SIMULATOR_SCENARIO / _AMOUNT_MINOR.)
 */
final class SimulatorPaymentProvider implements PaymentProviderInterface
{
    public const SCENARIOS = ["success", "pending", "failed", "cancelled", "mismatch", "currency", "timeout", "malformed"];

    public static function buildReference(string $scenario, int $amountMinor, string $suffix): string
    {
        if (!in_array($scenario, self::SCENARIOS, true)) $scenario = "pending";
        return "SIM-" . $scenario . "-" . $amountMinor . "-" . $suffix;
    }

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
        $amount   = (int)(getenv("BOOKKAM_SIMULATOR_AMOUNT_MINOR") ?: 10000);
        if (preg_match('/^SIM-([a-z]+)-(\d+)-/', $reference, $m)) { $scenario = $m[1]; $amount = (int)$m[2]; }
        if (!in_array($scenario, self::SCENARIOS, true)) $scenario = "pending";

        $base = ["status" => $scenario, "reference" => $reference, "currency" => "NGN",
            "message" => "SIMULATED ONLY: this result is not evidence of a real payment."];
        switch ($scenario) {
            case "success":   return $base + ["amount_minor" => $amount];
            case "mismatch":  return ["status" => "success"] + $base + ["amount_minor" => max(0, $amount - 1)];
            case "currency":  return ["status" => "success", "currency" => "USD"] + $base + ["amount_minor" => $amount];
            case "timeout":   return ["status" => "unavailable", "reference" => $reference, "message" => "SIMULATED provider timeout."];
            case "malformed": return ["status" => "success", "reference" => $reference, "message" => "SIMULATED malformed response (no amount/currency)."];
            default:          return $base; // pending | failed | cancelled
        }
    }
}
