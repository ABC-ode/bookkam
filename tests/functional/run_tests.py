#!/usr/bin/env python3
"""Local functional tests against a SQLite stand-in of the BOOKKAM schema.
Runs the REAL PHP endpoint code from the latest main (copied to ./testbed).
No network beyond 127.0.0.1, no production data, no real payments."""
import json, sqlite3, subprocess, hmac, hashlib, urllib.request, urllib.error, sys, os

S = os.environ["SCRATCH"]
BASE = "http://127.0.0.1:8099/api/"
DB = S + "/tests/t.db"
WEBHOOK_SECRET = "testbed-only-paystack"
results = []

def call(ep, action, body=None, token=None, method=None, qs="", raw=None, headers=None):
    url = BASE + ep + "?action=" + action + qs
    data = raw if raw is not None else (json.dumps(body).encode() if body is not None else None)
    req = urllib.request.Request(url, data=data, method=method or ("POST" if data is not None else "GET"))
    req.add_header("Content-Type", "application/json")
    if token: req.add_header("Authorization", "Bearer " + token)
    for k, v in (headers or {}).items(): req.add_header(k, v)
    try:
        with urllib.request.urlopen(req, timeout=10) as r:
            code, txt = r.status, r.read().decode()
    except urllib.error.HTTPError as e:
        code, txt = e.code, e.read().decode()
    try: js = json.loads(txt)
    except Exception: js = {"_raw": txt[:200]}
    return code, js

def db(sql, args=()):
    c = sqlite3.connect(DB); c.row_factory = sqlite3.Row
    rows = c.execute(sql, args).fetchall(); c.commit(); c.close()
    return [dict(r) for r in rows]

def check(name, ok, detail=""):
    results.append((name, ok, detail))
    print(("PASS " if ok else "FAIL ") + name + (" :: " + str(detail) if detail != "" else ""))

def webhook(event, ref, signed=True, secret=WEBHOOK_SECRET, amount=1500000):
    d = {"reference": ref, "currency": "NGN"}
    if amount is not None: d["amount"] = amount
    payload = json.dumps({"event": event, "data": d}).encode()
    sig = hmac.new(secret.encode(), payload, hashlib.sha512).hexdigest() if signed else "bad"
    return call("payments.php", "webhook", raw=payload, headers={"x-paystack-signature": sig})

# ---------- seed (test double only) ----------
pw = subprocess.check_output(["php", "-r", "echo password_hash('Adm1n-test-only',PASSWORD_BCRYPT);"]).decode()
db("INSERT INTO users(email,name,role,city,wallet_balance,password_hash) VALUES('admin@test.local','Test Admin','admin','Calabar',0,?)", (pw,))
for t, col, v in [("hourly","per_hour_price",5000),("daily","per_day_price",30000)]:
    db("INSERT INTO pricing(city,booking_type,%s) VALUES('Calabar',?,?)" % col, (t, v))
db("INSERT INTO pricing(city,booking_type,base_price) VALUES('Calabar','chauffeur_trip',15000)")

# ---------- 1. accounts ----------
c, adm = call("auth.php", "admin_login", {"email": "admin@test.local", "password": "Adm1n-test-only"})
check("admin login works", c == 200 and "token" in adm); AT = adm.get("token")
c, cu = call("auth.php", "register_email", {"name": "Cust One", "email": "c1@test.local", "password": "secret123", "confirm": "secret123", "role": "customer", "city": "Calabar"})
check("customer registers; welcome credit present", c == 200 and cu["user"]["wallet_balance"] == 1000, cu.get("user", {}).get("wallet_balance")); CT = cu["token"]
c, cu2 = call("auth.php", "register_email", {"name": "Cust Two", "email": "c2@test.local", "password": "secret123", "confirm": "secret123", "role": "customer", "city": "Calabar"}); C2T = cu2["token"]
c, dr = call("auth.php", "register_email", {"name": "Driver One", "email": "d1@test.local", "password": "secret123", "confirm": "secret123", "role": "driver", "city": "Calabar"})
check("driver registers as pending", dr.get("driver_status") == "pending", dr.get("driver_status")); DT = dr["token"]
drv_id = db("SELECT id FROM drivers")[0]["id"]

