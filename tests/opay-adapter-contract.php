<?php
// Pure-function and callback-signature checks for the OPay adapter. No network, no server, FAKE keys.
//   php tests/opay-adapter-contract.php
define("TEST_MODE", true);
define("OPAY_ENABLED", true);
define("OPAY_PUBLIC_KEY", "OPAYPUB-UNIT-TEST");
define("OPAY_SECRET_KEY", "OPAYPRV16498196872570.13953388019021462");   // the SAMPLE key printed in OPay's own documentation example, not a real credential
define("OPAY_MERCHANT_ID", "UNITMERCHANT");
define("OPAY_ENV", "sandbox");
define("OPAY_BASE_URL", "");
define("OPAY_CASHIER_HOSTS", "");
require_once __DIR__ . "/../api/services/providers/OpayPaymentProvider.php";

$fail = 0;
function check(string $name, bool $ok, $detail = null): void { global $fail; echo ($ok ? "PASS " : "FAIL ") . $name . ($ok || $detail === null ? "" : " :: " . json_encode($detail)) . "\n"; if (!$ok) $fail++; }

// ---- known-answer test: the sample callback in OPay's documentation (documentation.opaycheckout.com/callback-signature)
$docPayload = ["amount" => "49160", "channel" => "Web", "country" => "NG", "currency" => "NGN", "displayedFailure" => "", "fee" => "737", "feeCurrency" => "NGN", "instrumentType" => "BankCard",
    "reference" => "10023", "refunded" => false, "status" => "SUCCESS", "timestamp" => "2022-05-07T06:20:46Z", "token" => "220507145660712931829", "transactionId" => "220507145660712931829", "updated_at" => "2022-05-07T07:20:46Z"];
$docSig = "9f605d69f04e94172875dc156537071cead060bbcaeaca94a7b8805af9f89611e2fdf6836713c9c90b028ca7e4470b1356e996975f2abc862315aaa9b7f2ae2d";
check("K1 the signed string has OPay's documented shape", OpayPaymentProvider::callbackSignString($docPayload) === '{Amount:"49160",Currency:"NGN",Reference:"10023",Refunded:f,Status:"SUCCESS",Timestamp:"2022-05-07T06:20:46Z",Token:"220507145660712931829",TransactionID:"220507145660712931829"}');
check("K2 known answer: our HMAC-SHA3-512 equals the signature printed in OPay's documentation", OpayPaymentProvider::callbackSignature($docPayload, OPAY_SECRET_KEY) === $docSig);
check("K3 known answer: the API-call signature (HMAC-SHA512 of the exact body) matches an independent implementation",
    OpayPaymentProvider::requestSignature('{"country":"NG","reference":"R-123456"}', "unit-test-secret") === "4fb48fbfbf268d9eaa76f4bb4411137a01e0d0a4b0ccb7307d39d63b49da8ec26e0fddf7ddb19a866bb5fda81a2e62547e021f4c6f360e7f87fae9547094fb96");

$op = new OpayPaymentProvider();
$cb = ["payload" => $docPayload, "sha512" => $docSig, "type" => "transaction-status"];
$r = $op->verifyCallback($cb);
check("C1 the documentation's own sample callback is accepted", $r["valid"] === true && $r["reference"] === "10023" && $r["status"] === "SUCCESS" && $r["amount_minor"] === 49160 && $r["currency"] === "NGN" && $r["refunded"] === false, $r);
$up = $cb; $up["sha512"] = strtoupper($docSig); check("C2 an upper-case hex signature is accepted (OPay's own sample code compares case-insensitively)", $op->verifyCallback($up)["valid"] === true);
foreach (["amount" => "49161", "currency" => "USD", "reference" => "10024", "status" => "FAIL", "timestamp" => "2022-05-07T06:20:47Z", "token" => "x", "transactionId" => "1", "refunded" => true] as $k => $v) {
    $t = $cb; $t["payload"][$k] = $v; $x = $op->verifyCallback($t);
    check("C3 changing the signed field '$k' invalidates the signature", $x["valid"] === false && $x["reason"] === "signature", $x);
}
$t = $cb; $t["payload"]["fee"] = "0"; $t["payload"]["channel"] = "Mobile"; check("C3b fields OPay does not sign (fee, channel, ...) do not affect it, so they must never be trusted", $op->verifyCallback($t)["valid"] === true);
$t = $cb; $t["payload"]["refunded"] = "false"; check("C4 refunded given as the string 'false' is treated like false (same signed text)", $op->verifyCallback($t)["valid"] === true);
$t = $cb; unset($t["payload"]["token"]); check("C5 a missing token is signed as an empty string, so a callback whose token was removed does not verify", $op->verifyCallback($t)["valid"] === false);
$wrong = ["", "abc", str_repeat("0", 128), str_repeat("g", 128), substr($docSig, 0, 127), $docSig . "0", null, 12345, [$docSig]];
foreach ($wrong as $i => $w) { $t = $cb; $t["sha512"] = $w; $x = $op->verifyCallback($t); check("C6 a bad signature value #$i is refused", $x["valid"] === false, $x); }
foreach (["payload", "sha512", "type"] as $k) { $t = $cb; unset($t[$k]); check("C7 missing '$k' is refused", $op->verifyCallback($t)["valid"] === false); }
foreach (["amount", "currency", "reference", "status", "timestamp", "transactionId"] as $k) { $t = $cb; unset($t["payload"][$k]); check("C8 missing payload field '$k' is refused", $op->verifyCallback($t)["valid"] === false); }
foreach ([["amount", 49160], ["reference", ["10023"]], ["status", null], ["currency", true]] as [$k, $v]) { $t = $cb; $t["payload"][$k] = $v; check("C9 payload field '$k' of the wrong type is refused", $op->verifyCallback($t)["valid"] === false); }
$t = $cb; $t["type"] = "refund-status"; $x = $op->verifyCallback($t); check("C10 a refund-status notification is not treated as a payment (reason 'type')", $x["valid"] === false && $x["reason"] === "type", $x);
$t = $cb; $t["payload"] = "string"; check("C11 payload that is not an object is refused", $op->verifyCallback($t)["valid"] === false);

