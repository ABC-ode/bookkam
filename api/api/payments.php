<?php
require_once "../config/db.php";
require_once "../config/config.php";
apiHeaders();

$input  = json_decode(file_get_contents("php://input"), true) ?? [];
$action = $_GET["action"] ?? $input["action"] ?? "";
$user   = getAuthUser($pdo);

// ── Opay helpers ────────────────────────────────────────────────────────────
// Structure only: every function below is a real, ready-to-use call shape for
// Opay's Checkout API. They are all gated behind OPAY_ENABLED (config.php),
// which defaults to false. Flip it to true and set OPAY_PUBLIC_KEY /
// OPAY_SECRET_KEY / OPAY_MERCHANT_ID in .env to go live — no other code
// changes are needed anywhere in the app.
function opayInitialize($reference, $amountKobo, $email, $callbackUrl) {
    if (!OPAY_ENABLED) return ["status" => false, "message" => "Opay is not enabled"];
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => "https://api.opaycheckout.com/api/v1/international/cashier/create",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer " . OPAY_SECRET_KEY,
            "MerchantId: " . OPAY_MERCHANT_ID,
            "Content-Type: application/json",
        ],
        CURLOPT_POSTFIELDS => json_encode([
            "reference"   => $reference,
            "amount"      => ["total" => $amountKobo, "currency" => "NGN"],
            "returnUrl"   => $callbackUrl,
            "callbackUrl" => $callbackUrl,
            "country"     => "NG",
            "payMethod"   => "BankCard",
            "userInfo"    => ["userEmail" => $email],
        ]),
    ]);
    $res = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);
    if ($err) return ["status" => false, "message" => $err];
    return json_decode($res, true) ?: ["status" => false, "message" => "Invalid Opay response"];
}

function opayVerify($reference) {
    if (!OPAY_ENABLED) return ["status" => false, "message" => "Opay is not enabled"];
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => "https://api.opaycheckout.com/api/v1/international/cashier/status",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer " . OPAY_SECRET_KEY,
            "MerchantId: " . OPAY_MERCHANT_ID,
            "Content-Type: application/json",
        ],
        CURLOPT_POSTFIELDS => json_encode(["reference" => $reference, "country" => "NG"]),
    ]);
    $res = curl_exec($curl);
    curl_close($curl);
    return json_decode($res, true) ?: ["status" => false];
}

// ── Load a booking from whichever table it lives in ────────────────────────
function loadBooking($pdo, $bookingType, $bookingId) {
    if ($bookingType === "event") {
        $s = $pdo->prepare("SELECT * FROM event_bookings WHERE id=?");
        $s->execute([$bookingId]);
        $b = $s->fetch();
        if ($b) { $b["_amount"] = (float)($b["final_price"] ?? $b["price"]); $b["_deposit"] = 0; }
        return $b;
    }
    $s = $pdo->prepare("SELECT * FROM bookings WHERE id=?");
    $s->execute([$bookingId]);
    $b = $s->fetch();
    if ($b) { $b["_amount"] = (float)$b["total_price"]; $b["_deposit"] = (float)($b["security_deposit"] ?? 0); }
    return $b;
}

function markConfirmed($pdo, $bookingType, $bookingId) {
    $table = $bookingType === "event" ? "event_bookings" : "bookings";
    $pdo->prepare("UPDATE $table SET status='confirmed' WHERE id=?")->execute([$bookingId]);
    if ($bookingType !== "event") {
        $pdo->prepare("UPDATE bookings SET payment_status='paid' WHERE id=?")->execute([$bookingId]);
    }
}

function confirmByReference($pdo, $ref) {
    $ps = $pdo->prepare("SELECT * FROM payments WHERE paystack_reference=?");
    $ps->execute([$ref]);
    $payment = $ps->fetch();
    if (!$payment || $payment["status"] === "success") return;
    $pdo->prepare("UPDATE payments SET status='success' WHERE paystack_reference=?")->execute([$ref]);
    $bookingType = $payment["booking_kind"] ?? "website";
    markConfirmed($pdo, $bookingType, $payment["booking_id"]);
}

