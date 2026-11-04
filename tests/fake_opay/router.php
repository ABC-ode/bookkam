<?php
/**
 * FAKE OPay (Nigeria) for local tests ONLY. Not OPay, not a mock of its behaviour beyond what the public documentation
 * (documentation.opaycheckout.com) states. It checks the things a real OPay would reject, records every request so the tests
 * can inspect headers and bodies, and can be told to misbehave.  php -S 127.0.0.1:PORT tests/fake_opay/router.php
 * Env: FAKE_OPAY_STATE (json file), FAKE_OPAY_PUBLIC, FAKE_OPAY_SECRET, FAKE_OPAY_MERCHANT, FAKE_OPAY_PORT
 */
$STATE = getenv("FAKE_OPAY_STATE") ?: sys_get_temp_dir() . "/fake_opay_state.json";
$PUB = getenv("FAKE_OPAY_PUBLIC"); $SEC = getenv("FAKE_OPAY_SECRET"); $MID = getenv("FAKE_OPAY_MERCHANT"); $PORT = getenv("FAKE_OPAY_PORT") ?: "18120";

function load($f) { $s = @file_get_contents($f); $d = $s ? json_decode($s, true) : null; return is_array($d) ? $d : ["orders" => [], "refunds" => [], "log" => [], "behavior" => []]; }
function save($f, $d) { file_put_contents($f, json_encode($d), LOCK_EX); }
function out($data, $http = 200) { http_response_code($http); header("Content-Type: application/json"); echo json_encode($data); exit; }
function err($code, $msg) { out(["code" => $code, "message" => $msg]); }

$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$raw  = file_get_contents("php://input");
$st   = load($STATE);
$hdr  = ["authorization" => $_SERVER["HTTP_AUTHORIZATION"] ?? "", "merchantid" => $_SERVER["HTTP_MERCHANTID"] ?? ""];
$body = json_decode($raw, true);

if ($path === "/__control") {
    $c = $body ?: [];
    switch ($c["op"] ?? "") {
        case "reset":    $st = ["orders" => [], "refunds" => [], "log" => [], "behavior" => []]; break;
        case "set_order": foreach (($c["fields"] ?? []) as $k => $v) $st["orders"][$c["reference"]][$k] = $v; break;
        case "behavior": $st["behavior"][$c["endpoint"]] = $c["mode"]; break;
        case "set_refund": foreach (($c["fields"] ?? []) as $k => $v) $st["refunds"][$c["reference"]][$k] = $v; break;
        case "dump": out($st);
    }
    save($STATE, $st); out(["ok" => true]);
}
if (preg_match('#^/cashier/#', $path)) { echo "FAKE CASHIER"; exit; }

$ep = null;
foreach (["cashier/create" => "create", "cashier/status" => "status", "payment/refund/create" => "refund", "payment/refund/query" => "refund_query"] as $suffix => $name) {
    if ($path === "/api/v1/international/" . $suffix) $ep = $name;
}
if ($ep === null) { http_response_code(404); exit; }

$authOk = false; $secretOnCreate = false;
if ($ep === "create") {
    $authOk = $hdr["authorization"] === "Bearer " . $PUB;
    $secretOnCreate = $hdr["authorization"] === "Bearer " . $SEC;
} else {
    $authOk = hash_equals("Bearer " . hash_hmac("sha512", $raw, $SEC), $hdr["authorization"]);   // the EXACT raw body is what gets signed
}
$st["log"][] = ["ep" => $ep, "auth" => $hdr["authorization"], "merchant" => $hdr["merchantid"], "raw" => $raw, "auth_ok" => $authOk && $hdr["merchantid"] === $MID, "secret_on_create" => $secretOnCreate];
$beh = $st["behavior"][$ep] ?? "normal";
save($STATE, $st);

if ($beh === "http500")   { http_response_code(500); echo "boom"; exit; }
if ($beh === "malformed") { echo "<html>not json"; exit; }
if ($beh === "html200")   { echo "<html>maintenance</html>"; exit; }
if ($beh === "slow")      { sleep(20); }
if (strpos($beh, "code:") === 0) err(substr($beh, 5), "scripted error");
if (!$authOk || $hdr["merchantid"] !== $MID) err("02000", "authentication failed.");
if (!is_array($body)) err("02001", "request params not valid.");
if (($body["country"] ?? "") !== "NG") err("02001", "request params not valid.");

$save = function () use (&$st, $STATE) { save($STATE, $st); };