# ---------- 2. driver onboarding ----------
c, r = call("cars.php", "driver_save", {"name": "Toyota Camry", "plate_number": "CAL-001", "year": 2019, "make": "Toyota", "model": "Camry"}, DT)
check("driver can save car while still pending", c == 200 and r.get("success"), r)
c, r = call("cars.php", "get_all", qs="&type=chauffeur&city=Calabar", token=CT)
check("pending/offline driver's car NOT listed to customers", len(r.get("cars", [])) == 0, len(r.get("cars", [])))
c, r = call("admin.php", "update_driver_status", {"driver_id": drv_id, "status": "active"}, AT); check("admin approves driver", c == 200 and r.get("success"), r)
c, r = call("drivers.php", "toggle_online", {"is_online": 1}, DT); check("driver goes online", r.get("success"), r)
c, r = call("cars.php", "get_all", qs="&type=chauffeur&city=Calabar", token=CT)
check("active+online driver's car listed to customers", len(r.get("cars", [])) == 1, len(r.get("cars", []))); car_id = int(r["cars"][0]["id"])

# ---------- 3. customer booking journey ----------
c, r = call("bookings.php", "create", {"car_id": car_id, "booking_type": "trip", "pickup_address": "", "dropoff_address": "x"}, CT); check("create without pickup is rejected", "error" in r, r)
c, r = call("bookings.php", "create", {"booking_type": "trip", "pickup_address": "A"}, CT); check("create without car_id is rejected", "error" in r, r)
c, r = call("bookings.php", "create", {"car_id": 999, "booking_type": "trip", "pickup_address": "A"}, CT); check("create for unknown car is rejected", "error" in r, r)
c, b1 = call("bookings.php", "create", {"car_id": car_id, "booking_type": "trip", "pickup_address": "Marian Rd", "dropoff_address": "Airport", "duration_hours": 2, "city": "Calabar", "car_type": "chauffeur"}, CT)
check("trip booking created; total matches pricing table (15000)", c == 200 and b1.get("total") == 15000, b1); B1 = int(b1.get("booking_id"))
c, r = call("bookings.php", "get_my_bookings", token=CT); check("customer sees the booking as pending", any(x["id"] == B1 and x["status"] == "pending" for x in r.get("bookings", [])))
c, r = call("bookings.php", "create", {"car_id": car_id, "booking_type": "trip", "pickup_address": "B"}, C2T); check("second customer cannot book the same car while it is held", "error" in r, r)
c, r = call("bookings.php", "get_driver_bookings", token=DT); check("driver sees assigned pending booking", any(x["id"] == B1 and x["status"] == "pending" for x in r.get("bookings", [])))

# ---------- 4. payments: retry paths ----------
c, r = call("payments.php", "initiate", {"booking_id": B1, "method": "cash", "booking_type": "website"}, CT)
check("cash: payment row pending, booking stays pending", r.get("status") == "pending" and db("SELECT status FROM bookings WHERE id=?", (B1,))[0]["status"] == "pending", r)
c, r = call("payments.php", "initiate", {"booking_id": B1, "method": "wallet", "booking_type": "website"}, CT)
check("wallet with only the 1000 welcome credit is refused", "error" in r, r)
db("UPDATE users SET wallet_balance=60000 WHERE email='c1@test.local'")  # test-double top-up (prod top-up needs Paystack)
c, r = call("payments.php", "initiate", {"booking_id": B1, "method": "wallet", "booking_type": "website"}, CT)
bk = db("SELECT status,payment_status FROM bookings WHERE id=?", (B1,))[0]
check("retry via wallet after cash attempt succeeds; booking confirmed+paid", r.get("success") and bk == {"status": "confirmed", "payment_status": "paid"}, bk)
rows = db("SELECT method,status FROM payments WHERE booking_id=?", (B1,)); print("   payments rows:", rows)
check("[DEFECT?] stale cash 'pending' row left beside the successful wallet row", any(p["method"] == "cash" and p["status"] == "pending" for p in rows) and any(p["method"] == "wallet" and p["status"] == "success" for p in rows))
bal = db("SELECT wallet_balance FROM users WHERE email='c1@test.local'")[0]["wallet_balance"]
c, r = call("payments.php", "initiate", {"booking_id": B1, "method": "wallet", "booking_type": "website"}, CT)
bal2 = db("SELECT wallet_balance FROM users WHERE email='c1@test.local'")[0]["wallet_balance"]
check("[DEFECT?] paying an already-paid booking is refused", "error" in r and bal2 == bal, "balance %s -> %s, resp=%s" % (bal, bal2, r))

