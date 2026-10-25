<?php
require_once "../config/db.php";
require_once "../config/config.php";
apiHeaders();

$input  = json_decode(file_get_contents("php://input"), true) ?? [];
$action = $_GET["action"] ?? $input["action"] ?? "";
$user   = getAuthUser($pdo);

require_once __DIR__ . "/../services/PaymentGateway.php";

// Provider calls live in api/services (interface + OPay adapter + dev-only simulator).
// OPay is NOT verified for production: keep OPAY_ENABLED=false (see docs/payments.md).
function providerFor(string $name) {
    return PaymentGateway::provider($name, !TEST_MODE);
}

function jsonOut(array $data, int $code) { http_response_code($code); echo json_encode($data); exit; }

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
        // The payment row is marked successful before this function is called.
        // Counting successful payments here would therefore reject the very payment
        // being settled. The conditional booking update is the idempotency guard.
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

// Turn a provider verify() result into a booking/payment outcome. Returns [httpCode, body].
function applyProviderResult($pdo, $ref, array $res) {
    $status = $res["status"] ?? "unavailable";
    if ($status === "success") {
        $r = confirmByReference($pdo, $ref, $res["amount_minor"] ?? null, $res["currency"] ?? null);
        if (in_array($r, ["confirmed", "already_success", "ignored"], true)) return [200, ["status" => "success"]];
        if ($r === "not_found")         return [404, ["error" => "Payment reference not found", "status" => "unknown"]];
        if ($r === "amount_mismatch")   return [409, ["error" => "Payment amount does not match the booking", "status" => "mismatch"]];
        if ($r === "currency_mismatch") return [409, ["error" => "Payment currency does not match", "status" => "mismatch"]];
        if ($r === "refunded")          return [409, ["error" => "Payment was received but could not be applied to this booking; it was returned to your wallet", "status" => "refunded"]];
        return [500, ["error" => "Payment could not be confirmed", "status" => "error"]];
    }
    // Failed / cancelled / pending / unavailable never change a booking.
    // (payments.status has no 'failed' value we can rely on: the live enum is not in the repo.)
    if ($status === "failed" || $status === "cancelled") {
        error_log("BOOKKAM payment not completed ref=$ref provider_status=$status");
        return [402, ["error" => "Payment was not completed", "status" => $status]];
    }
    if ($status === "pending") return [202, ["status" => "pending", "message" => "Payment is still being processed"]];
    return [502, ["error" => "Could not verify payment right now. Please try again shortly.", "status" => "unavailable"]];
}

function assertPayable($booking, $bookingType) {
    if (in_array($booking["status"], ["cancelled", "completed"], true)) respondError("This booking can no longer be paid");
    if ($bookingType !== "event" && ($booking["payment_status"] ?? "") === "paid") respondError("This booking is already paid");
    // Event bookings carry no payment_status: they are payable only while still pending
    // (a confirmed one was either paid already or confirmed by an admin).
    if ($bookingType === "event" && $booking["status"] !== "pending") respondError("This booking can no longer be paid");
}

