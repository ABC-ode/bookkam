<?php
require_once dirname(__DIR__) . "/PaymentProviderInterface.php";

/**
 * OPay adapter. NOT VERIFIED FOR PRODUCTION.
 *
 * The endpoint paths, header/signing scheme, amount units, response codes and status names
 * below are the assumptions that were already in the codebase; they have NOT been validated
 * against current official OPay merchant documentation or a sandbox. Keep OPAY_ENABLED=false
 * until they are (see docs/payments.md). The adapter fails closed:
 *  - disabled/unconfigured            => "unavailable"
 *  - transport error, non-2xx, bad JSON, unexpected "code" => "unavailable" (never "success")
 *  - unknown provider status          => "unavailable"
 *  - a success answer without amount/currency is passed through as-is; the settlement layer
 *    rejects it (amount and currency must match what we expect).
 */
final class OpayPaymentProvider implements PaymentProviderInterface
{
    private const BASE = "https://api.opaycheckout.com/api/v1/international/cashier/";

    private function enabled(): bool
    {
        return defined("OPAY_ENABLED") && OPAY_ENABLED
            && defined("OPAY_SECRET_KEY") && OPAY_SECRET_KEY !== ""
            && defined("OPAY_MERCHANT_ID") && OPAY_MERCHANT_ID !== "";
    }

    /** @return array{0:?array,1:string} decoded JSON (null on any failure) and a safe reason */
    private function post(string $path, array $body): array
    {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => self::BASE . $path,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer " . OPAY_SECRET_KEY,
                "MerchantId: " . OPAY_MERCHANT_ID,
                "Content-Type: application/json",
            ],
            CURLOPT_POSTFIELDS     => json_encode($body),
        ]);
        $res  = curl_exec($curl);
        $err  = curl_error($curl);
        $http = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        // Never log the request, the response body or credentials.
        if ($err !== "")                 { error_log("BOOKKAM OPay transport failure"); return [null, "timeout_or_transport"]; }
        if ($http < 200 || $http >= 300) { error_log("BOOKKAM OPay HTTP " . $http);     return [null, "http_" . $http]; }
        $data = json_decode((string)$res, true);
        if (!is_array($data))            { error_log("BOOKKAM OPay malformed response"); return [null, "malformed"]; }
        return [$data, ""];
    }

    public function initiate(string $reference, int $amountMinor, string $currency, string $email, array $metadata = []): array
    {
        if (!$this->enabled()) {
            return ["status" => "unavailable", "reference" => $reference, "message" => "OPay is not enabled."];
        }
        if ($amountMinor <= 0 || $currency !== "NGN") {
            return ["status" => "failed", "reference" => $reference, "message" => "Invalid amount or currency."];
        }
        $callback = (string)($metadata["callback_url"] ?? "");
        [$data, $why] = $this->post("create", [
            "reference"   => $reference,
            "amount"      => ["total" => $amountMinor, "currency" => $currency],
            "returnUrl"   => $callback,
            "callbackUrl" => $callback,
            "country"     => "NG",
            "payMethod"   => "BankCard",
            "userInfo"    => ["userEmail" => $email],
        ]);
        if ($data === null) {
            return ["status" => "unavailable", "reference" => $reference, "message" => "Payment provider is temporarily unavailable."];
        }
        $url = $data["data"]["cashierUrl"] ?? "";
        if (($data["code"] ?? "") === "00000" && is_string($url) && str_starts_with($url, "https://") && filter_var($url, FILTER_VALIDATE_URL)) {
            // Initialisation is NOT payment: the status stays "pending" until verify() says otherwise.
            return ["status" => "pending", "reference" => $reference, "checkout_url" => $url, "message" => "Checkout created."];
        }
        return ["status" => "failed", "reference" => $reference, "message" => "Payment could not be started."];
    }

    public function verify(string $reference): array
    {
        if (!$this->enabled()) {
            return ["status" => "unavailable", "reference" => $reference, "message" => "OPay is not enabled."];
        }
        [$data, $why] = $this->post("status", ["reference" => $reference, "country" => "NG"]);
        if ($data === null || ($data["code"] ?? "") !== "00000" || !isset($data["data"]) || !is_array($data["data"])) {
            return ["status" => "unavailable", "reference" => $reference, "message" => "Could not verify payment with provider."];
        }
        $d = $data["data"];
        // Provider-reported reference must be the one we asked about.
        if (isset($d["reference"]) && $d["reference"] !== $reference) {
            return ["status" => "unavailable", "reference" => $reference, "message" => "Provider reference mismatch."];
        }
        $map = [
            "SUCCESS" => "success", "FAIL" => "failed", "FAILED" => "failed",
            "CLOSE" => "cancelled", "CLOSED" => "cancelled", "CANCEL" => "cancelled", "CANCELLED" => "cancelled",
            "INITIAL" => "pending", "PENDING" => "pending", "PROCESSING" => "pending",
        ];
        $status = $map[strtoupper((string)($d["status"] ?? ""))] ?? "unavailable";
        $out = ["status" => $status, "reference" => $reference, "message" => "Provider status checked."];
        if (isset($d["amount"]["total"]) && is_numeric($d["amount"]["total"])) $out["amount_minor"] = (int)$d["amount"]["total"];
        if (isset($d["amount"]["currency"]) && is_string($d["amount"]["currency"])) $out["currency"] = strtoupper($d["amount"]["currency"]);
        return $out;
    }
}
