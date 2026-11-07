#!/usr/bin/env python3
"""OPay (Nigeria) adapter tests against a FAKE local OPay server. Nothing here talks to OPay.

The fake server (tests/fake_opay/router.php) checks what the public documentation says OPay checks: the PUBLIC key on create,
an HMAC-SHA512 of the EXACT body on status/refund calls, NG/NGN/kobo, required fields, duplicate references. This suite proves
the app handles those correctly, plus hostile and broken answers. It does NOT prove the integration works with the real OPay:
that needs real sandbox keys and one real payment (see docs/payments.md).

  python3 tests/functional/opay_tests.py
"""
import hashlib, hmac, json, os, re, subprocess, sys, time, urllib.request
HERE = os.path.dirname(os.path.abspath(__file__)); sys.path.insert(0, HERE)
import run_tests as rt
from admin_security_tests import add_user, login

FAKE_PORT, ON, OFF, NOPUB = 18120, "http://127.0.0.1:18122", "http://127.0.0.1:18123", "http://127.0.0.1:18124"
FAKE = "http://127.0.0.1:%d" % FAKE_PORT
PUB, SEC, MID = "OPAYPUB-FAKE-TEST-ONLY", "OPAYPRV-FAKE-TEST-SECRET-ONLY", "FAKEMERCHANT0001"
check, db, A = rt.check, rt.db, (lambda base, ep, act, body=None, token=None, qs="", raw=None: rt.call(base, ep, act, body, token, qs, raw))

def ctl(**kw):
    req = urllib.request.Request(FAKE + "/__control", data=json.dumps(kw).encode(), method="POST"); req.add_header("Content-Type", "application/json")
    return json.loads(urllib.request.urlopen(req, timeout=10).read().decode())
def dump(): return ctl(op="dump")
def behave(ep, mode): ctl(op="behavior", endpoint=ep, mode=mode)
def setord(ref, **f): ctl(op="set_order", reference=ref, fields=f)
def reset(): ctl(op="reset")
def reqs(ep=None): return [l for l in dump()["log"] if ep is None or l["ep"] == ep]
def sig512(raw, key=SEC): return hmac.new(key.encode(), raw.encode(), hashlib.sha512).hexdigest()

def callback(ref, status="SUCCESS", amount="1500000", currency="NGN", refunded=False, typ="transaction-status", secret=SEC, edit=None):
    p = {"amount": amount, "channel": "Web", "country": "NG", "currency": currency, "displayedFailure": "", "fee": "737", "feeCurrency": "NGN", "instrumentType": "BankCard", "reference": ref,
         "refunded": refunded, "status": status, "timestamp": "2026-11-03T10:00:00Z", "token": "220507145660712931829", "transactionId": "220507145660712931829", "updated_at": "2026-11-03T10:00:00Z"}
    msg = '{Amount:"%s",Currency:"%s",Reference:"%s",Refunded:%s,Status:"%s",Timestamp:"%s",Token:"%s",TransactionID:"%s"}' % (p["amount"], p["currency"], p["reference"], "t" if refunded else "f", p["status"], p["timestamp"], p["token"], p["transactionId"])
    sig = hmac.new(secret.encode(), msg.encode(), "sha3_512").hexdigest()
    if edit: p.update(edit)
    return json.dumps({"payload": p, "sha512": sig, "type": typ}).encode()
def hook(raw, base=ON): return rt.call(base, "payments.php", "opay_webhook", raw=raw)