# ---------- 5. driver workflow ----------
c, r = call("bookings.php", "update_status", {"booking_id": B1, "status": "active"}, DT); check("driver starts trip (active)", c == 200 and r.get("success"), r)
c, r = call("bookings.php", "update_status", {"booking_id": B1, "status": "completed"}, DT); check("driver completes trip", c == 200 and r.get("success"), r)
car = db("SELECT is_available FROM cars WHERE id=?", (car_id,))[0]; check("car released after completion", car["is_available"] == 1, car)
c, r = call("drivers.php", "get_earnings", qs="&period=today", token=DT); print("   earnings:", r.get("earnings"))
check("driver earnings reflect the completed trip (15000 total, 3000 commission)", r.get("earnings", {}).get("total") == 15000 and r["earnings"]["commission"] == 3000, r.get("earnings"))
c, r = call("bookings.php", "update_status", {"booking_id": B1, "status": "bogus"}, DT); check("invalid status rejected", "error" in r, r)
print("SKIP driver payout request: endpoint uses MySQL-only DATE_SUB(...INTERVAL) which SQLite cannot parse (not testable here)")

# ---------- 6. Paystack path: failure / success / duplicate (webhook simulated) ----------
c, b2 = call("bookings.php", "create", {"car_id": car_id, "booking_type": "trip", "pickup_address": "Calabar Mall", "city": "Calabar"}, CT); B2 = int(b2["booking_id"])
ref2 = "BKM-WEBSITE-%d-1700000001" % B2
db("INSERT INTO payments(booking_id,booking_kind,amount,deposit_amount,method,status,is_test,paystack_reference) VALUES(?,?,?,?,?,?,?,?)", (B2, "website", 15000, 0, "paystack", "pending", 0, ref2))  # what initiate would write
c, r = webhook("charge.success", ref2, signed=False); check("webhook with bad signature rejected (401)", c == 401, c)
c, r = webhook("charge.failed", ref2); st = db("SELECT status FROM bookings WHERE id=?", (B2,))[0]["status"]; pst = db("SELECT status FROM payments WHERE paystack_reference=?", (ref2,))[0]["status"]
check("[OPEN] failed charge is recorded (payment marked failed; status enum unknown)", pst == "failed", "payment status after charge.failed = %s, booking = %s" % (pst, st))
c, r = webhook("charge.success", ref2); bk = db("SELECT status,payment_status FROM bookings WHERE id=?", (B2,))[0]
check("success webhook confirms booking and marks paid", bk == {"status": "confirmed", "payment_status": "paid"}, bk)
c, r = webhook("charge.success", ref2); n = db("SELECT COUNT(*) n FROM payments WHERE paystack_reference=?", (ref2,))[0]["n"]
check("duplicate webhook is harmless (no extra rows)", c == 200 and n == 1, n)
c, r = call("payments.php", "initiate", {"booking_id": B2, "method": "opay", "booking_type": "website"}, CT); check("OPay is off: clear error", "error" in r, r.get("error"))

# ---------- 7. cancellation + consistency ----------
c, r = call("bookings.php", "cancel", {"booking_id": B2}, CT); check("customer can cancel a confirmed, paid booking", r.get("success"), r)
pay = db("SELECT status FROM payments WHERE paystack_reference=?", (ref2,))[0]["status"]
wref = db("SELECT wallet_balance w FROM users WHERE email='c1@test.local'")[0]["w"]
check("cancelling a PAID booking refunds the customer's wallet (FIXED)", r.get("refunded") == 15000, "refunded=%s (payment row stays '%s' as the audit trail)" % (r.get("refunded"), pay))
c, b3 = call("bookings.php", "create", {"car_id": car_id, "booking_type": "trip", "pickup_address": "Ikom Rd", "city": "Calabar"}, CT); B3 = int(b3["booking_id"])
c, r = call("bookings.php", "cancel", {"booking_id": B3}, CT)
c, r = call("payments.php", "initiate", {"booking_id": B3, "method": "cash", "booking_type": "website"}, CT)
check("[DEFECT?] payment cannot be started for a CANCELLED booking", "error" in r, r)
ref3 = "BKM-WEBSITE-%d-1700000003" % B3
db("INSERT INTO payments(booking_id,booking_kind,amount,deposit_amount,method,status,is_test,paystack_reference) VALUES(?,?,?,?,?,?,?,?)", (B3, "website", 15000, 0, "paystack", "pending", 0, ref3))
webhook("charge.success", ref3); bk3 = db("SELECT status FROM bookings WHERE id=?", (B3,))[0]["status"]
check("[DEFECT?] late payment does not revive a cancelled booking", bk3 == "cancelled", "booking status after late success = %s" % bk3)
carS = db("SELECT is_available FROM cars WHERE id=?", (car_id,))[0]["is_available"]
c, b4 = call("bookings.php", "create", {"car_id": car_id, "booking_type": "trip", "pickup_address": "Second", "city": "Calabar"}, C2T)
live = db("SELECT COUNT(*) n FROM bookings WHERE car_id=? AND status IN ('pending','confirmed','active')", (car_id,))[0]["n"]
check("[DEFECT?] car cannot end up with two live bookings", live <= 1, "live bookings on one car = %d (revived B3 confirmed + new B4; car flag was %s)" % (live, carS))

