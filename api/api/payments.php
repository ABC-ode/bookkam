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

// Atomically move a booking to "confirmed/paid". Returns false (and changes nothing) if the
// booking is cancelled, completed, or already paid — so a payment can never revive a
// cancelled booking or be applied twice.
function settleBooking($pdo, $bookingType, $bookingId) {
    if ($bookingType === "event") {
        $c = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE booking_id=? AND booking_kind='event' AND status='success'");
        $c->execute([$bookingId]);
        if ((int)$c->fetchColumn() > 0) return false;
        $u = $pdo->prepare("UPDATE event_bookings SET status='confirmed' WHERE id=? AND status='pending'");
        $u->execute([$bookingId]);
        return $u->rowCount() === 1;
    }
    $u = $pdo->prepare("UPDATE bookings SET status=CASE WHEN status='pending' THEN 'confirmed' ELSE status END, payment_status='paid'
        WHERE id=? AND status IN ('pending','confirmed','active') AND (payment_status IS NULL OR payment_status<>'paid')");
    $u->execute([$bookingId]);
    return $u->rowCount() === 1;
}

function instantSuccess($pdo, $bookingType, $bookingId, $amount, $deposit, $method) {
    $pdo->beginTransaction();
    try {
        if (!settleBooking($pdo, $bookingType, $bookingId)) { $pdo->rollBack(); respondError("This booking can no longer be paid"); }
        $pdo->prepare("INSERT INTO payments (booking_id,amount,deposit_amount,method,status,is_test,booking_kind) VALUES (?,?,?,?,'success',1,?)")
            ->execute([$bookingId, $amount, $deposit, $method, $bookingType]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respondError("Payment could not be completed", 500);
    }
}

function assertPayable($booking, $bookingType) {
    if (in_array($booking["status"], ["cancelled", "completed"], true)) respondError("This booking can no longer be paid");
    if ($bookingType !== "event" && ($booking["payment_status"] ?? "") === "paid") respondError("This booking is already paid");
}

// Called from the Paystack/Opay webhook and from verify. Idempotent: a payment row is
// claimed exactly once. $paidKobo (from the provider) must cover the amount we expect.
// If the booking can no longer take the payment (cancelled / already paid) the money is
// returned to the customer's wallet instead of being lost or double-applied.
function confirmByReference($pdo, $ref, $paidKobo = null) {
    $ps = $pdo->prepare("SELECT * FROM payments WHERE paystack_reference=?");
    $ps->execute([$ref]);
    $payment = $ps->fetch();
    if (!$payment || $payment["status"] === "success") return "ignored";

    $expectedKobo = (int)round(((float)$payment["amount"] + (float)$payment["deposit_amount"]) * 100);
    if ($paidKobo !== null && (int)$paidKobo < $expectedKobo) {
        error_log("BOOKKAM payment amount mismatch ref=$ref paid=$paidKobo expected=$expectedKobo");
        return "amount_mismatch";
    }

    $bookingType = $payment["booking_kind"] ?? "website";
    $pdo->beginTransaction();
    try {
        $claim = $pdo->prepare("UPDATE payments SET status='success' WHERE paystack_reference=? AND status<>'success'");
        $claim->execute([$ref]);
        if ($claim->rowCount() !== 1) { $pdo->rollBack(); return "ignored"; }
        $result = "confirmed";
        if (!settleBooking($pdo, $bookingType, $payment["booking_id"])) {
            $result = "refunded";
            if ($bookingType !== "event") {
                $b = $pdo->prepare("SELECT customer_id FROM bookings WHERE id=?");
                $b->execute([$payment["booking_id"]]);
                $cid = $b->fetchColumn();
                if ($cid) {
                    $refund = (float)$payment["amount"] + (float)$payment["deposit_amount"];
                    $pdo->prepare("UPDATE users SET wallet_balance=wallet_balance+? WHERE id=?")->execute([$refund, $cid]);
                    $pdo->prepare("INSERT INTO notifications (user_id,title,body,type) VALUES (?,?,?,'payment')")
                        ->execute([$cid, "Payment returned to wallet", "Your payment for booking #{$payment['booking_id']} arrived after the booking was cancelled or already paid, so it was credited to your BOOKKAM wallet.", ]);
                }
            } else {
                error_log("BOOKKAM manual refund needed: event booking {$payment['booking_id']} ref=$ref");
            }
        }
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("BOOKKAM confirmByReference failed ref=$ref: " . $e->getMessage());
        return "error";
    }
}

switch ($action) {

    // ── Tells the frontend which methods are actually usable right now ─────
    case "get_methods": {
        respond([
            "paystack" => false,
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

        assertPayable($booking, $bookingType);

        $amount      = $booking["_amount"];
        $deposit     = $booking["_deposit"];
        $totalCharge = $amount + $deposit;
        $email       = $bookingType === "event"
            ? ($input["email"] ?? "guest@bookkam.com")
            : ($user["email"] ?? $user["phone"] . "@bookkam.com");

        switch ($method) {

            case "wallet": {
                if ($bookingType !== "website") respondError("Wallet payment is only available for website bookings");
                // One transaction: claim the booking, debit atomically, record the payment.
                // Any failure rolls everything back, so the customer is never charged twice
                // or charged for a booking that did not get confirmed.
                $pdo->beginTransaction();
                try {
                    if (!settleBooking($pdo, $bookingType, $bookingId)) { $pdo->rollBack(); respondError("This booking can no longer be paid"); }
                    $debit = $pdo->prepare("UPDATE users SET wallet_balance=wallet_balance-? WHERE id=? AND wallet_balance>=?");
                    $debit->execute([$totalCharge, $user["id"], $totalCharge]);
                    if ($debit->rowCount() !== 1) { $pdo->rollBack(); respondError("Insufficient wallet balance"); }
                    $pdo->prepare("INSERT INTO payments (booking_id,amount,deposit_amount,method,status,is_test,booking_kind) VALUES (?,?,?,?,'success',?,?)")
                        ->execute([$bookingId, $amount, $deposit, "wallet", TEST_MODE ? 1 : 0, $bookingType]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    respondError("Payment could not be completed", 500);
                }
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
                instantSuccess($pdo, $bookingType, $bookingId, $amount, $deposit, "test");
                respond(["success" => true, "method" => "test", "status" => "confirmed", "message" => "Test payment simulated"]);
                break;
            }

            case "paystack": {
                // Paystack is frozen for new payments. Keep webhook/verification
                // handlers below for any historical or in-flight transactions.
                respondError("Paystack is disabled. Please choose another payment method.", 410);
                break;
            }

            case "opay": {
                if (!OPAY_ENABLED) respondError("Opay is not available yet — please choose another payment method");
                $ref  = "BKM-OP-" . strtoupper($bookingType) . "-" . $bookingId . "-" . time() . bin2hex(random_bytes(3));
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
        // Signature is mandatory whenever a Paystack secret is configured (even in TEST_MODE).
        // It may be skipped only when NO secret exists AND we are in a dev environment.
        $mustSign = !empty(PAYSTACK_SECRET) || !TEST_MODE;
        if ($mustSign && (empty(PAYSTACK_SECRET) || !$signature || !hash_equals(hash_hmac("sha512", $payload, PAYSTACK_SECRET), $signature))) {
            http_response_code(401); exit;
        }
        $event = json_decode($payload, true);
        if (($event["event"] ?? "") === "charge.success") {
            $d = $event["data"] ?? [];
            if (!empty($d["reference"]) && (($d["currency"] ?? "NGN") === "NGN")) {
                $r = confirmByReference($pdo, $d["reference"], isset($d["amount"]) ? (int)$d["amount"] : ($mustSign ? 0 : null));
                if ($r === "error") { http_response_code(500); exit; } // let Paystack retry
            }
        }
        http_response_code(200);
        echo "OK";
        exit;
    }

    // ── Opay webhook — inert unless OPAY_ENABLED. The payload is never trusted:
    // the status is re-checked with Opay's own status API before anything is confirmed.
    case "opay_webhook": {
        if (!OPAY_ENABLED) { http_response_code(200); echo "OK"; exit; }
        $payload = json_decode(file_get_contents("php://input"), true) ?? [];
        $ref     = $payload["reference"] ?? ($payload["payload"]["reference"] ?? "");
        if ($ref && str_starts_with($ref, "BKM-OP-")) {
            $data = opayVerify($ref);
            if (($data["data"]["status"] ?? "") === "SUCCESS") {
                $amt = $data["data"]["amount"]["total"] ?? null;
                $r = confirmByReference($pdo, $ref, $amt !== null ? (int)$amt : 0);
                if ($r === "error") { http_response_code(500); exit; }
            }
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
                confirmByReference($pdo, $ref, (int)($data["data"]["amount"]["total"] ?? 0));
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
            confirmByReference($pdo, $ref, (int)($data["data"]["amount"] ?? 0));
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
        respondError("Use OPay for production top-up");
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