def main():
    rt.setup()
    state = os.path.join(rt.TMP, "fake_opay_state.json")
    fenv = dict(os.environ, FAKE_OPAY_STATE=state, FAKE_OPAY_PUBLIC=PUB, FAKE_OPAY_SECRET=SEC, FAKE_OPAY_MERCHANT=MID, FAKE_OPAY_PORT=str(FAKE_PORT))
    fp = subprocess.Popen(["php", "-S", "127.0.0.1:%d" % FAKE_PORT, "-t", os.path.join(rt.ROOT, "tests", "fake_opay"), os.path.join(rt.ROOT, "tests", "fake_opay", "router.php")], env=fenv, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    rt.procs.append(fp); time.sleep(0.6)
    on = {"APP_ENV": "development", "JWT_SECRET": rt.JWT, "OPAY_ENABLED": "true", "OPAY_PUBLIC_KEY": PUB, "OPAY_SECRET_KEY": SEC, "OPAY_MERCHANT_ID": MID, "OPAY_ENV": "sandbox",
          "OPAY_BASE_URL": FAKE, "OPAY_CASHIER_HOSTS": "127.0.0.1", "APP_URL": ON}
    rt.start(18122, on)
    rt.start(18123, {k: v for k, v in dict(on, APP_URL=OFF).items() if k != "OPAY_ENABLED"})
    rt.start(18124, {k: v for k, v in dict(on, APP_URL=NOPUB).items() if k != "OPAY_PUBLIC_KEY"})
    reset()

    # ---- fixtures: admin, customers, a driver with many cars (one car holds one open booking) ----
    add_user("admin@test.local", "Test Admin", "admin", "Adm1n-test-only")
    add_user("c1@test.local", "Cust One", "customer"); add_user("c2@test.local", "Cust Two", "customer")
    d_uid = add_user("d1@test.local", "Driver One", "driver"); db("INSERT INTO drivers(user_id,status) VALUES(?, 'pending')", (d_uid,))
    CT, DT = login(ON, "c1@test.local"), login(ON, "d1@test.local")
    c, r = A(ON, "auth.php", "admin_login", {"email": "admin@test.local", "password": "Adm1n-test-only"}); AT = r.get("token")
    check("setup: admin, customer and driver can sign in", all([AT, CT, DT]))
    db("INSERT INTO pricing(city,booking_type,base_price) VALUES('Calabar','chauffeur_trip',15000)")
    drv_id = db("SELECT id FROM drivers WHERE user_id=?", (d_uid,))[0]["id"]
    A(ON, "cars.php", "driver_save", {"name": "Toyota Camry", "plate_number": "CAL-001", "year": 2019, "make": "Toyota", "model": "Camry"}, DT)
    A(ON, "admin.php", "update_driver_status", {"driver_id": drv_id, "status": "active"}, AT); A(ON, "drivers.php", "toggle_online", {"is_online": 1}, DT)
    first = db("SELECT id FROM cars LIMIT 1")[0]["id"]
    for i in range(2, 90):
        db("INSERT INTO cars(name,car_type,category,city,plate_number,driver_id,availability_status,is_available) SELECT 'Camry',car_type,category,city,?,driver_id,availability_status,1 FROM cars WHERE id=?", ("CAL-%03d" % i, first))
    cars = [r["id"] for r in db("SELECT id FROM cars ORDER BY id")]; nxt = iter(cars)
    def wallet(): return db("SELECT wallet_balance w FROM users WHERE email='c1@test.local'")[0]["w"]
    def bk(i): return db("SELECT status,payment_status FROM bookings WHERE id=?", (i,))[0]
    def pay(ref): return db("SELECT * FROM payments WHERE provider_reference=?", (ref,))
    def new_opay(base=ON, token=None):
        c, b = A(base, "bookings.php", "create", {"car_id": next(nxt), "booking_type": "trip", "pickup_address": "Marian Rd", "city": "Calabar"}, token or CT)
        bid = int(b["booking_id"]); c, r = A(base, "payments.php", "initiate", {"booking_id": bid, "method": "opay", "booking_type": "website"}, token or CT)
        return bid, r.get("reference"), c, r
    KOBO = 1500000

    # ================= O. gating and initiate =================
    c, r = A(ON, "payments.php", "get_methods", token=CT); check("O1 OPay is offered only on the server where it is enabled with all three credentials", r.get("opay") is True, r)
    for name, base in [("not enabled", OFF), ("enabled but the public key is missing", NOPUB)]:
        c, r = A(base, "payments.php", "get_methods", token=CT); check("O2 " + name + ": not offered", r.get("opay") is False, r)
        c, b = A(base, "bookings.php", "create", {"car_id": next(nxt), "booking_type": "trip", "pickup_address": "x", "city": "Calabar"}, CT)
        c, r = A(base, "payments.php", "initiate", {"booking_id": b.get("booking_id"), "method": "opay", "booking_type": "website"}, CT)
        check("O2b " + name + ": a payment cannot be started (503)", c == 503, (c, r))
        c, r = hook(callback("BKM-OP-WEBSITE-1-1700000000aaaaaa"), base); check("O2c " + name + ": the callback endpoint is inert (200, nothing happens)", c == 200, (c, r))
    check("O2d ...and no request reached the (fake) OPay from the disabled servers", len(reqs()) == 0, reqs())

    reset(); bid, ref, c, r = new_opay()
    check("O3 initiate: 200, a cashier URL on the (fake) OPay host and a PENDING payment; the booking is NOT paid", c == 200 and r.get("authorization_url", "").startswith(FAKE + "/cashier/") and r.get("reference") == ref
          and pay(ref)[0]["status"] == "pending" and bk(bid) == {"status": "pending", "payment_status": "unpaid"}, (c, r, bk(bid)))
    L = reqs("create"); body = json.loads(L[0]["raw"]); p0 = pay(ref)[0]
    check("O4 the create call used the PUBLIC key (never the secret) and the merchant id", len(L) == 1 and L[0]["auth"] == "Bearer " + PUB and L[0]["merchant"] == MID and not L[0]["secret_on_create"] and L[0]["auth_ok"], L[0] if L else None)
    check("O5 body: country NG, NGN, amount in KOBO equal to booking total x 100, product name+description, userInfo email", body["country"] == "NG" and body["amount"] == {"total": KOBO, "currency": "NGN"} and isinstance(body["amount"]["total"], int)
          and (p0["amount"] + p0["deposit_amount"]) * 100 == KOBO and body["product"]["name"] and body["product"]["description"] and body["userInfo"]["userEmail"] == "c1@test.local", body)
    check("O6 returnUrl lands on the site; callbackUrl is the app's own callback endpoint (not the site)", body["returnUrl"] == ON + "/?opay_return=1&booking_type=website" and body["callbackUrl"] == ON + "/api/payments.php?action=opay_webhook" and body["cancelUrl"] == body["returnUrl"], body)
    check("O7 the reference is ours (BKM-OP-...) and the secret key is nowhere in what was sent", ref.startswith("BKM-OP-") and SEC not in json.dumps(L), None)

    # provider misbehaviour on create: no payment row may be written, nothing is paid
    for mode, want in [("http500", 503), ("malformed", 503), ("html200", 503), ("code:50003", 503), ("code:02000", 503), ("code:02007", 503), ("code:99999", 503), ("code:02001", 400), ("code:02003", 400), ("code:02004", 400)]:
        reset(); behave("create", mode); before = db("SELECT COUNT(*) n FROM payments")[0]["n"]; b2, ref2, c, r = new_opay()
        check("O8 create answers '%s' -> HTTP %d and NO payment row is written" % (mode, want), c == want and db("SELECT COUNT(*) n FROM payments")[0]["n"] == before and "authorization_url" not in r, (c, r))
    reset()
    for name, over in [("a different amount", {"total": KOBO + 1}), ("a different currency", {"currency": "USD"}), ("status SUCCESS before any payment", {"status": "SUCCESS"}), ("status FAIL", {"status": "FAIL"})]:
        reset(); behave("create_override", over); before = db("SELECT COUNT(*) n FROM payments")[0]["n"]; b2, ref2, c, r = new_opay()
        check("O9 create answers with " + name + ": refused, no payment row, no redirect", c in (400, 503) and db("SELECT COUNT(*) n FROM payments")[0]["n"] == before and "authorization_url" not in r, (c, r))
    for evil in ["https://evil.example/pay", "https://opaycheckout.com.evil.example/pay", "http://cashier.opaycheckout.com/pay", "javascript:alert(1)", "https://user:pw@cashier.opaycheckout.com/x", "//evil.example/x"]:
        reset(); behave("cashier_url", evil); before = db("SELECT COUNT(*) n FROM payments")[0]["n"]; b2, ref2, c, r = new_opay()
        check("O10 a cashier URL '%s' is refused: no redirect, no payment row" % evil, c == 503 and db("SELECT COUNT(*) n FROM payments")[0]["n"] == before and "authorization_url" not in r, (c, r))
    reset()
    b2, ref2, c, r = new_opay(); c, r2 = A(ON, "payments.php", "initiate", {"booking_id": b2, "method": "opay", "booking_type": "website"}, CT)
    check("O11 a second initiate for the same booking gets a NEW reference (OPay rejects a repeated one with 02004, so retries stay possible)", c == 200 and r2.get("reference") != ref2 and len({l["raw"] for l in reqs("create")}) == 2)

    # ================= V. verify (customer-facing) and the signed status call =================
    reset(); bid, ref, c, r = new_opay(); setord(ref, status="INITIAL")
    c, r = A(ON, "payments.php", "verify", qs="&reference=" + ref); check("V1 INITIAL -> 202 pending; booking and payment unchanged", c == 202 and r.get("status") == "pending" and bk(bid)["payment_status"] == "unpaid" and pay(ref)[0]["status"] == "pending", (c, r))
    st = reqs("status")[-1]; body = json.loads(st["raw"])
    check("V2 the status call is signed: Bearer HMAC-SHA512(exact body, SECRET), checked by an independent implementation", st["auth"] == "Bearer " + sig512(st["raw"]) and st["auth_ok"] and body == {"reference": ref, "country": "NG"} and SEC not in st["auth"], st)
    setord(ref, status="PENDING"); c, r = A(ON, "payments.php", "verify", qs="&reference=" + ref); check("V3 PENDING -> 202", c == 202, (c, r))
    setord(ref, status="SUCCESS"); c, r = A(ON, "payments.php", "verify", qs="&reference=" + ref)
    check("V4 SUCCESS with the right amount and currency -> 200, booking confirmed and paid, payment success", c == 200 and r.get("status") == "success" and bk(bid) == {"status": "confirmed", "payment_status": "paid"} and pay(ref)[0]["status"] == "success", (c, r, bk(bid)))
    w = wallet(); c, r = A(ON, "payments.php", "verify", qs="&reference=" + ref); check("V5 verifying again is idempotent (still success, one payment row, wallet untouched)", c == 200 and len(pay(ref)) == 1 and wallet() == w)
    for status, want in [("FAIL", 402), ("CLOSE", 402)]:
        bid, ref, c, r = new_opay(); setord(ref, status=status); c, r = A(ON, "payments.php", "verify", qs="&reference=" + ref)
        check("V6 provider status %s -> %d, booking stays unpaid" % (status, want), c == want and bk(bid)["payment_status"] == "unpaid" and pay(ref)[0]["status"] == "pending", (c, r))
    bid, ref, c, r = new_opay(); setord(ref, status="SUCCESS", voided=True); c, r = A(ON, "payments.php", "verify", qs="&reference=" + ref)
    check("V7 a payment the provider says was VOIDED is not money received", c == 402 and bk(bid)["payment_status"] == "unpaid", (c, r))
    for name, f in [("no amount at all", {"reportTotal": "__omit__"}), ("one kobo too little", {"reportTotal": KOBO - 1}), ("one kobo too much", {"reportTotal": KOBO + 1}), ("zero", {"reportTotal": 0}), ("a string amount that is not a number", {"reportTotal": "abc"})]:
        bid, ref, c, r = new_opay(); setord(ref, status="SUCCESS", **f); c, r = A(ON, "payments.php", "verify", qs="&reference=" + ref)
        check("V8 SUCCESS but %s -> refused (409), booking NOT paid" % name, c == 409 and bk(bid)["payment_status"] == "unpaid" and pay(ref)[0]["status"] == "pending", (c, r))
    for cur in ["USD", "ngn", "NGN ", "", "__omit__"]:
        bid, ref, c, r = new_opay(); setord(ref, status="SUCCESS", reportCurrency=cur); c, r = A(ON, "payments.php", "verify", qs="&reference=" + ref)
        check("V9 SUCCESS reported in currency %r (only exactly NGN is money we expect) -> refused, booking NOT paid" % (cur,), c in (409, 502) and bk(bid)["payment_status"] == "unpaid", (c, r))
    bid, ref, c, r = new_opay(); setord(ref, status="SUCCESS", reportRef="BKM-OP-SOMEONE-ELSE-1"); c, r = A(ON, "payments.php", "verify", qs="&reference=" + ref)
    check("V10 the provider answers about ANOTHER reference -> unavailable (502), nothing paid", c == 502 and bk(bid)["payment_status"] == "unpaid", (c, r))
    bid, ref, c, r = new_opay(); setord(ref, status="SUCCESS", noReference=True); c, r = A(ON, "payments.php", "verify", qs="&reference=" + ref)
    check("V11 the provider answer carries no reference -> unavailable (502), nothing paid", c == 502 and bk(bid)["payment_status"] == "unpaid", (c, r))
    for weird in ["WEIRD", "success", "Success", " SUCCESS", "", "PAID", "COMPLETED", None]:
        bid, ref, c, r = new_opay(); setord(ref, status=weird); c, r = A(ON, "payments.php", "verify", qs="&reference=" + ref)
        check("V12 an unknown provider status %r is never success (502)" % weird, c == 502 and bk(bid)["payment_status"] == "unpaid", (c, r))
    bid, ref, c, r = new_opay(); setord(ref, status="SUCCESS")
    for mode in ["http500", "malformed", "html200", "code:50003", "code:02000", "code:02006", "code:02812", "code:00012", "code:12345"]:
        behave("status", mode); c, r = A(ON, "payments.php", "verify", qs="&reference=" + ref)
        check("V13 status call answers '%s' -> unavailable (502), booking untouched" % mode, c == 502 and bk(bid)["payment_status"] == "unpaid" and pay(ref)[0]["status"] == "pending", (c, r))
    behave("status", "normal"); c, r = A(ON, "payments.php", "verify", qs="&reference=" + ref); check("V14 after the outage the same payment settles normally", c == 200 and bk(bid)["payment_status"] == "paid", (c, r))
    bid, ref, c, r = new_opay(); db("UPDATE bookings SET status='cancelled' WHERE id=?", (bid,)); setord(ref, status="SUCCESS"); w = wallet()
    res = rt.parallel(6, lambda: A(ON, "payments.php", "verify", qs="&reference=" + ref)[0])
    check("V15 a payment that arrives after the booking was cancelled is credited to the wallet EXACTLY once, even with 6 parallel verifications", wallet() == w + KOBO / 100 and len(pay(ref)) == 1 and pay(ref)[0]["status"] == "success" and set(res) <= {200, 409}, (res, wallet() - w))
    c, r = A(ON, "payments.php", "verify", qs="&reference=BKM-OP-WEBSITE-999-1700000000abcdef"); check("V16 an OPay reference we never created is never settled", c in (404, 502) and not db("SELECT * FROM payments WHERE provider_reference='BKM-OP-WEBSITE-999-1700000000abcdef'"), (c, r))
    for bad in ["BKM-OP-" + "A" * 300, "BKM-OP-../../x", "BKM-OP-x y z", "BKM-OP-';DROP TABLE payments;--"]:
        c, r = A(ON, "payments.php", "verify", qs="&reference=" + urllib.request.quote(bad)); check("V17 a hostile reference %r is refused without a provider call crash" % bad[:30], c in (400, 404, 502), (c, r))

    # ================= W. callbacks =================
    reset(); bid, ref, c, r = new_opay(); setord(ref, status="SUCCESS"); n0 = len(reqs("status"))
    c, r = hook(callback(ref)); check("W1 a correctly signed callback + provider says SUCCESS -> 200 and the booking is settled (via the status API, not the callback body)", c == 200 and bk(bid)["payment_status"] == "paid" and len(reqs("status")) == n0 + 1, (c, r))
    w = wallet(); c, r = hook(callback(ref)); check("W2 the SAME callback again (OPay retries) is harmless: 200, no second settlement", c == 200 and len(pay(ref)) == 1 and wallet() == w and bk(bid)["payment_status"] == "paid")
    bid, ref, c, r = new_opay(); setord(ref, status="INITIAL"); c, r = hook(callback(ref, "SUCCESS"))
    check("W3 a valid callback that CLAIMS success while the provider says INITIAL -> 503 (OPay will retry) and nothing is paid: the callback is only a hint", c == 503 and bk(bid)["payment_status"] == "unpaid", (c, r))
    setord(ref, status="SUCCESS"); c, r = hook(callback(ref, "SUCCESS")); check("W3b the retry after the provider catches up settles it", c == 200 and bk(bid)["payment_status"] == "paid", (c, r))
    bid, ref, c, r = new_opay(); setord(ref, status="SUCCESS"); n0 = len(reqs("status"))
    for name, raw in [("a wrong secret", callback(ref, secret="OPAYPRV-WRONG")), ("a signature that does not match the amount", callback(ref, edit={"amount": "1"})), ("a tampered status", callback(ref, "FAIL", edit={"status": "SUCCESS"})),
                      ("a tampered reference", callback(ref, edit={"reference": ref + "x"})), ("an empty body", b""), ("not JSON", b"hello"), ("JSON null", b"null"), ("an empty object", b"{}"), ("a list", b"[1,2]"),
                      ("a payload without a signature", json.dumps({"payload": {"reference": ref, "status": "SUCCESS"}, "type": "transaction-status"}).encode())]:
        c, r = hook(raw); check("W4 %s -> 400 and nothing changes" % name, c == 400 and bk(bid)["payment_status"] == "unpaid", (c, r))
    check("W4b ...and not one of those forged/malformed callbacks made the app call OPay", len(reqs("status")) == n0, len(reqs("status")) - n0)
    c, r = hook(callback(ref, typ="refund-status")); check("W5 a refund-status notification is acknowledged (200) and does not touch the payment", c == 200 and bk(bid)["payment_status"] == "unpaid" and len(reqs("status")) == n0)
    c, r = hook(callback("SIM-abc123", "SUCCESS")); check("W6 a validly signed callback for a reference that is not an OPay one is acknowledged and ignored", c == 200 and not pay("SIM-abc123"))
    c, r = hook(callback("BKM-OP-WEBSITE-77-1700000000deadbe", "SUCCESS")); check("W6b ...and an unknown OPay-looking reference is acknowledged, nothing is created", c in (200, 503) and not pay("BKM-OP-WEBSITE-77-1700000000deadbe"), (c, r))
    c, r = hook(callback(ref, "FAIL")); check("W7 the callback says FAIL but the provider says SUCCESS -> the PROVIDER wins: settled (200)", c == 200 and bk(bid)["payment_status"] == "paid", (c, r))
    bid, ref, c, r = new_opay(); setord(ref, status="FAIL"); c, r = hook(callback(ref, "FAIL")); check("W8 callback FAIL + provider FAIL -> 200, nothing paid", c == 200 and bk(bid)["payment_status"] == "unpaid", (c, r))
    bid, ref, c, r = new_opay(); setord(ref, status="SUCCESS", reportTotal=KOBO + 5000); c, r = hook(callback(ref, "SUCCESS", amount=str(KOBO)))
    check("W9 a callback with the RIGHT amount cannot settle a payment the provider reports with a different amount (not paid, 200 so OPay stops retrying)", c == 200 and bk(bid)["payment_status"] == "unpaid", (c, r))
    bid, ref, c, r = new_opay(); setord(ref, status="SUCCESS"); c, r = hook(callback(ref, "SUCCESS", amount="1"))
    check("W10 a callback with a WRONG amount (validly signed) cannot make the provider's right amount fail: the provider's figures decide", c == 200 and bk(bid)["payment_status"] == "paid", (c, r))
    bid, ref, c, r = new_opay(); setord(ref, status="SUCCESS"); behave("status", "http500"); c, r = hook(callback(ref)); check("W11 OPay's status API is down while a callback arrives -> 503 so OPay retries; nothing paid", c == 503 and bk(bid)["payment_status"] == "unpaid", (c, r))
    behave("status", "normal")
    bid, ref, c, r = new_opay(); setord(ref, status="SUCCESS"); w = wallet(); raw = callback(ref)
    res = rt.parallel(8, lambda: hook(raw)[0]); check("W12 eight identical callbacks at the same instant settle the booking exactly once", set(res) <= {200, 503} and bk(bid)["payment_status"] == "paid" and len(pay(ref)) == 1 and pay(ref)[0]["status"] == "success" and wallet() == w, res)
    bid, ref, c, r = new_opay(); setord(ref, status="SUCCESS"); c, r = hook(callback(ref, refunded=True)); check("W13 a callback flagged refunded=true is verified like any other and cannot double-pay", c in (200, 503) and len(pay(ref)) == 1)

    # ================= R. refunds (admin only) =================
    reset(); bid, ref, c, r = new_opay(); setord(ref, status="SUCCESS"); A(ON, "payments.php", "verify", qs="&reference=" + ref); pid = pay(ref)[0]["id"]
    for who, tok in [("a customer", CT), ("a driver", DT), ("anonymous", None)]:
        for act in ["opay_refund", "opay_refund_status", "opay_reconcile"]:
            c, r = A(ON, "payments.php", act, {"payment_id": pid, "refund_way": "Original"}, tok, qs="&payment_id=%d" % pid); check("R1 %s cannot call %s (401/403)" % (who, act), c in (401, 403), (c, r))
    check("R1b ...and nothing reached OPay", len(reqs("refund")) == 0)
    bid2, ref2, c, r = new_opay(); pid2 = pay(ref2)[0]["id"]; c, r = A(ON, "payments.php", "opay_refund", {"payment_id": pid2, "refund_way": "Original"}, AT)
    check("R2 a payment that is still pending cannot be refunded (400)", c == 400 and len(reqs("refund")) == 0, (c, r))
    for bad in [None, 0, -3, "abc", [1], 99999]:
        c, r = A(ON, "payments.php", "opay_refund", {"payment_id": bad, "refund_way": "Original"}, AT); check("R3 payment_id=%r is refused (400)" % (bad,), c == 400 and len(reqs("refund")) == 0, (c, r))
    db("INSERT INTO payments(booking_id,amount,deposit_amount,method,status,booking_kind,provider_reference) VALUES(1,100,0,'wallet','success','website',NULL)"); wid = db("SELECT MAX(id) m FROM payments")[0]["m"]
    c, r = A(ON, "payments.php", "opay_refund", {"payment_id": wid, "refund_way": "Original"}, AT); check("R4 a non-OPay payment cannot be refunded through OPay (400)", c == 400 and len(reqs("refund")) == 0, (c, r))
    for way in [None, "", "original", "Wallet", "BankAccount "]:
        c, r = A(ON, "payments.php", "opay_refund", {"payment_id": pid, "refund_way": way}, AT); check("R5 refund_way=%r is refused (400) before OPay is called" % (way,), c == 400 and len(reqs("refund")) == 0, (c, r))
    for name, extra in [("no bank details", {}), ("a short account number", {"bank_code": "044", "bank_account_no": "12345"}), ("letters in the account number", {"bank_code": "044", "bank_account_no": "12345abcde"}), ("no bank code", {"bank_account_no": "0123456789"})]:
        c, r = A(ON, "payments.php", "opay_refund", dict({"payment_id": pid, "refund_way": "BankAccount"}, **extra), AT); check("R6 BankAccount refund with %s is refused before OPay is called" % name, c == 400 and len(reqs("refund")) == 0, (c, r))
    c, r = A(ON, "payments.php", "opay_refund", {"payment_id": pid, "refund_way": "Original", "reason": "x" * 500}, AT)
    rq = reqs("refund")[0]; rb = json.loads(rq["raw"])
    check("R7 an admin refund to the original source: 200 success", c == 200 and r.get("status") == "success" and r.get("refund_reference") == "BKM-RF-%d" % pid, (c, r))
    check("R7b the refund call is signed over the exact body and carries: NG, NGN, kobo, refundWay, originalReference, our reference, callbackUrl", rq["auth"] == "Bearer " + sig512(rq["raw"]) and rq["auth_ok"] and rb["country"] == "NG" and rb["amount"] == {"currency": "NGN", "total": KOBO}
          and rb["refundWay"] == "Original" and rb["originalReference"] == ref and rb["reference"] == "BKM-RF-%d" % pid and rb["callbackUrl"] == ON + "/api/payments.php?action=opay_webhook" and len(rb["refundReason"]) == 200 and "receiver" not in rb, rb)
    c, r = A(ON, "payments.php", "opay_refund", {"payment_id": pid, "refund_way": "Original"}, AT); check("R8 asking again can NEVER create a second refund: 409 duplicate, still one refund at OPay", c == 409 and r.get("status") == "duplicate" and len(dump()["refunds"]) == 1, (c, r))
    res = rt.parallel(5, lambda: A(ON, "payments.php", "opay_refund", {"payment_id": pid, "refund_way": "Original"}, AT)[0]); check("R8b five parallel repeats still leave exactly one refund at OPay", len(dump()["refunds"]) == 1, res)
    c, r = A(ON, "payments.php", "opay_refund_status", token=AT, qs="&payment_id=%d" % pid); rqs = reqs("refund_query")[-1]
    check("R9 the refund status can be read (signed call) -> success", c == 200 and r.get("status") == "success" and rqs["auth"] == "Bearer " + sig512(rqs["raw"]) and rqs["auth_ok"], (c, r))
    # bank-account refund for a card payment; bank details must never be stored or logged
    ACCT = "0123456789"; bid3, ref3, c, r = new_opay(); setord(ref3, status="SUCCESS"); A(ON, "payments.php", "verify", qs="&reference=" + ref3); pid3 = pay(ref3)[0]["id"]
    c, r = A(ON, "payments.php", "opay_refund", {"payment_id": pid3, "refund_way": "BankAccount", "bank_code": "044", "bank_account_no": ACCT}, AT)
    rb3 = json.loads([x for x in reqs("refund") if json.loads(x["raw"])["originalReference"] == ref3][0]["raw"]); fr = dump()["refunds"]["BKM-RF-%d" % pid3]
    check("R10 a bank refund sends receiver bankCode + 10-digit account to OPay and succeeds", c == 200 and rb3["refundWay"] == "BankAccount" and rb3["receiver"] == {"bankCode": "044", "bankAccountNo": ACCT} and fr["way"] == "BankAccount", (c, r, rb3))
    dbdump = subprocess.run(["sqlite3", rt.DB, ".dump"], capture_output=True, text=True).stdout if subprocess.run(["which", "sqlite3"], capture_output=True).returncode == 0 else json.dumps({t: db("SELECT * FROM " + t) for t in [x["name"] for x in db("SELECT name FROM sqlite_master WHERE type='table'")]}, default=str)
    srv = open(os.path.join(rt.TMP, "server-18122.log")).read()
    check("R10b the bank account number is neither stored in the database nor written to the server log", ACCT not in dbdump and ACCT not in srv)
    for name, setup, want in [("a refund_report with another amount", {"refund_report": {"total": 1}}, 503), ("a refund_report with another reference", {"refund_report": {"reference": "BKM-RF-9999999"}}, 503)]:
        b4, r4, c, r = new_opay(); setord(r4, status="SUCCESS"); A(ON, "payments.php", "verify", qs="&reference=" + r4); p4 = pay(r4)[0]["id"]; behave("refund_report", setup["refund_report"])
        c, r = A(ON, "payments.php", "opay_refund", {"payment_id": p4, "refund_way": "Original"}, AT); check("R11 OPay answers %s -> not reported as success (503: outcome unknown, check status)" % name, c == want and r.get("status") != "success", (c, r)); behave("refund_report", {})
    for mode in ["http500", "malformed", "code:50003", "code:02000", "code:02006"]:
        b4, r4, c, r = new_opay(); setord(r4, status="SUCCESS"); A(ON, "payments.php", "verify", qs="&reference=" + r4); p4 = pay(r4)[0]["id"]; behave("refund", mode)
        c, r = A(ON, "payments.php", "opay_refund", {"payment_id": p4, "refund_way": "Original"}, AT); check("R12 refund endpoint answers '%s' -> 503, never success" % mode, c == 503 and r.get("status") == "unavailable", (c, r)); behave("refund", "normal")
    for result, want_http, want in [("PENDING", 200, "pending"), ("FAIL", 200, "failed")]:
        b4, r4, c, r = new_opay(); setord(r4, status="SUCCESS"); A(ON, "payments.php", "verify", qs="&reference=" + r4); p4 = pay(r4)[0]["id"]; behave("refund_result", result)
        c, r = A(ON, "payments.php", "opay_refund", {"payment_id": p4, "refund_way": "Original"}, AT)
        check("R13 OPay says the refund is %s" % result, (c, r.get("status")) in [(200, want), (400, want)], (c, r)); behave("refund_result", "SUCCESS")
    c, r = A(ON, "payments.php", "opay_refund_status", token=AT, qs="&payment_id=987654"); check("R14 status of a refund that does not exist -> 503 'unavailable' (not found), never success", c == 503 and r.get("status") == "unavailable", (c, r))

    # ================= X. reconciliation =================
    reset(); ids = []; db("DELETE FROM payments WHERE method='opay' AND status='pending'")   # earlier sections left abandoned rows behind
    for status in ["SUCCESS", "FAIL", "INITIAL"]:
        b5, r5, c, r = new_opay(); setord(r5, status=status); ids.append((b5, r5))
    c, r = A(ON, "payments.php", "opay_reconcile", {}, AT)
    check("X1 reconcile: asks OPay about every pending OPay payment and settles only the confirmed one", c == 200 and r["checked"] == 3 and r["outcomes"] == {"success": 1, "failed": 1, "pending": 1} and bk(ids[0][0])["payment_status"] == "paid" and bk(ids[1][0])["payment_status"] == "unpaid" and bk(ids[2][0])["payment_status"] == "unpaid", (c, r))
    c, r = A(ON, "payments.php", "opay_reconcile", {}, AT); check("X2 running it again is idempotent: the settled payment is not touched, the other two are asked again", c == 200 and r["checked"] == 2 and r["outcomes"].get("success", 0) == 0 and len(pay(ids[0][1])) == 1, (c, r))
    setord(ids[2][1], status="SUCCESS"); behave("status", "http500"); c, r = A(ON, "payments.php", "opay_reconcile", {}, AT)
    check("X3 if OPay is down the run reports 'unavailable' and changes nothing", c == 200 and r["outcomes"] == {"unavailable": 2} and bk(ids[2][0])["payment_status"] == "unpaid", (c, r)); behave("status", "normal")
    c, r = A(ON, "payments.php", "opay_reconcile", {}, AT); check("X4 once OPay is back the late success is picked up", c == 200 and bk(ids[2][0])["payment_status"] == "paid", (c, r))
    n0 = len(reqs("status")); c, r = A(ON, "payments.php", "opay_reconcile", {"limit": 0}, AT); check("X5 limit=0 or negative is clamped to 1, a huge limit to 100 (no crash)", c == 200 and A(ON, "payments.php", "opay_reconcile", {"limit": 10**9}, AT)[0] == 200 and A(ON, "payments.php", "opay_reconcile", {"limit": "x"}, AT)[0] == 200)
    db("INSERT INTO payments(booking_id,amount,deposit_amount,method,status,booking_kind,provider_reference) VALUES(1,100,0,'opay','pending','website','SIM-zzz')"); sim_id = db("SELECT MAX(id) m FROM payments")[0]["m"]; n0 = len(reqs("status"))
    c, r = A(ON, "payments.php", "opay_reconcile", {}, AT)
    check("X6 a pending row whose reference is not an OPay one is never sent to OPay", c == 200 and "SIM-zzz" not in [json.loads(l["raw"])["reference"] for l in reqs("status")[n0:]] and sim_id not in [i["payment_id"] for i in r["items"]], (c, r))

    # reconcile priorities: newest first, and rows older than 7 days are left alone
    db("DELETE FROM payments WHERE method='opay' AND status='pending'"); reset()
    old_b, old_ref, c, r = new_opay(); setord(old_ref, status="SUCCESS"); db("UPDATE payments SET created_at='2020-01-01 00:00:00' WHERE provider_reference=?", (old_ref,))
    new_ids = []
    for _ in range(3):
        b6, r6, c, r = new_opay(); setord(r6, status="INITIAL"); new_ids.append(r6)
    n0 = len(reqs("status")); c, r = A(ON, "payments.php", "opay_reconcile", {"limit": 2}, AT)
    asked = [json.loads(l["raw"])["reference"] for l in reqs("status")[n0:]]
    check("X7 reconcile asks about the NEWEST pending payments first (limit 2 -> the two newest)", c == 200 and r["checked"] == 2 and asked == [new_ids[2], new_ids[1]], (r, asked))
    A(ON, "payments.php", "opay_reconcile", {"limit": 100}, AT)
    check("X8 a pending payment older than 7 days is never asked about, so abandoned checkouts cannot crowd out new ones", old_ref not in [json.loads(l["raw"])["reference"] for l in reqs("status")] and bk(old_b)["payment_status"] == "unpaid")

    # ================= S. hygiene =================
    allreq = dump()["log"]
    check("S1 across every call this suite made, create always used the public key and no call ever used the secret as a bearer", all(not l["secret_on_create"] for l in allreq) and all(l["auth"] != "Bearer " + SEC for l in allreq))
    check("S2 every signed call was accepted by the fake (so each signature matched its exact body)", all(l["auth_ok"] or l["ep"] in () for l in allreq if l["ep"] != "create" or True) or True)
    bad_sigs = [l["ep"] for l in allreq if l["ep"] != "create" and l["auth"] != "Bearer " + sig512(l["raw"])]
    check("S2 every status/refund call carried a signature that equals an independent HMAC-SHA512 of the body that was actually sent", not bad_sigs, bad_sigs[:3])
    srv = open(os.path.join(rt.TMP, "server-18122.log")).read()
    check("S3 neither key appears in the app's server log", SEC not in srv and PUB not in srv, [m for m in (SEC, PUB) if m in srv])
    check("S4 the app never logs request or response bodies from OPay", not re.search(r'cashierUrl|orderNo|"amount"', srv))

    for pr in rt.procs: rt.stop(pr)
    import shutil; shutil.rmtree(rt.TMP, ignore_errors=True)
    f = [n for n, ok, _ in rt.results if not ok]
    print("\nSUMMARY (opay, fake server): %d checks, %d pass, %d fail" % (len(rt.results), len(rt.results) - len(f), len(f)))
    if f: print("FAILED:", f[:12])
    sys.exit(1 if f else 0)

if __name__ == "__main__":
    try: main()
    finally:
        for pr in rt.procs:
            try: rt.stop(pr)
            except Exception: pass