# ---------- 8. self-drive rental ----------
c, r = call("cars.php", "admin_add", {"name": "Self Drive Prado", "car_type": "self_drive", "plate_number": "SD-01", "city": "Calabar", "security_deposit": 20000}, AT); SD = int(r.get("id")); check("admin adds self-drive car", r.get("success"), r)
c, r = call("cars.php", "get_all", qs="&type=self_drive&city=Calabar&available=1", token=CT); check("self-drive car listed", len(r.get("cars", [])) == 1)
c, s1 = call("bookings.php", "create", {"car_id": SD, "booking_type": "daily", "pickup_address": "Office", "duration_days": 2, "duration_hours": 4, "city": "Calabar", "car_type": "self_drive"}, CT)
check("daily rental: total 60000 + deposit 20000", s1.get("total") == 60000 and s1.get("deposit") == 20000 and s1.get("total_with_deposit") == 80000, s1)
db("UPDATE users SET wallet_balance=100000 WHERE email='c1@test.local'")
c, r = call("payments.php", "initiate", {"booking_id": int(s1["booking_id"]), "method": "wallet", "booking_type": "website"}, CT)
w = db("SELECT wallet_balance FROM users WHERE email='c1@test.local'")[0]["wallet_balance"]; check("wallet pays total+deposit (80000)", r.get("success") and w == 20000, "balance now %s" % w)
call("bookings.php", "update_status", {"booking_id": int(s1["booking_id"]), "status": "completed"}, AT)
c, r = call("bookings.php", "release_deposit", {"booking_id": int(s1["booking_id"])}, AT); w2 = db("SELECT wallet_balance FROM users WHERE email='c1@test.local'")[0]["wallet_balance"]; check("admin releases deposit to wallet (+20000)", r.get("success") and w2 == 40000, "balance %s" % w2)

# ---------- 9. buttons that cannot work in production mode ----------
c, r = call("payments.php", "initiate", {"booking_id": B1, "method": "test", "booking_type": "website"}, CT); check("[OPEN, frontend] 'Simulate Payment' is refused server-side in production (button still shown to customers)", "error" in r, r.get("error"))
c, r = call("payments.php", "top_up_wallet", {"amount": 5000}, CT); check("[OPEN, out of scope] wallet top-up works in production", "error" not in r, r.get("error"))
c, r = call("payments.php", "get_methods"); check("get_methods reflects Paystack configured", r.get("paystack") is True)

# ---------- 10. driver-side gaps ----------
call("drivers.php", "toggle_online", {"is_online": 0}, DT); call("admin.php", "update_driver_status", {"driver_id": drv_id, "status": "suspended"}, AT)
db("UPDATE bookings SET status='cancelled' WHERE car_id=?", (car_id,)); db("UPDATE cars SET is_available=1 WHERE id=?", (car_id,))
c, r = call("bookings.php", "create", {"car_id": car_id, "booking_type": "trip", "pickup_address": "X", "city": "Calabar"}, CT)
check("[DEFECT?] cannot book a car whose driver is offline AND suspended", "error" in r, r)

