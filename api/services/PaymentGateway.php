<?php
require_once __DIR__ . "/PaymentProviderInterface.php";
require_once __DIR__ . "/providers/SimulatorPaymentProvider.php";
require_once __DIR__ . "/providers/OpayPaymentProvider.php";

/** Provider-specific API details stay outside booking and wallet code. */
final class PaymentGateway
{
    /** Provider selection is explicit: unknown names fail closed. */
    public static function provider(string $name, bool $isProduction): PaymentProviderInterface
    {
        $name = strtolower(trim($name));
        if ($name === "simulator") {
            if ($isProduction) {
                throw new RuntimeException("The payment simulator is disabled in production.");
            }
            return new SimulatorPaymentProvider();
        }
        if ($name === "opay") {
            return new OpayPaymentProvider();
        }
        throw new InvalidArgumentException("Unsupported payment provider.");
    }

    /** Which provider owns a stored reference. Null = not one of ours. */
    public static function nameForReference(string $reference): ?string
    {
        if (str_starts_with($reference, "BKM-OP-")) return "opay";
        if (str_starts_with($reference, "SIM-")) return "simulator";
        return null;
    }
}