if ($ep === "create") {
    $a = $body["amount"] ?? [];
    $okParams = is_string($body["reference"] ?? null) && $body["reference"] !== "" && is_int($a["total"] ?? null) && $a["total"] > 0 && ($a["currency"] ?? "") === "NGN"
        && is_string($body["returnUrl"] ?? null) && $body["returnUrl"] !== "" && is_string($body["product"]["name"] ?? null) && is_string($body["product"]["description"] ?? null);
    if (!$okParams) err("02001", "request params not valid.");
    $ref = $body["reference"];
    if (isset($st["orders"][$ref])) err("02004", "the payment reference(merchant order number) already exists.");
    $st["orders"][$ref] = ["status" => "INITIAL", "total" => $a["total"], "currency" => "NGN", "orderNo" => "2" . random_int(10000000000000000, 99999999999999999), "callbackUrl" => $body["callbackUrl"] ?? null, "returnUrl" => $body["returnUrl"]];
    $o = $st["orders"][$ref]; $save();
    $shown = $o; foreach (($st["behavior"]["create_override"] ?? []) as $k => $v) $shown[$k] = $v;
    out(["code" => "00000", "message" => "SUCCESSFUL", "data" => ["reference" => $ref, "orderNo" => $o["orderNo"],
        "cashierUrl" => ($st["behavior"]["cashier_url"] ?? ("http://127.0.0.1:" . $PORT . "/cashier/" . $ref)), "status" => $shown["status"],
        "amount" => ["total" => $shown["total"], "currency" => $shown["currency"]], "vat" => ["total" => 0, "currency" => "NGN"]]]);
}

if ($ep === "status") {
    $ref = $body["reference"] ?? "";
    if (!isset($st["orders"][$ref])) err("02006", "payment not found.");
    $o = $st["orders"][$ref];
    $d = ["reference" => $o["reportRef"] ?? $ref, "orderNo" => $o["orderNo"], "status" => $o["status"], "vat" => ["total" => 0, "currency" => $o["currency"]],
          "amount" => ["total" => $o["reportTotal"] ?? $o["total"], "currency" => $o["reportCurrency"] ?? $o["currency"]], "createTime" => 1633788085000];
    if (($o["reportCurrency"] ?? null) === "__omit__") unset($d["amount"]["currency"]);
    if (($o["reportTotal"] ?? null) === "__omit__") unset($d["amount"]["total"]);
    if (isset($o["voided"])) $d["isVoided"] = $o["voided"];
    if (in_array($o["status"], ["FAIL", "CLOSE"], true)) { $d["failureCode"] = "X1"; $d["failureReason"] = "scripted"; }
    if (isset($o["noReference"])) unset($d["reference"]);
    out(["code" => "00000", "message" => "SUCCESSFUL", "data" => $d]);
}

if ($ep === "refund") {
    $ref = $body["reference"] ?? ""; $orig = $body["originalReference"] ?? ""; $a = $body["amount"] ?? [];
    $way = $body["refundWay"] ?? "";
    if (!in_array($way, ["Original", "BankAccount"], true) || !is_string($body["callbackUrl"] ?? null) || $ref === "" || !is_int($a["total"] ?? null) || ($a["currency"] ?? "") !== "NGN") err("02001", "request params not valid.");
    if ($way === "BankAccount" && (!isset($body["receiver"]["bankCode"]) || !isset($body["receiver"]["bankAccountNo"]))) err("02001", "request params not valid.");
    if (!isset($st["orders"][$orig]) || $st["orders"][$orig]["status"] !== "SUCCESS") err("02006", "original payment not found.");
    if ($a["total"] > $st["orders"][$orig]["total"]) err("02001", "request params not valid.");
    if (isset($st["refunds"][$ref])) err("20000", "Duplicate merchant order number.");
    $status = $st["behavior"]["refund_result"] ?? "SUCCESS";
    $st["refunds"][$ref] = ["originalReference" => $orig, "total" => $a["total"], "currency" => "NGN", "orderStatus" => $status, "way" => $way, "receiver" => $body["receiver"] ?? null];
    $save();
    $rep = $st["behavior"]["refund_report"] ?? [];
    out(["code" => "00000", "message" => "SUCCESSFUL", "data" => ["reference" => $rep["reference"] ?? $ref, "originalReference" => $orig, "orderNo" => "3" . random_int(10000000000000000, 99999999999999999),
        "originalOrderNo" => $st["orders"][$orig]["orderNo"], "country" => "NG", "refundAmount" => ["currency" => "NGN", "total" => $rep["total"] ?? $a["total"]], "orderStatus" => $status]]);
}

if ($ep === "refund_query") {
    $ref = $body["reference"] ?? "";
    if (!isset($st["refunds"][$ref])) err("02006", "not found.");
    $r = $st["refunds"][$ref];
    out(["code" => "00000", "message" => "SUCCESSFUL", "data" => ["reference" => $ref, "originalReference" => $r["originalReference"], "orderNo" => "3000", "originalOrderNo" => "2000", "country" => "NG",
        "amount" => ["currency" => "NGN", "total" => $r["total"]], "orderStatus" => $r["orderStatus"]]]);
}