# ---------- 11. regression checks for the payment / security fixes ----------
# cancel of a paid booking refunds exactly once
db("UPDATE drivers SET status='active', is_online=1 WHERE id=?", (drv_id,)); db("UPDATE cars SET is_available=1 WHERE id=?", (car_id,)); db("UPDATE bookings SET status='cancelled' WHERE car_id=? AND status IN ('pending','confirmed')", (car_id,))
db("UPDATE users SET wallet_balance=50000 WHERE email='c1@test.local'")
c, b5 = call("bookings.php", "create", {"car_id": car_id, "booking_type": "trip", "pickup_address": "Refund test", "city": "Calabar"}, CT); B5 = int(b5["booking_id"])
call("payments.php", "initiate", {"booking_id": B5, "method": "wallet", "booking_type": "website"}, CT)
w0 = db("SELECT wallet_balance FROM users WHERE email='c1@test.local'")[0]["wallet_balance"]
check("wallet pay debits exactly once (50000 -> 35000)", w0 == 35000, w0)
c, r = call("payments.php", "initiate", {"booking_id": B5, "method": "wallet", "booking_type": "website"}, CT)
check("second wallet payment on a paid booking refused, no extra charge", "error" in r and db("SELECT wallet_balance w FROM users WHERE email='c1@test.local'")[0]["w"] == 35000, r)
c, r = call("payments.php", "initiate", {"booking_id": B5, "method": "paystack", "booking_type": "website"}, CT)
check("paystack/test-mode payment on a paid booking refused", "error" in r, r)
c, r = call("bookings.php", "cancel", {"booking_id": B5}, CT)
w1 = db("SELECT wallet_balance FROM users WHERE email='c1@test.local'")[0]["wallet_balance"]
check("cancel refunds the paid amount to the wallet (35000 -> 50000)", r.get("success") and w1 == 50000, (r, w1))
c, r = call("bookings.php", "cancel", {"booking_id": B5}, CT)
w2 = db("SELECT wallet_balance FROM users WHERE email='c1@test.local'")[0]["wallet_balance"]
check("cancelling twice does not refund twice", "error" in r and w2 == 50000, (r, w2))
c, r = call("bookings.php", "update_status", {"booking_id": B5, "status": "confirmed"}, AT)
check("a cancelled booking cannot be revived via update_status", "error" in r, r)
# webhook integrity
c, b6 = call("bookings.php", "create", {"car_id": car_id, "booking_type": "trip", "pickup_address": "Webhook test", "city": "Calabar"}, CT); B6 = int(b6["booking_id"])
ref6 = "BKM-WEBSITE-%d-1700000006" % B6
db("INSERT INTO payments(booking_id,booking_kind,amount,deposit_amount,method,status,is_test,paystack_reference) VALUES(?,?,?,?,?,?,?,?)", (B6, "website", 15000, 0, "paystack", "pending", 0, ref6))
webhook("charge.success", ref6, amount=100)
check("underpaid webhook (100 kobo) does not confirm the booking", db("SELECT status FROM bookings WHERE id=?", (B6,))[0]["status"] == "pending")
webhook("charge.success", ref6, amount=None)
check("webhook without an amount does not confirm in production", db("SELECT status FROM bookings WHERE id=?", (B6,))[0]["status"] == "pending")
webhook("charge.success", ref6, secret="wrong-secret")
check("webhook signed with the wrong secret is rejected", db("SELECT status FROM bookings WHERE id=?", (B6,))[0]["status"] == "pending")
webhook("charge.success", ref6)
check("correctly signed, correct-amount webhook confirms", db("SELECT status,payment_status FROM bookings WHERE id=?", (B6,))[0] == {"status": "confirmed", "payment_status": "paid"})
# authorisation on status changes
c, r = call("bookings.php", "update_status", {"booking_id": B6, "status": "completed"}, C2T)
check("another customer cannot change someone else's booking status", c == 403, (c, r))
c, r = call("bookings.php", "update_status", {"booking_id": B6, "status": "cancelled"}, CT)
check("customer cannot use update_status (must use cancel)", c == 403, (c, r))
c, r = call("bookings.php", "get_one", qs="&id=%d" % B6, token=C2T)
check("another customer cannot read someone else's booking", "error" in r, r)
c, r = call("bookings.php", "get_one", qs="&id=%d" % B6, token=CT); check("owner can read own booking", "booking" in r, r)
# deposit release idempotent
c, r = call("bookings.php", "release_deposit", {"booking_id": int(s1["booking_id"])}, AT)
check("deposit cannot be released twice", "error" in r, r)
# event booking: server-side price
db("INSERT INTO car_location_pricing(car_id,location,price) VALUES(?,?,?)", (car_id, "Municipal", 12000))
ev = {"event": "grovveyard", "event_name": "Test Event", "pickup_address": "A", "dropoff_address": "B", "zone": "municipal", "zone_label": "Municipal", "date": "2026-12-01", "time": "20:00", "passengers": 2, "car_id": car_id, "selected_car": "Camry", "price": 1}
c, r = call("event-booking.php", "", ev)
check("event booking ignores the client price (sent 1, charged 12000)", r.get("success") and r.get("final_price") == 12000, r)
ev2 = dict(ev, zone_label="Nowhere", price=5)
c, r = call("event-booking.php", "", ev2); check("event booking with no admin price is refused (negotiate)", "error" in r, r)
ev3 = dict(ev, price=-5); c, r = call("event-booking.php", "", ev3); check("negative client price cannot produce a negative booking", r.get("final_price") == 12000 or "error" in r, r)

print("\nSUMMARY: %d checks, %d pass, %d fail" % (len(results), sum(1 for _, o, _ in results if o), sum(1 for _, o, _ in results if not o)))
