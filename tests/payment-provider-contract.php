<?php
// Run from repository root: php tests/payment-provider-contract.php
require_once __DIR__ . "/../api/services/PaymentGateway.php";

function check(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $message . PHP_EOL);
        exit(1);
    }
}

$sim = PaymentGateway::provider("simulator", false);
check($sim instanceof SimulatorPaymentProvider, "development selects simulator");
$invalid = $sim->initiate("TEST-1", 0, "NGN", "test@example.com");
check($invalid["status"] === "failed", "zero amount is rejected");

putenv("BOOKKAM_SIMULATOR_SCENARIO=pending");
$pending = $sim->verify("TEST-2");
check($pending["status"] === "pending", "pending scenario is represented");
check(str_contains($pending["message"], "SIMULATED ONLY"), "simulator response is clearly marked");

putenv("BOOKKAM_SIMULATOR_SCENARIO=success");
putenv("BOOKKAM_SIMULATOR_AMOUNT_MINOR=12500");
$success = $sim->verify("TEST-3");
check($success["status"] === "success" && $success["amount_minor"] === 12500, "success fixture carries amount in minor units");

putenv("BOOKKAM_SIMULATOR_SCENARIO=mismatch");
$mismatch = $sim->verify("TEST-4");
check($mismatch["status"] === "mismatch" && $mismatch["amount_minor"] === 12499, "mismatch scenario differs by one minor unit");

$blocked = false;
try {
    PaymentGateway::provider("simulator", true);
} catch (RuntimeException $e) {
    $blocked = true;
}
check($blocked, "simulator is blocked in production");

$unknown = false;
try {
    PaymentGateway::provider("unknown-provider", false);
} catch (InvalidArgumentException $e) {
    $unknown = true;
}
check($unknown, "unknown providers fail closed");

echo "Payment provider contract checks passed." . PHP_EOL;