switch ($action) {

    // ── Tells the frontend which methods are actually usable right now ─────
    case "get_methods": {
        respond([
            "paystack" => !empty(PAYSTACK_SECRET) || TEST_MODE,
            "opay"     => OPAY_ENABLED && !empty(OPAY_SECRET_KEY),
        ]);
    }

    case "initiate": {
        $bookingId   = (int)($input["booking_id"] ?? 0);
        $bookingType = ($input["booking_type"] ?? "website") === "event" ? "event" : "website";
        $method      = $input["method"] ?? "cash";
        if (!$bookingId) respondError("booking_id required");

        if ($bookingType === "website" && !$user) respondError("Unauthorised", 401);

        $booking = loadBooking($pdo, $bookingType, $bookingId);
        if (!$booking) respondError("Booking not found");
        if ($bookingType === "website" && (int)$booking["customer_id"] !== (int)$user["id"]) respondError("Booking not found");

        $amount      = $booking["_amount"];
        $deposit     = $booking["_deposit"];
        $totalCharge = $amount + $deposit;
        $email       = $bookingType === "event"
            ? ($input["email"] ?? "guest@bookkam.com")
            : ($user["email"] ?? $user["phone"] . "@bookkam.com");

        switch ($method) {

            case "wallet": {
                if ($bookingType !== "website") respondError("Wallet payment is only available for website bookings");
                if ($user["wallet_balance"] < $totalCharge) respondError("Insufficient wallet balance");
                $pdo->prepare("UPDATE users SET wallet_balance=wallet_balance-? WHERE id=?")->execute([$totalCharge, $user["id"]]);
                $pdo->prepare("INSERT INTO payments (booking_id,amount,deposit_amount,method,status,is_test,booking_kind) VALUES (?,?,?,?,'success',?,?)")
                    ->execute([$bookingId, $amount, $deposit, "wallet", TEST_MODE ? 1 : 0, $bookingType]);
                markConfirmed($pdo, $bookingType, $bookingId);
                respond(["success" => true, "method" => "wallet", "status" => "confirmed", "message" => "Payment successful"]);
                break;
            }

            case "cash": {
                // Booking record already exists (created as "pending" at submit time).
                // Cash is settled with the driver later, so it stays pending here —
                // an admin marks it confirmed once collected.
                $pdo->prepare("INSERT INTO payments (booking_id,amount,deposit_amount,method,status,is_test,booking_kind) VALUES (?,?,?,?,'pending',?,?)")
                    ->execute([$bookingId, $amount, $deposit, "cash", TEST_MODE ? 1 : 0, $bookingType]);
                respond(["success" => true, "method" => "cash", "status" => "pending", "message" => "Cash payment recorded — booking stays pending until confirmed"]);
                break;
            }

            case "test": {
                if (!TEST_MODE) respondError("Test payments are only available in development");
                $pdo->prepare("INSERT INTO payments (booking_id,amount,deposit_amount,method,status,is_test,booking_kind) VALUES (?,?,?,?,'success',1,?)")
                    ->execute([$bookingId, $amount, $deposit, "test", $bookingType]);
                markConfirmed($pdo, $bookingType, $bookingId);
                respond(["success" => true, "method" => "test", "status" => "confirmed", "message" => "Test payment simulated"]);
                break;
            }

            case "paystack": {
                if (empty(PAYSTACK_SECRET) && !TEST_MODE) respondError("Paystack is not configured");
                if (TEST_MODE) {
                    $pdo->prepare("INSERT INTO payments (booking_id,amount,deposit_amount,method,status,is_test,booking_kind) VALUES (?,?,?,?,'success',1,?)")
                        ->execute([$bookingId, $amount, $deposit, "paystack", $bookingType]);
                    markConfirmed($pdo, $bookingType, $bookingId);
                    respond(["success" => true, "method" => "paystack", "status" => "confirmed", "message" => "Test Paystack payment simulated"]);
                }
                $ref  = "BKM-" . strtoupper($bookingType) . "-" . $bookingId . "-" . time();
                $curl = curl_init();
                curl_setopt_array($curl, [
                    CURLOPT_URL            => "https://api.paystack.co/transaction/initialize",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_HTTPHEADER     => ["Authorization: Bearer " . PAYSTACK_SECRET, "Content-Type: application/json"],
                    CURLOPT_POSTFIELDS     => json_encode([
                        "email"        => $email,
                        "amount"       => (int)round($totalCharge * 100),
                        "reference"    => $ref,
                        "callback_url" => "https://bookkam.com/?paystack_return=1&booking_type=$bookingType",
                    ]),
                ]);
                $res  = curl_exec($curl);
                $data = json_decode($res, true);
                curl_close($curl);
                if (!empty($data["status"])) {
                    $pdo->prepare("INSERT INTO payments (booking_id,amount,deposit_amount,method,status,paystack_reference,booking_kind) VALUES (?,?,?,?,'pending',?,?)")
                        ->execute([$bookingId, $amount, $deposit, "paystack", $ref, $bookingType]);
                    respond(["success" => true, "authorization_url" => $data["data"]["authorization_url"], "reference" => $ref]);
                }
                respondError("Payment initiation failed");
                break;
            }

            case "opay": {
                if (!OPAY_ENABLED) respondError("Opay is not available yet — please choose another payment method");
                $ref  = "BKM-OP-" . strtoupper($bookingType) . "-" . $bookingId . "-" . time();
                $data = opayInitialize($ref, (int)round($totalCharge * 100), $email, "https://bookkam.com/?opay_return=1&booking_type=$bookingType");
                if (($data["code"] ?? "") === "00000") {
                    $pdo->prepare("INSERT INTO payments (booking_id,amount,deposit_amount,method,status,paystack_reference,booking_kind) VALUES (?,?,?,?,'pending',?,?)")
                        ->execute([$bookingId, $amount, $deposit, "opay", $ref, $bookingType]);
                    respond(["success" => true, "authorization_url" => $data["data"]["cashierUrl"] ?? null, "reference" => $ref]);
                }
                respondError($data["message"] ?? "Opay payment initiation failed");
                break;
            }

            default: respondError("Invalid payment method");
        }
        break;
    }

    // ── Paystack webhook ─────────────────────────────────────────────────────
    case "webhook": {
        $payload   = file_get_contents("php://input");
        $signature = $_SERVER["HTTP_X_PAYSTACK_SIGNATURE"] ?? "";
        if (!TEST_MODE && (!$signature || hash_hmac("sha512", $payload, PAYSTACK_SECRET) !== $signature)) {
            http_response_code(401); exit;
        }
        $event = json_decode($payload, true);
        if (($event["event"] ?? "") === "charge.success") {
            confirmByReference($pdo, $event["data"]["reference"]);
        }
        http_response_code(200);
        echo "OK";
        exit;
    }

    // ── Opay webhook — structure only, inert unless OPAY_ENABLED ───────────
    case "opay_webhook": {
        if (!OPAY_ENABLED) { http_response_code(200); echo "OK"; exit; }
        $payload = json_decode(file_get_contents("php://input"), true) ?? [];
        $ref     = $payload["reference"] ?? ($payload["payload"]["reference"] ?? "");
        $status  = $payload["status"] ?? ($payload["payload"]["status"] ?? "");
        if ($ref && $status === "SUCCESS") {
            confirmByReference($pdo, $ref);
        }
        http_response_code(200);
        echo "OK";
        exit;
    }

    case "verify": {
        $ref = $_GET["reference"] ?? $input["reference"] ?? "";
        if (!$ref) respondError("Reference required");
        if (TEST_MODE) respond(["status" => "success", "message" => "Test payment verified"]);

        if (str_starts_with($ref, "BKM-OP-")) {
            if (!OPAY_ENABLED) respondError("Opay is not enabled");
            $data = opayVerify($ref);
            if (($data["data"]["status"] ?? "") === "SUCCESS") {
                confirmByReference($pdo, $ref);
                respond(["status" => "success"]);
            }
            respondError("Payment not verified");
        }

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL            => "https://api.paystack.co/transaction/verify/$ref",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer " . PAYSTACK_SECRET],
        ]);
        $res  = curl_exec($curl);
        $data = json_decode($res, true);
        curl_close($curl);
        if (($data["data"]["status"] ?? "") === "success") {
            confirmByReference($pdo, $ref);
            respond(["status" => "success"]);
        }
        respondError("Payment not verified");
    }

    case "top_up_wallet": {
        if (!$user) respondError("Unauthorised", 401);
        $amount = (float)($input["amount"] ?? 0);
        if ($amount <= 0) respondError("Invalid amount");
        if (TEST_MODE) {
            $pdo->prepare("UPDATE users SET wallet_balance=wallet_balance+? WHERE id=?")->execute([$amount, $user["id"]]);
            $s = $pdo->prepare("SELECT wallet_balance FROM users WHERE id=?");
            $s->execute([$user["id"]]);
            $u = $s->fetch();
            respond(["success" => true, "new_balance" => $u["wallet_balance"], "message" => "Wallet topped up"]);
        }
        respondError("Use Paystack or Opay for production top-up");
    }

    case "get_wallet": {
        if (!$user) respondError("Unauthorised", 401);
        $s = $pdo->prepare("SELECT wallet_balance FROM users WHERE id=?");
        $s->execute([$user["id"]]);
        $u = $s->fetch();
        respond(["balance" => $u["wallet_balance"]]);
    }

    default: respondError("Invalid action");
}
?>
