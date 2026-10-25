<?php
// Provider contract checks. Run from the repository root: php tests/payment-provider-contract.php
// No network, no credentials. Exit code 1 on the first failed check.
require_once __DIR__ . "/../api/services/PaymentGateway.php";

function check(bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "FAIL: " . $message . PHP_EOL); exit(1); }
    echo "PASS " . $message . PHP_EOL;
}

$sim = PaymentGateway::provider("simulator", false);
check($sim instanceof SimulatorPaymentProvider, "development selects the simulator");
check($sim->initiate("T-1", 0, "NGN", "t@example.com")["status"] === "failed", "zero amount is rejected");
check($sim->initiate("T-1", 100, "naira", "t@example.com")["status"] === "failed", "malformed currency is rejected");

foreach (["success" => 12500, "mismatch" => 12499] as $scenario => $amount) {
    $r = $sim->verify(SimulatorPaymentProvider::buildReference($scenario, 12500, "x"));
    check($r["status"] === "success" && $r["amount_minor"] === $amount && $r["currency"] === "NGN", "'$scenario' reports $amount kobo NGN");
    check(str_contains($r["message"], "SIMULATED ONLY"), "'$scenario' result is clearly labelled as simulated");
}
check($sim->verify("SIM-currency-100-x")["currency"] === "USD", "currency scenario returns a non-NGN currency");
check($sim->verify("SIM-timeout-100-x")["status"] === "unavailable", "timeout scenario is 'unavailable', never success");
check(!isset($sim->verify("SIM-malformed-100-x")["amount_minor"]), "malformed scenario omits the amount");
foreach (["pending", "failed", "cancelled"] as $s) check($sim->verify("SIM-$s-100-x")["status"] === $s, "'$s' scenario is represented");
check($sim->verify("SIM-nonsense-100-x")["status"] === "pending", "unknown scenario falls back to pending, not success");

$blocked = false;
try { PaymentGateway::provider("simulator", true); } catch (RuntimeException $e) { $blocked = true; }
check($blocked, "simulator cannot be built in production");

$unknown = false;
try { PaymentGateway::provider("unknown-provider", false); } catch (InvalidArgumentException $e) { $unknown = true; }
check($unknown, "unknown providers fail closed");

// OPay adapter with OPAY_ENABLED undefined/false: must never report success or touch the network.
$opay = PaymentGateway::provider("opay", true);
check($opay->initiate("BKM-OP-1", 100, "NGN", "t@example.com")["status"] === "unavailable", "disabled OPay initiate is 'unavailable'");
check($opay->verify("BKM-OP-1")["status"] === "unavailable", "disabled OPay verify is 'unavailable'");

check(PaymentGateway::nameForReference("BKM-OP-WEBSITE-1-1") === "opay", "BKM-OP- references belong to OPay");
check(PaymentGateway::nameForReference("SIM-success-1-x") === "simulator", "SIM- references belong to the simulator");
check(PaymentGateway::nameForReference("BKM-WEBSITE-1-1700000000") === null, "legacy Paystack-style references belong to no active provider");
echo "Payment provider contract checks passed." . PHP_EOL;