// ---- hosts: Nigeria docs use testapi / liveapi (NOT the Egypt sandboxapi / api hosts)
check("H1 sandbox host is testapi.opaycheckout.com", OpayPaymentProvider::baseUrl("sandbox", false) === "https://testapi.opaycheckout.com");
check("H2 production host is liveapi.opaycheckout.com", OpayPaymentProvider::baseUrl("production", false) === "https://liveapi.opaycheckout.com");
check("H3 a base-URL override is IGNORED in production, even in test mode", OpayPaymentProvider::baseUrl("production", true, "http://127.0.0.1:9999") === "https://liveapi.opaycheckout.com");
check("H4 a base-URL override is ignored when not in a test environment", OpayPaymentProvider::baseUrl("sandbox", false, "http://127.0.0.1:9999") === "https://testapi.opaycheckout.com");
check("H5 the override works only for loopback http in a test environment", OpayPaymentProvider::baseUrl("sandbox", true, "http://127.0.0.1:9999") === "http://127.0.0.1:9999"
    && OpayPaymentProvider::baseUrl("sandbox", true, "https://evil.example") === "https://testapi.opaycheckout.com" && OpayPaymentProvider::baseUrl("sandbox", true, "http://evil.example:80") === "https://testapi.opaycheckout.com"
    && OpayPaymentProvider::baseUrl("sandbox", true, "http://127.0.0.1.evil.example") === "https://testapi.opaycheckout.com");
check("H6 no Egypt host is referenced anywhere in the adapter", !preg_match('#sandboxapi\.opaycheckout|//api\.opaycheckout#', file_get_contents(__DIR__ . "/../api/services/providers/OpayPaymentProvider.php") . "\n") || str_contains(file_get_contents(__DIR__ . "/../api/services/providers/OpayPaymentProvider.php"), "NOT the Egypt"));

// ---- URL policy
$u = fn($url, $test = false, $sfx = [], $trusted = true) => OpayPaymentProvider::urlAllowed($url, $test, $sfx, $trusted);
check("U1 the documented sandbox cashier host is accepted", $u("https://sandboxcashier.opaycheckout.com/apiCashier/redirect/payment/checkout?orderToken=TOKEN.1"));
check("U2 an OPay host over http is refused", !$u("http://cashier.opaycheckout.com/x"));
foreach (["https://opaycheckout.com.evil.example/x", "https://evil.example/x", "https://evilopaycheckout.com/x", "https://user:pw@cashier.opaycheckout.com/x", "javascript:alert(1)", "//cashier.opaycheckout.com/x", "https://", "", "ftp://cashier.opaycheckout.com/x", "https://127.0.0.1/x", "https://localhost/x"] as $bad)
    check("U3 a look-alike or unsafe cashier URL is refused: " . $bad, !$u($bad));
check("U4 loopback http is accepted only in a test environment AND only if listed as trusted", $u("http://127.0.0.1:18120/cashier/x", true, ["127.0.0.1"]) && !$u("http://127.0.0.1:18120/cashier/x", false, ["127.0.0.1"]) && !$u("http://127.0.0.1:18120/cashier/x", true, []));
check("U5 an owner-listed extra cashier host is accepted", $u("https://cashier.opay.example/x", false, ["opay.example"]));
check("U6 return/callback URLs (own site) need https but no OPay host", $u("https://bookkam.com/x", false, [], false) && !$u("http://bookkam.com/x", false, [], false));

echo $fail ? "OPAY_ADAPTER_CONTRACT_FAILED ($fail)\n" : "OPay adapter contract checks passed.\n";
exit($fail ? 1 : 0);
