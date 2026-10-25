<?php
require_once __DIR__ . "/PaymentProviderInterface.php";
require_once __DIR__ . "/providers/SimulatorPaymentProvider.php";
require_once __DIR__ . "/providers/OpayPaymentProvider.php";

/** Provider-specific API details stay outside booking and wallet code. */
final class PaymentGateway
{
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
}
