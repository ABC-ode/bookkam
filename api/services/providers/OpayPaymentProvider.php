<?php
require_once dirname(__DIR__) . "/PaymentProviderInterface.php";
require_once dirname(__DIR__) . "/PaymentRefundInterface.php";

/**
 * OPay Checkout adapter for NIGERIA (NGN). DISABLED unless OPAY_ENABLED=true and all three credentials exist.
 *
 * Written to OPay's Nigeria documentation (documentation.opaycheckout.com; NOT the Egypt site doc.opaycheckout.com,
 * whose hosts and currency differ). NO real OPay credential or sandbox transaction was ever available to the
 * developer, so this has only been exercised against a fake local server. It is NOT verified against OPay and
 * must not be called production-ready until one real sandbox payment, one callback and one refund have been done.
 *
 * What the documentation says, and what this class does with it:
 *  - Hosts: sandbox https://testapi.opaycheckout.com, production https://liveapi.opaycheckout.com
 *  - Create (POST /api/v1/international/cashier/create): "Authorization: Bearer <PublicKey>" + "MerchantId".
 *    country NG, amount.total in KOBO (100 kobo = 1 NGN), returnUrl required, product.name + product.description required.
 *  - Status / refund / refund-status: "Authorization: Bearer <HMAC-SHA512 hex of the exact JSON body, key = SecretKey>".
 *    The exact bytes that are signed are the bytes that are sent.
 *  - Success code is "00000". Payment statuses: INITIAL, PENDING, SUCCESS, FAIL, CLOSE.
 *  - Callback: JSON {payload, sha512, type}. sha512 = HMAC-SHA3-512 (key = SecretKey) over the string
 *    {Amount:"..",Currency:"..",Reference:"..",Refunded:t|f,Status:"..",Timestamp:"..",Token:"..",TransactionID:".."}.
 *    The callback is only a HINT: the caller must still ask verify(). Duplicates must be harmless.
 *  - Refund (POST /payment/refund/create): refundWay is Original (OPay wallet / bank account payments) or BankAccount
 *    (card, bank transfer and USSD payments are returned to a bank account the customer supplies).
 *
 * Fail closed: anything unexpected (transport error, non-2xx, bad JSON, unknown code or status, wrong reference,
 * wrong amount or currency) is "unavailable" or "failed", never "success". Keys, request bodies and response
 * bodies are never logged.
 */
final class OpayPaymentProvider implements PaymentProviderInterface, PaymentRefundInterface
{
    public const HOST_SANDBOX    = "https://testapi.opaycheckout.com";
    public const HOST_PRODUCTION = "https://liveapi.opaycheckout.com";
    private const API_PREFIX     = "/api/v1/international";
    private const COUNTRY        = "NG";
    private const CURRENCY       = "NGN";
    private const CASHIER_HOSTS  = ["opaycheckout.com"];   // the documented sandbox cashier is sandboxcashier.opaycheckout.com

    // ---------------------------------------------------------------- pure helpers (unit-tested without a server)