// Called ONLY with a result obtained from the provider's own verification API (never from a
// browser claim or a raw webhook body). Idempotent: a payment row is claimed exactly once,
// inside the same transaction that settles the booking (or credits the wallet), so the row
// can never read "success" without the matching financial/booking change.
// Policy for a late or duplicate-payment: if the booking can no longer take the payment
// (cancelled / already paid) the amount is credited to the customer's wallet with a
// notification (event bookings have no wallet: logged for manual refund).
// The paid amount must EQUAL the expected total and the currency must be NGN; a missing
// currency or amount is treated as a mismatch (fail closed).
function confirmByReference($pdo, $ref, $paidMinor, $currency) {
    $ps = $pdo->prepare("SELECT * FROM payments WHERE provider_reference=?");
    $ps->execute([$ref]);
    $payment = $ps->fetch();
    if (!$payment) return "not_found";
    if ($payment["status"] === "success") return "already_success";

    if ($currency !== "NGN") {
        error_log("BOOKKAM payment currency mismatch ref=$ref");
        return "currency_mismatch";
    }
    $expectedKobo = (int)round(((float)$payment["amount"] + (float)$payment["deposit_amount"]) * 100);
    if ($paidMinor === null || (int)$paidMinor !== $expectedKobo) {
        error_log("BOOKKAM payment amount mismatch ref=$ref paid=" . var_export($paidMinor, true) . " expected=$expectedKobo");
        return "amount_mismatch";
    }

    $bookingType = $payment["booking_kind"] ?? "website";
    $pdo->beginTransaction();
    try {
        $claim = $pdo->prepare("UPDATE payments SET status='success' WHERE provider_reference=? AND status<>'success'");
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
            "opay"     => OPAY_ENABLED,
            "wallet_top_up" => TEST_MODE, "test_mode" => TEST_MODE, // production top-up needs a verified provider flow (not built)
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
                $dup = $pdo->prepare("SELECT 1 FROM payments WHERE booking_id=? AND booking_kind=? AND method='cash' AND status='pending'");
                $dup->execute([$bookingId, $bookingType]);
                if (!$dup->fetch()) {
                    $pdo->prepare("INSERT INTO payments (booking_id,amount,deposit_amount,method,status,is_test,booking_kind) VALUES (?,?,?,?,'pending',?,?)")
                        ->execute([$bookingId, $amount, $deposit, "cash", TEST_MODE ? 1 : 0, $bookingType]);
                }
                respond(["success" => true, "method" => "cash", "status" => "pending", "message" => "Cash payment recorded — booking stays pending until confirmed"]);
                break;
            }

            case "test": {
                if (!TEST_MODE) respondError("Test payments are only available in development");
                instantSuccess($pdo, $bookingType, $bookingId, $amount, $deposit, "test");
                respond(["success" => true, "method" => "test", "status" => "confirmed", "message" => "Test payment simulated"]);
                break;
            }


            case "opay": {
                if (!OPAY_ENABLED) respondError("Opay is not available yet — please choose another payment method", 503);
                $ref = "BKM-OP-" . strtoupper($bookingType) . "-" . $bookingId . "-" . time() . bin2hex(random_bytes(3));
                $r   = providerFor("opay")->initiate($ref, (int)round($totalCharge * 100), "NGN", $email,
                    ["callback_url" => "https://bookkam.com/?opay_return=1&booking_type=$bookingType"]);
                if (($r["status"] ?? "") === "pending" && !empty($r["checkout_url"])) {
                    $pdo->prepare("INSERT INTO payments (booking_id,amount,deposit_amount,method,status,provider_reference,booking_kind) VALUES (?,?,?,?,'pending',?,?)")
                        ->execute([$bookingId, $amount, $deposit, "opay", $ref, $bookingType]);
                    // A checkout URL is NOT proof of payment: the booking stays pending until verify/webhook.
                    respond(["success" => true, "authorization_url" => $r["checkout_url"], "reference" => $ref]);
                }
                respondError($r["message"] ?? "Opay payment initiation failed", ($r["status"] ?? "") === "unavailable" ? 503 : 400);
                break;
            }

            // Development-only: drives the REAL verify/settlement path with a scripted provider result.
            case "simulator": {
                if (!TEST_MODE) respondError("Payment simulator is only available in development", 403);
                $scenario = strtolower((string)($input["scenario"] ?? "success"));
                if (!in_array($scenario, SimulatorPaymentProvider::SCENARIOS, true)) respondError("Unknown simulator scenario");
                $ref = SimulatorPaymentProvider::buildReference($scenario, (int)round($totalCharge * 100), $bookingType . $bookingId . "-" . bin2hex(random_bytes(4)));
                $pdo->prepare("INSERT INTO payments (booking_id,amount,deposit_amount,method,status,is_test,provider_reference,booking_kind) VALUES (?,?,?,?,'pending',1,?,?)")
                    ->execute([$bookingId, $amount, $deposit, "test", $ref, $bookingType]);
                respond(["success" => true, "reference" => $ref, "message" => "SIMULATED ONLY: call verify to settle"]);
                break;
            }

            default: respondError("Invalid payment method");
        }
        break;
    }

    // ── Opay webhook — inert unless OPAY_ENABLED. The body is an untrusted hint: only the
    // reference is read from it, and the real status/amount/currency come from OPay's own API.
    // Provider outage => 503 so the provider retries; unknown references are acknowledged.
    case "opay_webhook": {
        if (!OPAY_ENABLED) { http_response_code(200); echo "OK"; exit; }
        $payload = json_decode(file_get_contents("php://input"), true);
        $ref     = is_array($payload) ? (string)($payload["reference"] ?? ($payload["payload"]["reference"] ?? "")) : "";
        if ($ref === "" || PaymentGateway::nameForReference($ref) !== "opay") { http_response_code(200); echo "OK"; exit; }
        [$code, $body] = applyProviderResult($pdo, $ref, providerFor("opay")->verify($ref));
        if ($code >= 500 || ($body["status"] ?? "") === "unavailable") { http_response_code(503); exit; }
        http_response_code(200);
        echo "OK";
        exit;
    }

    // Customer-facing status check (also what the return page should call).
    case "verify": {
        $ref = (string)($_GET["reference"] ?? $input["reference"] ?? "");
        if ($ref === "" || strlen($ref) > 255) respondError("Reference required");
        $name = PaymentGateway::nameForReference($ref);
        if ($name === null || ($name === "simulator" && !TEST_MODE)) respondError("Unknown payment reference", 404);
        if ($name === "opay" && !OPAY_ENABLED) respondError("Opay is not enabled", 503);
        [$code, $body] = applyProviderResult($pdo, $ref, providerFor($name)->verify($ref));
        jsonOut($body, $code);
    }

    case "top_up_wallet": {
        if (!$user) respondError("Unauthorised", 401);
        // No verified provider-backed top-up exists, so production never credits a wallet
        // from this endpoint, whatever the client sends.
        if (!TEST_MODE) respondError("Wallet top-ups are temporarily unavailable. Please contact support.", 503);

        $amount = filter_var($input["amount"] ?? null, FILTER_VALIDATE_FLOAT);
        if ($amount === false || !is_finite((float)$amount) || $amount <= 0 || $amount > 1000000) {
            respondError("Amount must be greater than zero and no more than ₦1,000,000", 422);
        }
        $key = (string)($input["idempotency_key"] ?? bin2hex(random_bytes(12)));
        if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $key)) respondError("Invalid idempotency_key", 422);
        $ref = "test_wallet_" . (int)$user["id"] . "_" . $key;

        // Test-only credit: atomic, labelled (method 'test', is_test=1, 'test_wallet_' reference),
        // idempotent per key (also enforced by the unique index from migration 007).
        $pdo->beginTransaction();
        try {
            $dup = $pdo->prepare("SELECT 1 FROM payments WHERE provider_reference=?");
            $dup->execute([$ref]);
            $credited = false;
            if (!$dup->fetch()) {
                $u = $pdo->prepare("UPDATE users SET wallet_balance=wallet_balance+? WHERE id=?");
                $u->execute([(float)$amount, $user["id"]]);
                if ($u->rowCount() !== 1) throw new RuntimeException("Wallet owner not found");
                $pdo->prepare("INSERT INTO payments (booking_id, booking_kind, amount, deposit_amount, method, status, is_test, provider_reference)
                    VALUES (0, 'topup', ?, 0, 'test', 'success', 1, ?)")->execute([(float)$amount, $ref]);
                $credited = true;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("BOOKKAM test wallet top-up failed: " . $e->getMessage());
            respondError("Could not top up wallet", 500);
        }
        $s = $pdo->prepare("SELECT wallet_balance FROM users WHERE id=?");
        $s->execute([$user["id"]]);
        $u = $s->fetch();
        respond(["success" => true, "credited" => $credited, "new_balance" => $u["wallet_balance"],
                 "message" => $credited ? "Test wallet topped up" : "Duplicate request ignored"]);
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
