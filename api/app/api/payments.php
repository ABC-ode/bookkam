<?php
require_once "../config/db.php";
require_once "../config/config.php";
apiHeaders();

$input  = json_decode(file_get_contents("php://input"), true) ?? [];
$action = $_GET["action"] ?? $input["action"] ?? "";
$user   = getAuthUser($pdo);

switch ($action) {

    case "initiate": {
        if (!$user) respondError("Unauthorised", 401);
        $bookingId = (int)($input["booking_id"] ?? 0);
        $method    = $input["method"] ?? "cash";
        if (!$bookingId) respondError("booking_id required");

        $bs = $pdo->prepare("SELECT * FROM bookings WHERE id=? AND customer_id=?");
        $bs->execute([$bookingId,$user["id"]]);
        $booking = $bs->fetch();
        if (!$booking) respondError("Booking not found");

        $amount  = $booking["total_price"];
        $deposit = $booking["security_deposit"] ?? 0;
        $totalCharge = $amount + $deposit;

        // Wallet payment
        if ($method === "wallet") {
            if ($user["wallet_balance"] < $totalCharge) respondError("Insufficient wallet balance");
            $pdo->prepare("UPDATE users SET wallet_balance=wallet_balance-? WHERE id=?")->execute([$totalCharge,$user["id"]]);
            $pdo->prepare("INSERT INTO payments (booking_id,amount,deposit_amount,method,status,is_test) VALUES (?,?,?,?,'success',?)")
                ->execute([$bookingId,$amount,$deposit,"wallet",TEST_MODE?1:0]);
            $pdo->prepare("UPDATE bookings SET status='confirmed',payment_status='paid' WHERE id=?")->execute([$bookingId]);
            // Credit driver earnings (minus commission)
            if ($booking["driver_id"]) {
                $net = $amount - $booking["commission_amount"];
                $pdo->prepare("UPDATE drivers SET pending_earnings=pending_earnings+? WHERE id=?")->execute([$net,$booking["driver_id"]]);
            }
            respond(["success"=>true,"method"=>"wallet","message"=>"Payment successful"]);
        }

        // Cash
        if ($method === "cash") {
            $pdo->prepare("INSERT INTO payments (booking_id,amount,deposit_amount,method,status,is_test) VALUES (?,?,?,?,'pending',?)")
                ->execute([$bookingId,$amount,$deposit,"cash",TEST_MODE?1:0]);
            respond(["success"=>true,"method"=>"cash","message"=>"Cash payment recorded"]);
        }

        // Test simulation
        if ($method === "test" && TEST_MODE) {
            $pdo->prepare("INSERT INTO payments (booking_id,amount,deposit_amount,method,status,is_test) VALUES (?,?,?,?,'success',1)")
                ->execute([$bookingId,$amount,$deposit,"test"]);
            $pdo->prepare("UPDATE bookings SET status='confirmed',payment_status='paid' WHERE id=?")->execute([$bookingId]);
            if ($booking["driver_id"]) {
                $net = $amount - $booking["commission_amount"];
                $pdo->prepare("UPDATE drivers SET pending_earnings=pending_earnings+? WHERE id=?")->execute([$net,$booking["driver_id"]]);
            }
            respond(["success"=>true,"method"=>"test","message"=>"Test payment simulated"]);
        }

        // Paystack DVA — get or create driver's dedicated account
        if ($method === "dva" && !TEST_MODE) {
            if (!$booking["driver_id"]) respondError("No driver assigned");
            // Get driver's DVA
            $ds = $pdo->prepare("SELECT d.*,u.name,u.phone,u.email FROM drivers d JOIN users u ON d.user_id=u.id WHERE d.id=?");
            $ds->execute([$booking["driver_id"]]);
            $driver = $ds->fetch();

            if (!$driver["dva_account_number"]) {
                // Create DVA for this driver
                $curl = curl_init();
                curl_setopt_array($curl,[
                    CURLOPT_URL            => "https://api.paystack.co/dedicated_account",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_HTTPHEADER     => ["Authorization: Bearer ".PAYSTACK_SECRET,"Content-Type: application/json"],
                    CURLOPT_POSTFIELDS     => json_encode([
                        "customer"           => $driver["paystack_customer_code"] ?? null,
                        "preferred_bank"     => "wema-bank",
                        "first_name"         => explode(" ",$driver["name"])[0],
                        "last_name"          => explode(" ",$driver["name"])[1] ?? "",
                        "phone"              => "+".$driver["phone"],
                        "email"              => $driver["email"] ?? $driver["phone"]."@bookkam.com",
                    ]),
                ]);
                $res  = curl_exec($curl);
                $data = json_decode($res,true);
                curl_close($curl);
                if ($data["status"]) {
                    $acct = $data["data"]["account_number"];
                    $bank = $data["data"]["bank"]["name"];
                    $pdo->prepare("UPDATE drivers SET dva_account_number=?,dva_bank_name=? WHERE id=?")->execute([$acct,$bank,$driver["id"]]);
                    respond(["dva"=>["account_number"=>$acct,"bank_name"=>$bank,"account_name"=>$driver["name"],"amount"=>$totalCharge]]);
                }
                respondError("Could not create payment account");
            }

            respond(["dva"=>[
                "account_number" => $driver["dva_account_number"],
                "bank_name"      => $driver["dva_bank_name"],
                "account_name"   => $driver["name"],
                "amount"         => $totalCharge,
            ]]);
        }

        // Paystack card
        if ($method === "card") {
            if (TEST_MODE) {
                $pdo->prepare("INSERT INTO payments (booking_id,amount,deposit_amount,method,status,is_test) VALUES (?,?,?,?,'success',1)")
                    ->execute([$bookingId,$amount,$deposit,"card"]);
                $pdo->prepare("UPDATE bookings SET status='confirmed',payment_status='paid' WHERE id=?")->execute([$bookingId]);
                respond(["success"=>true,"method"=>"card","message"=>"Test card payment simulated"]);
            }
            $ref  = "BKM-".$bookingId."-".time();
            $curl = curl_init();
            curl_setopt_array($curl,[
                CURLOPT_URL            => "https://api.paystack.co/transaction/initialize",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_HTTPHEADER     => ["Authorization: Bearer ".PAYSTACK_SECRET,"Content-Type: application/json"],
                CURLOPT_POSTFIELDS     => json_encode([
                    "email"        => $user["email"] ?? $user["phone"]."@bookkam.com",
                    "amount"       => $totalCharge * 100,
                    "reference"    => $ref,
                    "callback_url" => "https://yourdomain.com/?paystack_return=1",
                ]),
            ]);
            $res  = curl_exec($curl);
            $data = json_decode($res,true);
            curl_close($curl);
            if ($data["status"]) {
                $pdo->prepare("INSERT INTO payments (booking_id,amount,deposit_amount,method,status,paystack_reference) VALUES (?,?,?,?,'pending',?)")
                    ->execute([$bookingId,$amount,$deposit,"card",$ref]);
                respond(["authorization_url"=>$data["data"]["authorization_url"],"reference"=>$ref]);
            }
            respondError("Payment initiation failed");
        }

        respondError("Invalid payment method");
    }

    // ── Paystack webhook ──────────────────────────────────────────────────────
    case "webhook": {
        $payload   = file_get_contents("php://input");
        $signature = $_SERVER["HTTP_X_PAYSTACK_SIGNATURE"] ?? "";
        if (!TEST_MODE && hash_hmac("sha512",$payload,PAYSTACK_SECRET) !== $signature) {
            http_response_code(401); exit;
        }
        $event = json_decode($payload,true);
        if ($event["event"] === "charge.success") {
            $ref = $event["data"]["reference"];
            $ps  = $pdo->prepare("SELECT * FROM payments WHERE paystack_reference=?");
            $ps->execute([$ref]);
            $payment = $ps->fetch();
            if ($payment && $payment["status"] !== "success") {
                $pdo->prepare("UPDATE payments SET status='success' WHERE paystack_reference=?")->execute([$ref]);
                $bs = $pdo->prepare("SELECT * FROM bookings WHERE id=?");
                $bs->execute([$payment["booking_id"]]);
                $booking = $bs->fetch();
                if ($booking) {
                    $pdo->prepare("UPDATE bookings SET status='confirmed',payment_status='paid' WHERE id=?")->execute([$booking["id"]]);
                    if ($booking["driver_id"]) {
                        $net = $booking["total_price"] - $booking["commission_amount"];
                        $pdo->prepare("UPDATE drivers SET pending_earnings=pending_earnings+? WHERE id=?")->execute([$net,$booking["driver_id"]]);
                    }
                }
            }
        }
        http_response_code(200);
        echo "OK";
        exit;
    }

    case "verify": {
        $ref = $_GET["reference"] ?? $input["reference"] ?? "";
        if (!$ref) respondError("Reference required");
        if (TEST_MODE) respond(["status"=>"success","message"=>"Test payment verified"]);
        $curl = curl_init();
        curl_setopt_array($curl,[
            CURLOPT_URL            => "https://api.paystack.co/transaction/verify/$ref",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer ".PAYSTACK_SECRET],
        ]);
        $res  = curl_exec($curl);
        $data = json_decode($res,true);
        curl_close($curl);
        if ($data["data"]["status"] === "success") {
            $pdo->prepare("UPDATE payments SET status='success' WHERE paystack_reference=?")->execute([$ref]);
            respond(["status"=>"success"]);
        }
        respondError("Payment not verified");
    }

    case "top_up_wallet": {
        if (!$user) respondError("Unauthorised", 401);
        $amount = (float)($input["amount"] ?? 0);
        if ($amount <= 0) respondError("Invalid amount");
        if (TEST_MODE) {
            $pdo->prepare("UPDATE users SET wallet_balance=wallet_balance+? WHERE id=?")->execute([$amount,$user["id"]]);
            $s = $pdo->prepare("SELECT wallet_balance FROM users WHERE id=?");
            $s->execute([$user["id"]]);
            $u = $s->fetch();
            respond(["success"=>true,"new_balance"=>$u["wallet_balance"],"message"=>"Wallet topped up"]);
        }
        respondError("Use Paystack for production top-up");
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