    /** Sandbox/production host. The override is honoured ONLY in a sandbox test environment, never in production. */
    public static function baseUrl(string $env, bool $testMode, string $override = ""): string
    {
        if ($env === "production") return self::HOST_PRODUCTION;
        if ($testMode && $override !== "" && preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', $override)) return rtrim($override, "/");
        return self::HOST_SANDBOX;
    }

    /** HMAC-SHA512 hex of the exact request body (the "API Calls Signature"). */
    public static function requestSignature(string $jsonBody, string $secretKey): string
    {
        return hash_hmac("sha512", $jsonBody, $secretKey);
    }

    /** The string OPay signs for a transaction callback. */
    public static function callbackSignString(array $p): string
    {
        return sprintf('{Amount:"%s",Currency:"%s",Reference:"%s",Refunded:%s,Status:"%s",Timestamp:"%s",Token:"%s",TransactionID:"%s"}',
            $p["amount"], $p["currency"], $p["reference"], ($p["refunded"] === true || $p["refunded"] === "true") ? "t" : "f",
            $p["status"], $p["timestamp"], $p["token"] ?? "", $p["transactionId"]);
    }

    public static function callbackSignature(array $payload, string $secretKey): string
    {
        return hash_hmac("sha3-512", self::callbackSignString($payload), $secretKey);
    }

    /** https only (plain http only for loopback in a test environment) and a host the owner trusts. */
    public static function urlAllowed(string $url, bool $testMode, array $trustedSuffixes, bool $requireTrustedHost): bool
    {
        if (strlen($url) > 2000 || !filter_var($url, FILTER_VALIDATE_URL)) return false;
        $p = parse_url($url);
        $host = strtolower((string)($p["host"] ?? ""));
        if ($host === "" || isset($p["user"]) || isset($p["pass"])) return false;
        $loopback = in_array($host, ["127.0.0.1", "localhost"], true);
        $scheme = strtolower((string)($p["scheme"] ?? ""));
        if (!($scheme === "https" || ($scheme === "http" && $testMode && $loopback))) return false;
        if (!$requireTrustedHost) return true;
        if ($testMode && $loopback && in_array($host, $trustedSuffixes, true)) return true;
        foreach (array_merge(self::CASHIER_HOSTS, $trustedSuffixes) as $s) {
            $s = strtolower(trim($s));
            if ($s === "" || $s === "127.0.0.1" || $s === "localhost") continue;
            if ($host === $s || str_ends_with($host, "." . $s)) return true;
        }
        return false;
    }

    // ---------------------------------------------------------------- configuration

    private function enabled(): bool
    {
        return defined("OPAY_ENABLED") && OPAY_ENABLED
            && defined("OPAY_PUBLIC_KEY")  && OPAY_PUBLIC_KEY  !== ""
            && defined("OPAY_SECRET_KEY")  && OPAY_SECRET_KEY  !== ""
            && defined("OPAY_MERCHANT_ID") && OPAY_MERCHANT_ID !== "";
    }
    private function testMode(): bool { return defined("TEST_MODE") && TEST_MODE; }
    private function env(): string    { return defined("OPAY_ENV") ? OPAY_ENV : "sandbox"; }
    private function endpoint(string $path): string
    {
        return self::baseUrl($this->env(), $this->testMode(), defined("OPAY_BASE_URL") ? OPAY_BASE_URL : "") . self::API_PREFIX . $path;
    }
    private function trustedHosts(): array
    {
        return defined("OPAY_CASHIER_HOSTS") ? array_values(array_filter(array_map("trim", explode(",", OPAY_CASHIER_HOSTS)))) : [];
    }

    // ---------------------------------------------------------------- transport

    /** @return array{0:?array,1:string} decoded JSON (null on any failure) and a short, safe reason */
    private function post(string $path, array $body, bool $signed): array
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) return [null, "encode"];
        $bearer = $signed ? self::requestSignature($json, OPAY_SECRET_KEY) : OPAY_PUBLIC_KEY;
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => $this->endpoint($path),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer " . $bearer, "MerchantId: " . OPAY_MERCHANT_ID, "Content-Type: application/json"],
            CURLOPT_POSTFIELDS     => $json,        // exactly the bytes that were signed
        ]);
        $res  = curl_exec($curl);
        $err  = curl_error($curl);
        $http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($err !== "")                 { error_log("BOOKKAM OPay transport failure");     return [null, "transport"]; }
        if ($http < 200 || $http >= 300) { error_log("BOOKKAM OPay HTTP " . $http);          return [null, "http_" . $http]; }
        $data = json_decode((string)$res, true);
        if (!is_array($data))            { error_log("BOOKKAM OPay malformed response");     return [null, "malformed"]; }
        return [$data, ""];
    }

    /** ok | failed (the provider rejected the request for good) | unavailable (unknown outcome: do not conclude anything) */
    private function classify(array $data): string
    {
        $code = (string)($data["code"] ?? "");
        if ($code === "00000") return "ok";
        if ($code === "02000") error_log("BOOKKAM OPay authentication failed: check the keys, merchant id and OPAY_ENV");
        if (in_array($code, ["02001", "02003", "02004", "20000"], true)) return "failed";   // bad params, payMethod unsupported, duplicate reference
        return "unavailable";                                                                   // auth/config, outages, not found and every unknown code
    }

    private static function intAmount($v): ?int
    {
        if (is_int($v)) return $v;
        if (is_string($v) && preg_match('/^\d{1,12}$/', $v)) return (int)$v;
        if (is_float($v) && $v == floor($v) && $v >= 0 && $v < 1e12) return (int)$v;
        return null;
    }

    private function unavailable(string $reference, string $msg = "Payment provider is temporarily unavailable."): array
    {
        return ["status" => "unavailable", "reference" => $reference, "message" => $msg];
    }

    // ---------------------------------------------------------------- PaymentProviderInterface

    public function initiate(string $reference, int $amountMinor, string $currency, string $email, array $metadata = []): array
    {
        if (!$this->enabled()) return ["status" => "unavailable", "reference" => $reference, "message" => "OPay is not enabled."];
        if ($amountMinor <= 0 || $currency !== self::CURRENCY) return ["status" => "failed", "reference" => $reference, "message" => "Invalid amount or currency."];
        if (!preg_match('/^[A-Za-z0-9._-]{6,64}$/', $reference)) return ["status" => "failed", "reference" => $reference, "message" => "Invalid payment reference."];
        $return   = (string)($metadata["return_url"]   ?? "");
        $callback = (string)($metadata["callback_url"] ?? "");
        $cancel   = (string)($metadata["cancel_url"]   ?? $return);
        foreach ([$return, $callback, $cancel] as $u) {
            if (!self::urlAllowed($u, $this->testMode(), [], false)) return ["status" => "failed", "reference" => $reference, "message" => "Payments are not configured (return address)."];
        }
        $body = [
            "country"     => self::COUNTRY,
            "reference"   => $reference,
            "amount"      => ["total" => $amountMinor, "currency" => self::CURRENCY],
            "returnUrl"   => $return,
            "callbackUrl" => $callback,
            "cancelUrl"   => $cancel,
            "product"     => ["name" => "BOOKKAM booking", "description" => "Car rental or ride booking"],
        ];
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) $body["userInfo"] = ["userEmail" => $email];

        [$data] = $this->post("/cashier/create", $body, false);
        if ($data === null) return $this->unavailable($reference);
        $class = $this->classify($data);
        if ($class === "unavailable") return $this->unavailable($reference);
        if ($class === "failed") return ["status" => "failed", "reference" => $reference, "message" => "Payment could not be started."];

        $d = $data["data"] ?? null;
        if (!is_array($d) || ($d["reference"] ?? null) !== $reference) return $this->unavailable($reference);
        $total = self::intAmount($d["amount"]["total"] ?? null);
        if ($total !== $amountMinor || (string)($d["amount"]["currency"] ?? "") !== self::CURRENCY) {
            error_log("BOOKKAM OPay create answered with a different amount or currency");
            return ["status" => "failed", "reference" => $reference, "message" => "Payment could not be started."];
        }
        if (!in_array((string)($d["status"] ?? ""), ["INITIAL", "PENDING"], true)) return ["status" => "failed", "reference" => $reference, "message" => "Payment could not be started."];
        $url = (string)($d["cashierUrl"] ?? "");
        if (!self::urlAllowed($url, $this->testMode(), $this->trustedHosts(), true)) {
            error_log("BOOKKAM OPay cashier URL refused (not https or not an OPay host)");
            return $this->unavailable($reference, "Payment page could not be opened.");
        }
        // Initialisation is NOT payment: the status stays "pending" until verify() says otherwise.
        return ["status" => "pending", "reference" => $reference, "checkout_url" => $url, "message" => "Checkout created."];
    }

    public function verify(string $reference): array
    {
        if (!$this->enabled()) return ["status" => "unavailable", "reference" => $reference, "message" => "OPay is not enabled."];
        if (!preg_match('/^[A-Za-z0-9._-]{6,64}$/', $reference)) return $this->unavailable($reference, "Invalid payment reference.");
        [$data] = $this->post("/cashier/status", ["reference" => $reference, "country" => self::COUNTRY], true);
        if ($data === null || $this->classify($data) !== "ok" || !isset($data["data"]) || !is_array($data["data"])) {
            return $this->unavailable($reference, "Could not verify payment with provider.");
        }
        $d = $data["data"];
        // The provider-reported reference MUST be present and must be the one we asked about.
        if (($d["reference"] ?? null) !== $reference) return $this->unavailable($reference, "Provider reference mismatch.");
        $map = ["SUCCESS" => "success", "FAIL" => "failed", "CLOSE" => "cancelled", "INITIAL" => "pending", "PENDING" => "pending"];
        $status = $map[(string)($d["status"] ?? "")] ?? "unavailable";        // exact documented values only: "success" or "Paid" is not SUCCESS
        if (($d["isVoided"] ?? false) === true) $status = "cancelled";     // a voided payment is not money received
        $out = ["status" => $status, "reference" => $reference, "message" => "Provider status checked."];
        $total = self::intAmount($d["amount"]["total"] ?? null);
        if ($total !== null) $out["amount_minor"] = $total;
        if (isset($d["amount"]["currency"]) && is_string($d["amount"]["currency"])) $out["currency"] = $d["amount"]["currency"];
        if (isset($d["orderNo"]) && is_string($d["orderNo"])) $out["provider_order_no"] = $d["orderNo"];
        return $out;
    }

    // ---------------------------------------------------------------- callbacks

    /**
     * Validates a callback body. Returns ["valid"=>bool, "reason"=>string, ...hint fields when valid].
     * A valid callback is still only a hint: the caller must call verify() before changing anything.
     */
    public function verifyCallback(array $body): array
    {
        $bad = fn(string $why) => ["valid" => false, "reason" => $why];
        if (!$this->enabled()) return $bad("disabled");
        $p = $body["payload"] ?? null; $sig = $body["sha512"] ?? null; $type = $body["type"] ?? null;
        if (!is_array($p) || !is_string($sig) || !is_string($type) || !preg_match('/^[0-9a-fA-F]{128}$/', $sig)) return $bad("shape");
        if ($type !== "transaction-status") return ["valid" => false, "reason" => "type", "type" => $type];
        foreach (["amount", "currency", "reference", "status", "timestamp", "transactionId"] as $k) {
            if (!isset($p[$k]) || !is_string($p[$k]) || $p[$k] === "") return $bad("field_" . $k);
        }
        if (!array_key_exists("refunded", $p) || !(is_bool($p["refunded"]) || in_array($p["refunded"], ["true", "false"], true))) return $bad("field_refunded");
        if (isset($p["token"]) && !is_string($p["token"])) return $bad("field_token");
        $expected = self::callbackSignature($p, OPAY_SECRET_KEY);
        if (!hash_equals($expected, strtolower($sig))) { error_log("BOOKKAM OPay callback signature rejected"); return $bad("signature"); }
        return [
            "valid" => true, "type" => $type, "reference" => $p["reference"], "status" => $p["status"],
            "amount_minor" => self::intAmount($p["amount"]), "currency" => $p["currency"],
            "refunded" => ($p["refunded"] === true || $p["refunded"] === "true"),
        ];
    }

    // ---------------------------------------------------------------- PaymentRefundInterface

    public function refund(string $refundReference, string $originalReference, int $amountMinor, string $currency, array $options = []): array
    {
        if (!$this->enabled()) return ["status" => "unavailable", "reference" => $refundReference, "message" => "OPay is not enabled."];
        $fail = fn(string $m) => ["status" => "failed", "reference" => $refundReference, "message" => $m];
        if ($amountMinor <= 0 || $currency !== self::CURRENCY) return $fail("Invalid amount or currency.");
        if (!preg_match('/^[A-Za-z0-9._-]{6,64}$/', $refundReference) || !preg_match('/^[A-Za-z0-9._-]{6,64}$/', $originalReference)) return $fail("Invalid reference.");
        $way = (string)($options["refund_way"] ?? "");
        if (!in_array($way, ["Original", "BankAccount"], true)) return $fail("Choose how to refund: Original or BankAccount.");
        $callback = (string)($options["callback_url"] ?? "");
        if (!self::urlAllowed($callback, $this->testMode(), [], false)) return $fail("Refund callback address is not configured.");
        $body = [
            "amount"            => ["total" => $amountMinor, "currency" => self::CURRENCY],
            "callbackUrl"       => $callback,
            "country"           => self::COUNTRY,
            "originalReference" => $originalReference,
            "reference"         => $refundReference,
            "refundWay"         => $way,
        ];
        if ($way === "BankAccount") {
            $code = (string)($options["bank_code"] ?? ""); $acct = (string)($options["bank_account_no"] ?? "");
            if (!preg_match('/^[0-9]{3,10}$/', $code) || !preg_match('/^[0-9]{10}$/', $acct)) return $fail("A bank code and a 10-digit account number are required for a bank refund.");
            $body["receiver"] = ["bankCode" => $code, "bankAccountNo" => $acct];
        }
        $reason = trim((string)($options["reason"] ?? ""));
        if ($reason !== "") $body["refundReason"] = mb_substr($reason, 0, 200);

        [$data] = $this->post("/payment/refund/create", $body, true);
        if ($data === null) return $this->unavailable($refundReference, "Refund provider is temporarily unavailable.");
        $code = (string)($data["code"] ?? "");
        if ($code === "20000" || $code === "02004") return ["status" => "duplicate", "reference" => $refundReference, "message" => "A refund with this reference already exists: check its status."];
        if ($this->classify($data) === "failed") return $fail("Refund was rejected.");
        if ($this->classify($data) !== "ok") return $this->unavailable($refundReference, "Refund outcome unknown: check its status.");
        $d = $data["data"] ?? null;
        if (!is_array($d) || ($d["reference"] ?? null) !== $refundReference || ($d["originalReference"] ?? null) !== $originalReference) return $this->unavailable($refundReference, "Refund outcome unknown: check its status.");
        $total = self::intAmount($d["refundAmount"]["total"] ?? null);
        if ($total !== $amountMinor || (string)($d["refundAmount"]["currency"] ?? "") !== self::CURRENCY) return $this->unavailable($refundReference, "Refund amount did not match: check its status.");
        $map = ["SUCCESS" => "success", "FAIL" => "failed", "INITIAL" => "pending", "PENDING" => "pending"];
        $st = $map[(string)($d["orderStatus"] ?? "")] ?? "unavailable";
        return ["status" => $st, "reference" => $refundReference, "refund_amount_minor" => $total, "currency" => self::CURRENCY, "message" => "Refund submitted."];
    }

    public function refundStatus(string $refundReference): array
    {
        if (!$this->enabled()) return ["status" => "unavailable", "reference" => $refundReference, "message" => "OPay is not enabled."];
        if (!preg_match('/^[A-Za-z0-9._-]{6,64}$/', $refundReference)) return $this->unavailable($refundReference, "Invalid reference.");
        [$data] = $this->post("/payment/refund/query", ["reference" => $refundReference, "country" => self::COUNTRY], true);
        if ($data === null || $this->classify($data) !== "ok" || !is_array($data["data"] ?? null)) return $this->unavailable($refundReference, "Could not check the refund.");
        $d = $data["data"];
        if (($d["reference"] ?? null) !== $refundReference) return $this->unavailable($refundReference, "Provider reference mismatch.");
        $map = ["SUCCESS" => "success", "FAIL" => "failed", "INITIAL" => "pending", "PENDING" => "pending"];
        $st = $map[(string)($d["orderStatus"] ?? "")] ?? "unavailable";
        return ["status" => $st, "reference" => $refundReference, "message" => "Refund status checked."];
    }
}
