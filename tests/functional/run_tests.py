#!/usr/bin/env python3
"""BOOKKAM functional tests. Local only: no network beyond 127.0.0.1, no production data,
no real payment credentials, no real payment provider.

It copies api/ to a temp dir, swaps ONLY config/db.php for a SQLite stand-in, creates a
throw-away database from tests/functional/schema.sql and starts the real PHP code twice:
  PROD server: APP_ENV=production  (simulator, test payments and test top-ups must be refused)
  DEV  server: APP_ENV=development (payment simulator + test wallet credits available)
Provider results are scripted by the development-only SimulatorPaymentProvider.

Run from the repo root:   python3 tests/functional/run_tests.py
Exit code is non-zero if any check fails. SQLite is a stand-in: MySQL-only SQL
(request_payout DATE_SUB, discount-code FOR UPDATE) is not exercised, and SQLite locking only
approximates MySQL row locking in the concurrency checks (invariants are asserted, not timing).
"""
import base64, concurrent.futures as cf, hashlib, hmac, json, os, re, shutil, sqlite3, subprocess
import sys, tempfile, time, urllib.error, urllib.request

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
TMP = tempfile.mkdtemp(prefix="bookkam-test-")
TESTBED = os.path.join(TMP, "testbed")
DB = os.path.join(TMP, "t.db")
JWT = "unit-test-jwt-secret-0123456789abcdef0123456789abcdef"
PROD, DEV = "http://127.0.0.1:18099", "http://127.0.0.1:18098"
results, procs = [], []

# ───────────────────────── infrastructure ─────────────────────────
STUB_DB = '''<?php
$pdo = new PDO("sqlite:" . getenv("TESTBED_DB"), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction("NOW", fn() => date("Y-m-d H:i:s"), 0);
$pdo->sqliteCreateFunction("CURDATE", fn() => date("Y-m-d"), 0);
'''

def setup():
    shutil.copytree(os.path.join(ROOT, "api"), TESTBED, ignore=shutil.ignore_patterns(".env", ".git"))
    open(os.path.join(TESTBED, "config", "db.php"), "w").write(STUB_DB)
    c = sqlite3.connect(DB)
    c.executescript(open(os.path.join(ROOT, "tests", "functional", "schema.sql")).read())
    c.commit(); c.close()

def start(port, env_extra, docroot=None):
    env = {k: v for k, v in os.environ.items() if not k.startswith(("APP_ENV", "JWT_", "OPAY", "ADMIN_PORTAL"))}
    env.update({"TESTBED_DB": DB, "PHP_CLI_SERVER_WORKERS": "4"}); env.update(env_extra)
    p = subprocess.Popen(["php", "-S", "127.0.0.1:%d" % port, "-t", docroot or TESTBED], env=env,
                         stdout=subprocess.DEVNULL, stderr=open(os.path.join(TMP, "server-%d.log" % port), "a"))
    procs.append(p)
    for _ in range(50):
        try: urllib.request.urlopen("http://127.0.0.1:%d/" % port, timeout=1); break
        except urllib.error.HTTPError: break
        except Exception: time.sleep(0.1)
    return p

def stop(p):
    p.terminate()
    try: p.wait(5)
    except Exception: p.kill()

def call(base, ep, action, body=None, token=None, qs="", raw=None, headers=None):
    url = base + "/api/" + ep + "?action=" + action + qs
    data = raw if raw is not None else (json.dumps(body).encode() if body is not None else None)
    req = urllib.request.Request(url, data=data, method="POST" if data is not None else "GET")
    req.add_header("Content-Type", "application/json")
    if token: req.add_header("Authorization", "Bearer " + token)
    for k, v in (headers or {}).items(): req.add_header(k, v)
    try:
        with urllib.request.urlopen(req, timeout=20) as r: code, txt = r.status, r.read().decode()
    except urllib.error.HTTPError as e: code, txt = e.code, e.read().decode()
    try: js = json.loads(txt)
    except Exception: js = {"_raw": txt[:200]}
    return code, js

def P(*a, **k): return call(PROD, *a, **k)
def D(*a, **k): return call(DEV, *a, **k)

def db(sql, args=()):
    c = sqlite3.connect(DB, timeout=30); c.row_factory = sqlite3.Row
    try:
        rows = c.execute(sql, args).fetchall(); c.commit()
        return [dict(r) for r in rows]
    finally:
        c.close()  # always release the lock, even when the statement raises

def check(name, ok, detail=""):
    results.append((name, bool(ok), detail))
    print(("PASS " if ok else "FAIL ") + name + ((" :: " + str(detail)[:230]) if (detail != "" and not ok) else ""))

def wallet(email): return db("SELECT wallet_balance w FROM users WHERE email=?", (email,))[0]["w"]

def make_jwt(secret, user_id, role, exp_delta=3600):
    b = lambda d: base64.b64encode(json.dumps(d, separators=(",", ":")).encode()).decode()
    h, p = b({"alg": "HS256", "typ": "JWT"}), b({"user_id": user_id, "role": role, "exp": int(time.time()) + exp_delta, "iat": int(time.time())})
    sig = base64.urlsafe_b64encode(hmac.new(secret.encode(), (h + "." + p).encode(), hashlib.sha256).digest()).decode().rstrip("=")
    return h + "." + p + "." + sig

def make_pending(secret, provider="google", sub="g-123", role="customer", email="social@test.local", exp_delta=900, typ="social_pending"):
    b = lambda d: base64.b64encode(json.dumps(d, separators=(",", ":")).encode()).decode()
    h = b({"alg": "HS256", "typ": "JWT"})
    pl = b({"typ": typ, "provider": provider, "sub": sub, "email": email, "name": "Social User", "photo": None, "role": role, "exp": int(time.time()) + exp_delta, "iat": int(time.time())})
    sig = base64.urlsafe_b64encode(hmac.new(secret.encode(), (h + "." + pl).encode(), hashlib.sha256).digest()).decode().rstrip("=")
    return h + "." + pl + "." + sig

def parallel(n, fn):
    with cf.ThreadPoolExecutor(n) as ex: return list(ex.map(lambda _: fn(), range(n)))

# ───────────────────────── run ─────────────────────────
def main():
    setup()
    prod_env = {"APP_ENV": "production", "JWT_SECRET": JWT}
    start(18099, prod_env); start(18098, dict(prod_env, APP_ENV="development"))

    # ---- accounts ----
    pw = subprocess.check_output(["php", "-r", "echo password_hash('Adm1n-test-only',PASSWORD_BCRYPT);"]).decode()
    db("INSERT INTO users(email,name,role,city,wallet_balance,password_hash) VALUES('admin@test.local','Test Admin','admin','Calabar',0,?)", (pw,))
    for t, col, v in [("hourly", "per_hour_price", 5000), ("daily", "per_day_price", 30000)]:
        db("INSERT INTO pricing(city,booking_type,%s) VALUES('Calabar',?,?)" % col, (t, v))
    db("INSERT INTO pricing(city,booking_type,base_price) VALUES('Calabar','chauffeur_trip',15000)")
    def reg(n, e, role):   # e-mail accounts start unverified: register, mark the address verified (the e-mail link is covered by email_auth_tests.py), sign in
        P("auth.php", "register_email", {"name": n, "email": e, "password": "secret123", "confirm": "secret123", "role": role, "city": "Calabar"})
        db("UPDATE users SET email_verified_at=datetime('now') WHERE email=?", (e,))
        return P("auth.php", "login_email", {"email": e, "password": "secret123"})[1]
    c, adm = P("auth.php", "admin_login", {"email": "admin@test.local", "password": "Adm1n-test-only"}); AT = adm.get("token")
    check("admin login works", c == 200 and AT)
    cu = reg("Cust One", "c1@test.local", "customer"); CT = cu["token"]
    C2T = reg("Cust Two", "c2@test.local", "customer")["token"]
    dr = reg("Driver One", "d1@test.local", "driver"); DT = dr["token"]
    D2T = reg("Driver Two", "d2@test.local", "driver")["token"]
    drv_id = db("SELECT id FROM drivers ORDER BY id")[0]["id"]
    P("cars.php", "driver_save", {"name": "Toyota Camry", "plate_number": "CAL-001", "year": 2019, "make": "Toyota", "model": "Camry"}, DT)
    P("admin.php", "update_driver_status", {"driver_id": drv_id, "status": "active"}, AT)
    P("drivers.php", "toggle_online", {"is_online": 1}, DT)
    c, r = P("cars.php", "get_all", qs="&type=chauffeur&city=Calabar", token=CT)
    check("active+online driver's car listed to customers", len(r.get("cars", [])) == 1); car_id = int(r["cars"][0]["id"])
    db("UPDATE users SET wallet_balance=0 WHERE role='customer'")

    def new_booking(token=CT, addr="Marian Rd"):
        c, b = P("bookings.php", "create", {"car_id": car_id, "booking_type": "trip", "pickup_address": addr, "city": "Calabar"}, token)
        return int(b["booking_id"]) if "booking_id" in b else None
    def free_car():
        db("UPDATE bookings SET status='cancelled' WHERE car_id=? AND status IN ('pending','confirmed','active')", (car_id,))
        db("UPDATE cars SET is_available=1 WHERE id=?", (car_id,))
    def bk(i): return db("SELECT status,payment_status FROM bookings WHERE id=?", (i,))[0]
    def sim(booking, scenario, server=D, token=CT, btype="website"):
        c, r = server("payments.php", "initiate", {"booking_id": booking, "method": "simulator", "scenario": scenario, "booking_type": btype}, token)
        return r.get("reference"), c, r

    # ================= A. BOOKING SECURITY =================
    B1 = new_booking()
    check("A: trip booking priced from the server table (15000)", db("SELECT total_price t FROM bookings WHERE id=?", (B1,))[0]["t"] == 15000)
    free_car()
    c, b = P("bookings.php", "create", {"car_id": car_id, "booking_type": "trip", "pickup_address": "Tamper", "city": "Calabar", "total_price": 1, "price": 1, "amount": 1, "customer_id": 99}, CT)
    tb = db("SELECT total_price t, customer_id c FROM bookings WHERE id=?", (int(b["booking_id"]),))[0]
    check("A: client-supplied price/customer_id ignored on booking create", tb["t"] == 15000 and tb["c"] != 99, tb)
    free_car(); B1 = new_booking(); P("payments.php", "initiate", {"booking_id": B1, "method": "cash", "booking_type": "website"}, CT)

    c, r = P("bookings.php", "get_one", qs="&id=%d" % B1, token=C2T); check("A: customer cannot read another customer's booking", "error" in r and "booking" not in r, r)
    c, r = P("bookings.php", "get_one", qs="&id=%d" % B1, token=CT); check("A: owner can read own booking", "booking" in r)
    c, r = P("bookings.php", "get_my_bookings", token=C2T); check("A: booking list never shows another customer's bookings", all(x["id"] != B1 for x in r.get("bookings", [])))
    c, r = P("bookings.php", "update_status", {"booking_id": B1, "status": "cancelled"}, C2T); check("A: other customer cannot change status", c == 403, (c, r))
    c, r = P("bookings.php", "update_status", {"booking_id": B1, "status": "cancelled"}, CT); check("A: customer cannot use update_status (must use cancel)", c == 403, (c, r))
    c, r = P("bookings.php", "update_status", {"booking_id": B1, "status": "confirmed"}, D2T); check("A: unrelated driver cannot act on the booking", c == 403, (c, r))
    c, r = P("bookings.php", "update_status", {"booking_id": B1, "status": "completed"}, DT); check("A: invalid transition pending->completed refused (driver)", "error" in r and bk(B1)["status"] == "pending", r)
    c, r = P("bookings.php", "update_status", {"booking_id": B1, "status": "bogus"}, DT); check("A: unknown status value rejected", "error" in r, r)
    c, r = P("bookings.php", "release_deposit", {"booking_id": B1}, CT); check("A: customer cannot release a deposit", c == 403, (c, r))
    c, r = P("admin.php", "get_customers", token=CT); check("A: customer token cannot use admin endpoints", c == 403, c)
    c, r = P("admin.php", "get_customers"); check("A: anonymous cannot use admin endpoints", c == 401, c)
    c, r = P("bookings.php", "update_status", {"booking_id": B1, "status": "confirmed"}, DT); check("A: assigned driver can confirm", r.get("success"), r)
    c, r = P("bookings.php", "update_status", {"booking_id": B1, "status": "active"}, DT); check("A: driver starts trip", r.get("success"), r)
    c, r = P("bookings.php", "update_status", {"booking_id": B1, "status": "completed"}, DT); check("A: driver completes trip", r.get("success"), r)
    c, r = P("bookings.php", "update_status", {"booking_id": B1, "status": "active"}, DT); check("A: completed booking cannot go back to active", "error" in r and bk(B1)["status"] == "completed", r)
    c, r = P("bookings.php", "update_status", {"booking_id": B1, "status": "cancelled"}, AT); check("A: completed booking cannot be cancelled even by admin", "error" in r and bk(B1)["status"] == "completed", r)
    check("A: car released after completion", db("SELECT is_available a FROM cars WHERE id=?", (car_id,))[0]["a"] == 1)
    c, r = P("drivers.php", "get_earnings", qs="&period=today", token=DT)
    check("A: driver earnings 15000 total / 3000 commission", r.get("earnings", {}).get("total") == 15000 and r["earnings"]["commission"] == 3000, r.get("earnings"))

    # availability + double booking
    free_car(); B2 = new_booking()
    check("A: car held while a booking is open", db("SELECT is_available a FROM cars WHERE id=?", (car_id,))[0]["a"] == 0)
    c, r = P("bookings.php", "create", {"car_id": car_id, "booking_type": "trip", "pickup_address": "B", "city": "Calabar"}, C2T)
    check("A: second customer cannot book the held car", "error" in r, r)
    c, r = P("bookings.php", "cancel", {"booking_id": B2}, CT)
    check("A: cancel releases the car", r.get("success") and db("SELECT is_available a FROM cars WHERE id=?", (car_id,))[0]["a"] == 1, r)
    c, r = P("bookings.php", "cancel", {"booking_id": B2}, CT); check("A: repeated cancellation refused", "error" in r, r)

    # paid booking cancel + refund exactly once, concurrent cancel
    free_car(); db("UPDATE users SET wallet_balance=50000 WHERE email='c1@test.local'")
    B3 = new_booking(); P("payments.php", "initiate", {"booking_id": B3, "method": "wallet", "booking_type": "website"}, CT)
    check("A: wallet payment debits once (50000->35000), booking confirmed+paid", wallet("c1@test.local") == 35000 and bk(B3) == {"status": "confirmed", "payment_status": "paid"}, wallet("c1@test.local"))
    c, r = P("payments.php", "initiate", {"booking_id": B3, "method": "wallet", "booking_type": "website"}, CT)
    check("A: duplicate wallet payment refused, no second debit", "error" in r and wallet("c1@test.local") == 35000, r)
    res = parallel(6, lambda: P("bookings.php", "cancel", {"booking_id": B3}, CT)[1])
    check("A: concurrent cancellation refunds exactly once (35000->50000)", wallet("c1@test.local") == 50000 and bk(B3)["status"] == "cancelled",
          "wallet=%s status=%s ok=%d" % (wallet("c1@test.local"), bk(B3)["status"], sum(1 for x in res if x.get("success"))))
    check("A: at most one concurrent cancel reported success", sum(1 for x in res if x.get("success")) == 1)
    c, r = P("bookings.php", "update_status", {"booking_id": B3, "status": "confirmed"}, AT); check("A: cancelled booking cannot be revived", "error" in r and bk(B3)["status"] == "cancelled", r)

    # self-drive deposit
    c, r = P("cars.php", "admin_add", {"name": "Prado", "car_type": "self_drive", "plate_number": "SD-01", "city": "Calabar", "security_deposit": 20000}, AT); SD = int(r["id"])
    db("UPDATE users SET wallet_balance=100000 WHERE email='c1@test.local'")
    c, s1 = P("bookings.php", "create", {"car_id": SD, "booking_type": "daily", "pickup_address": "Office", "duration_days": 2, "city": "Calabar", "car_type": "self_drive"}, CT)
    check("A: rental total 60000 + deposit 20000", s1.get("total") == 60000 and s1.get("deposit") == 20000, s1); S1 = int(s1["booking_id"])
    P("payments.php", "initiate", {"booking_id": S1, "method": "wallet", "booking_type": "website"}, CT)
    check("A: wallet pays total+deposit (100000->20000)", wallet("c1@test.local") == 20000, wallet("c1@test.local"))
    c, r = P("bookings.php", "release_deposit", {"booking_id": S1}, AT); check("A: deposit cannot be released before completion", "error" in r and wallet("c1@test.local") == 20000, r)
    P("bookings.php", "update_status", {"booking_id": S1, "status": "completed"}, AT)
    res = parallel(5, lambda: P("bookings.php", "release_deposit", {"booking_id": S1}, AT)[1])
    check("A: concurrent deposit release credits exactly once (+20000)", wallet("c1@test.local") == 40000 and sum(1 for x in res if x.get("success")) == 1, wallet("c1@test.local"))
    db("UPDATE bookings SET deposit_released=0, deposit_refund_amount=NULL WHERE id=?", (S1,)); db("UPDATE users SET wallet_balance=0 WHERE email='c1@test.local'")
    c, r1 = P("admin.php", "add_damage_report", {"booking_id": S1, "description": "dent", "repair_cost": 5000}, AT)
    c, r2 = P("admin.php", "add_damage_report", {"booking_id": S1, "description": "dent again", "repair_cost": 5000}, AT)
    check("A: repeated damage report cannot credit the deposit twice (15000 once)", wallet("c1@test.local") == 15000, wallet("c1@test.local"))
    c, r = P("admin.php", "add_damage_report", {"booking_id": S1, "description": "neg", "repair_cost": -50}, AT); check("A: negative repair cost rejected", "error" in r, r)

    # event pricing
    db("INSERT INTO car_location_pricing(car_id,location,price) VALUES(?,?,?)", (car_id, "Municipal", 12000))
    ev = {"event": "grovveyard", "event_name": "Test Event", "pickup_address": "A", "dropoff_address": "B", "zone": "municipal", "zone_label": "Municipal", "date": "2026-12-01", "time": "20:00", "passengers": 2, "car_id": car_id, "selected_car": "Camry", "price": 1}
    c, r = P("event-booking.php", "", ev); check("A: event price comes from the database (client sent 1)", r.get("final_price") == 12000, r)
    c, r = P("event-booking.php", "", dict(ev, zone_label="Nowhere")); check("A: event booking refused when no authoritative price exists", "error" in r, r)
    c, r = P("event-booking.php", "", dict(ev, price=-5)); check("A: negative client price cannot reduce the price", r.get("final_price") == 12000 or "error" in r, r)
    c, r = P("event-booking.php", "", dict(ev, passengers=0)); check("A: invalid passenger count rejected", "error" in r, r)
    c, r = P("event-booking.php", "", dict(ev, date="tomorrow")); check("A: invalid date rejected", "error" in r, r)
    c, r = P("event-booking.php", "", dict(ev, car_id=0)); check("A: missing car for a non-package event rejected", "error" in r, r)
    # SQL-injection-shaped input must not break or leak
    c, r = P("event-booking.php", "", dict(ev, pickup_address="x'); DROP TABLE bookings;--")); check("A: injection-shaped text stored literally, tables intact", db("SELECT COUNT(*) n FROM bookings")[0]["n"] > 0 and r.get("success"), r)

    # driver offline/suspended
    P("drivers.php", "toggle_online", {"is_online": 0}, DT); free_car()
    c, r = P("bookings.php", "create", {"car_id": car_id, "booking_type": "trip", "pickup_address": "X", "city": "Calabar"}, CT)
    check("A: cannot book a car whose driver is offline", "error" in r, r)
    P("drivers.php", "toggle_online", {"is_online": 1}, DT)

    # ================= B. PAYMENT SECURITY (provider results scripted by the simulator) =================
    free_car(); db("UPDATE users SET wallet_balance=0 WHERE email='c1@test.local'")
    bid = new_booking(); ref, c, r = sim(bid, "success")
    check("B: initiation alone does not confirm a booking", c == 200 and bk(bid)["status"] == "pending" and db("SELECT status s FROM payments WHERE provider_reference=?", (ref,))[0]["s"] == "pending", r)
    c, r = D("payments.php", "verify", qs="&reference=" + ref); check("B: verified success settles booking (confirmed+paid)", r.get("status") == "success" and bk(bid) == {"status": "confirmed", "payment_status": "paid"}, r)
    check("B: payment row success stored under provider_reference", db("SELECT status s, method m, is_test t FROM payments WHERE provider_reference=?", (ref,))[0] == {"s": "success", "m": "test", "t": 1})
    c, r = D("payments.php", "verify", qs="&reference=" + ref); n = db("SELECT COUNT(*) n FROM payments WHERE provider_reference=?", (ref,))[0]["n"]
    check("B: duplicate verification is harmless (one row, no wallet change)", r.get("status") == "success" and n == 1 and wallet("c1@test.local") == 0, r)
    P("bookings.php", "cancel", {"booking_id": bid}, CT); free_car(); db("UPDATE users SET wallet_balance=0 WHERE email='c1@test.local'")

    for scenario, code, expect_status in [("mismatch", 409, "mismatch"), ("currency", 409, "mismatch"), ("malformed", 409, "mismatch"),
                                          ("failed", 402, "failed"), ("cancelled", 402, "cancelled"), ("pending", 202, "pending"), ("timeout", 502, "unavailable")]:
        free_car(); b_ = new_booking(); ref_, _, _ = sim(b_, scenario)
        c, r = D("payments.php", "verify", qs="&reference=" + ref_)
        check("B: provider result '%s' -> HTTP %d, booking untouched" % (scenario, code), c == code and r.get("status") == expect_status and bk(b_) == {"status": "pending", "payment_status": "unpaid"} and
              db("SELECT status s FROM payments WHERE provider_reference=?", (ref_,))[0]["s"] == "pending", (c, r, bk(b_)))
    free_car(); b_ = new_booking()
    c, r = D("payments.php", "verify", qs="&reference=SIM-success-1500000-doesnotexist"); check("B: unknown provider reference -> 404, nothing changes", c == 404 and bk(b_)["status"] == "pending", (c, r))
    c, r = D("payments.php", "verify", qs="&reference=garbage"); check("B: unrecognised reference format rejected", c == 404, (c, r))
    c, r = D("payments.php", "verify"); check("B: missing reference rejected", "error" in r, r)

    # concurrent duplicate verification settles once
    P("bookings.php", "cancel", {"booking_id": b_}, CT); free_car(); db("UPDATE users SET wallet_balance=0 WHERE email='c1@test.local'")
    b_ = new_booking(); ref_, _, _ = sim(b_, "success")
    res = parallel(6, lambda: D("payments.php", "verify", qs="&reference=" + ref_))
    check("B: concurrent duplicate verification settles exactly once", bk(b_) == {"status": "confirmed", "payment_status": "paid"} and wallet("c1@test.local") == 0 and
          db("SELECT COUNT(*) n FROM payments WHERE provider_reference=? AND status='success'", (ref_,))[0]["n"] == 1, [x[0] for x in res])

    # payment for ineligible bookings
    P("bookings.php", "cancel", {"booking_id": b_}, CT)
    c, r = D("payments.php", "initiate", {"booking_id": b_, "method": "simulator", "scenario": "success", "booking_type": "website"}, CT); check("B: cancelled booking cannot be paid", "error" in r, r)
    c, r = D("payments.php", "initiate", {"booking_id": b_, "method": "wallet", "booking_type": "website"}, CT); check("B: cancelled booking cannot be paid by wallet", "error" in r, r)
    c, r = D("payments.php", "initiate", {"booking_id": b_, "method": "cash", "booking_type": "website"}, C2T); check("B: another customer cannot start a payment on this booking", "error" in r, r)
    c, r = D("payments.php", "initiate", {"booking_id": b_, "method": "cash", "booking_type": "website"}); check("B: anonymous cannot start a website payment", c == 401, c)

    # late payment on a cancelled booking -> wallet credit, booking stays cancelled, once
    free_car(); db("UPDATE users SET wallet_balance=0 WHERE email='c1@test.local'")
    b_ = new_booking(); ref_, _, _ = sim(b_, "success"); P("bookings.php", "cancel", {"booking_id": b_}, CT)
    c, r = D("payments.php", "verify", qs="&reference=" + ref_)
    check("B: late payment on cancelled booking is credited to the wallet, booking stays cancelled", bk(b_)["status"] == "cancelled" and wallet("c1@test.local") == 15000 and r.get("status") == "refunded", (bk(b_), wallet("c1@test.local"), r))
    D("payments.php", "verify", qs="&reference=" + ref_)
    check("B: repeating the late payment does not credit twice", wallet("c1@test.local") == 15000, wallet("c1@test.local"))
    check("B: late-payment policy: car not re-held by a cancelled booking", db("SELECT is_available a FROM cars WHERE id=?", (car_id,))[0]["a"] == 1)

    # settlement failure: a DB error while settling must roll back everything (payment stays pending)
    free_car(); db("UPDATE users SET wallet_balance=0 WHERE email='c1@test.local'")
    b_ = new_booking(); ref_, _, _ = sim(b_, "success")
    db("CREATE TRIGGER fail_settle BEFORE UPDATE OF payment_status ON bookings BEGIN SELECT RAISE(ABORT,'forced settlement failure'); END;")
    c, r = D("payments.php", "verify", qs="&reference=" + ref_)
    st = db("SELECT status s FROM payments WHERE provider_reference=?", (ref_,))[0]["s"]
    check("B: settlement failure -> 500, payment stays pending, booking not confirmed, wallet unchanged", c == 500 and st == "pending" and bk(b_)["status"] == "pending" and wallet("c1@test.local") == 0, (c, st, bk(b_)))
    db("DROP TRIGGER fail_settle")
    c, r = D("payments.php", "verify", qs="&reference=" + ref_); check("B: after the fault clears, retry settles correctly", r.get("status") == "success" and bk(b_)["payment_status"] == "paid", r)

    # production safety of payment endpoints
    free_car(); b_ = new_booking()
    c, r = P("payments.php", "initiate", {"booking_id": b_, "method": "simulator", "scenario": "success", "booking_type": "website"}, CT); check("B: simulator refused in production", c == 403, (c, r))
    c, r = P("payments.php", "initiate", {"booking_id": b_, "method": "test", "booking_type": "website"}, CT); check("B: 'test' payments refused in production", "error" in r and bk(b_)["payment_status"] == "unpaid", r)
    c, r = P("payments.php", "verify", qs="&reference=SIM-success-1500000-x"); check("B: simulator references are unknown in production", c == 404, (c, r))
    c, r = P("payments.php", "initiate", {"booking_id": b_, "method": "opay", "booking_type": "website"}, CT); check("B: OPay disabled -> 503, no payment row", c == 503 and db("SELECT COUNT(*) n FROM payments WHERE booking_id=? AND method='opay'", (b_,))[0]["n"] == 0, (c, r))
    c, r = P("payments.php", "initiate", {"booking_id": b_, "method": "paystack", "booking_type": "website"}, CT); check("B: Paystack no longer accepted as a method", "error" in r, r)
    c, r = P("payments.php", "webhook", raw=b'{"event":"charge.success","data":{"reference":"x","amount":1,"currency":"NGN"}}'); check("B: legacy Paystack webhook endpoint is gone", c == 400 and "error" in r, (c, r))
    ref_, _, _ = sim(b_, "success")
    c, r = P("payments.php", "opay_webhook", raw=json.dumps({"reference": ref_}).encode(), headers={"x-signature": "forged"})
    check("B: forged OPay webhook (provider disabled) changes nothing", c == 200 and bk(b_)["payment_status"] == "unpaid", (c, r))
    c, r = D("payments.php", "opay_webhook", raw=json.dumps({"reference": ref_}).encode()); check("B: OPay webhook cannot settle a non-OPay reference", bk(b_)["payment_status"] == "unpaid", (c, r))
    c, r = P("payments.php", "initiate", {"booking_id": b_, "method": "cash", "booking_type": "website"}, CT); c, r = P("payments.php", "initiate", {"booking_id": b_, "method": "cash", "booking_type": "website"}, CT)
    check("B: repeated cash initiation keeps a single pending row", db("SELECT COUNT(*) n FROM payments WHERE booking_id=? AND method='cash'", (b_,))[0]["n"] == 1)
    check("B: unique index rejects a duplicate provider reference (migration 007 equivalent)", _dup_rejected())
    # event booking payment on the DEV simulator
    c, ev_r = P("event-booking.php", "", ev); EB = ev_r["booking_id"]
    ref_, c, r = sim(EB, "success", token=None, btype="event")
    c, r = D("payments.php", "verify", qs="&reference=" + ref_)
    check("B: event booking settles via verified provider result", r.get("status") == "success" and db("SELECT status s FROM event_bookings WHERE id=?", (EB,))[0]["s"] == "confirmed", r)
    ref2, _, _ = sim(EB, "success", token=None, btype="event")
    check("B: confirmed event booking cannot be paid again", ref2 is None)
    # fixed payment_status values
    check("B: payment_status only ever 'paid' or 'unpaid' in tests", {r_["payment_status"] for r_ in db("SELECT DISTINCT payment_status FROM bookings")} <= {"paid", "unpaid", None})

    # ================= C. WALLET =================
    c, r = P("payments.php", "get_methods"); check("C: production reports test mode/top-up off, OPay off", r == {"opay": False, "wallet_top_up": False, "test_mode": False}, r)
    before = wallet("c1@test.local"); rows = db("SELECT COUNT(*) n FROM payments")[0]["n"]
    for body in ({"amount": 5000}, {"amount": 5000, "method": "test"}, {"amount": 1, "idempotency_key": "abcdefgh1234"}, {"amount": -5}):
        c, r = P("payments.php", "top_up_wallet", body, CT)
        check("C: production top-up refused (%s)" % json.dumps(body), c == 503 and wallet("c1@test.local") == before and db("SELECT COUNT(*) n FROM payments")[0]["n"] == rows, (c, r))
    c, r = P("payments.php", "top_up_wallet", {"amount": 5000}); check("C: anonymous top-up refused", c == 401, c)
    db("UPDATE users SET wallet_balance=0 WHERE email='c2@test.local'")
    c, r = D("payments.php", "top_up_wallet", {"amount": 5000, "idempotency_key": "key-0001-aaaa"}, C2T)
    row = db("SELECT method,status,is_test,booking_kind,provider_reference p FROM payments WHERE provider_reference LIKE 'test_wallet_%' ORDER BY id DESC LIMIT 1")[0]
    check("C: dev top-up credits once and writes a clearly labelled test payment row", r.get("success") and wallet("c2@test.local") == 5000 and row["method"] == "test" and row["is_test"] == 1 and row["booking_kind"] == "topup" and row["status"] == "success", (r, row))
    c, r = D("payments.php", "top_up_wallet", {"amount": 5000, "idempotency_key": "key-0001-aaaa"}, C2T); check("C: same idempotency key credits only once", r.get("credited") is False and wallet("c2@test.local") == 5000, r)
    res = parallel(6, lambda: D("payments.php", "top_up_wallet", {"amount": 700, "idempotency_key": "key-race-0002"}, C2T)[1])
    check("C: concurrent same-key top-ups credit once (5000->5700)", wallet("c2@test.local") == 5700, wallet("c2@test.local"))
    for amt in (0, -1, "abc", 1000001, None, "NaN", "1e999"):
        c, r = D("payments.php", "top_up_wallet", {"amount": amt}, C2T); check("C: invalid/excessive amount %r rejected" % (amt,), "error" in r and wallet("c2@test.local") == 5700, (c, r))
    c, r = D("payments.php", "top_up_wallet", {"amount": 10, "idempotency_key": "bad key!"}, C2T); check("C: malformed idempotency key rejected", c == 422, (c, r))
    tot = db("SELECT COALESCE(SUM(amount),0) s FROM payments WHERE booking_kind='topup' AND provider_reference LIKE ?", ("test_wallet_%d_%%" % db("SELECT id FROM users WHERE email='c2@test.local'")[0]["id"],))[0]["s"]
    check("C: wallet balance equals the sum of its recorded test credits", tot == wallet("c2@test.local") == 5700, (tot, wallet("c2@test.local")))
    c, r = D("payments.php", "get_methods"); check("C: dev server exposes test mode", r.get("test_mode") is True and r.get("wallet_top_up") is True, r)

    # ================= D. AUTHENTICATION / CONFIGURATION =================
    uid = db("SELECT id FROM users WHERE email='c1@test.local'")[0]["id"]
    c, r = P("bookings.php", "get_my_bookings", token="not.a.jwt"); check("D: garbage token treated as unauthenticated", c == 401, c)
    c, r = P("bookings.php", "get_my_bookings", token=make_jwt(JWT, uid, "customer", -10)); check("D: expired token rejected", c == 401, c)
    c, r = P("bookings.php", "get_my_bookings", token=make_jwt(JWT, uid, "customer")); check("D: validly signed token accepted", c == 200, c)
    c, r = P("bookings.php", "get_my_bookings", token=make_jwt("bookkam_dev_secret_not_for_production", uid, "customer")); check("D: token signed with the old dev fallback secret rejected in production", c == 401, c)
    tok = make_jwt(JWT, uid, "customer").split("."); forged = make_jwt(JWT, db("SELECT id FROM users WHERE role='admin'")[0]["id"], "admin").split(".")
    c, r = P("admin.php", "get_customers", token=".".join([tok[0], forged[1], tok[2]])); check("D: payload swapped under another signature rejected", c == 401, c)
    c, r = P("admin.php", "get_customers", token=make_jwt(JWT, uid, "admin")); check("D: role claim in the token is not trusted (DB role used)", c == 403, c)
    for a in ("send_otp", "verify_otp", "login"):
        c, r = P("auth.php", a, {"phone": "2348000000000", "otp": "123456"}); check("D: phone OTP action '%s' no longer exists" % a, c == 400 and "token" not in r and r.get("error") == "Invalid action", (c, r))
    for name, env, expect in [("JWT_SECRET missing", {"APP_ENV": "production"}, 500), ("JWT_SECRET placeholder", {"APP_ENV": "production", "JWT_SECRET": "REPLACE_WITH_A_UNIQUE_RANDOM_SECRET_0123456789"}, 500),
                              ("JWT_SECRET too short", {"APP_ENV": "production", "JWT_SECRET": "short"}, 500), ("APP_ENV unset (fail closed)", {"JWT_SECRET": ""}, 500),
                              ("APP_ENV typo 'prod' (fail closed)", {"APP_ENV": "prod"}, 500), ("APP_ENV 'staging' (fail closed)", {"APP_ENV": "staging"}, 500)]:
        p = start(18097, env); c, r = call("http://127.0.0.1:18097", "bookings.php", "get_my_bookings"); stop(p)
        check("D: %s -> server refuses to run (HTTP %d)" % (name, expect), c == expect, (c, r))
    p = start(18097, {"APP_ENV": "development"}); c, r = call("http://127.0.0.1:18097", "payments.php", "get_methods"); stop(p)
    check("D: dev fallback secret only when APP_ENV=development is explicit", c == 200 and r.get("test_mode") is True, (c, r))
    # database settings copied from the placeholder template must not be used
    cfgdir = os.path.join(TMP, "dbcfg"); os.makedirs(os.path.join(cfgdir, "config"))
    shutil.copy(os.path.join(ROOT, "api", "config", "db.php"), os.path.join(cfgdir, "config", "db.php"))
    open(os.path.join(cfgdir, ".env"), "w").write("DB_HOST=127.0.0.1\nDB_USER=REPLACE_WITH_DATABASE_USER\nDB_PASS=REPLACE_WITH_DATABASE_PASSWORD\nDB_NAME=bookkam\n")
    out = subprocess.run(["php", "-r", "require '%s/config/db.php'; echo 'CONNECTED';" % cfgdir], capture_output=True, text=True)
    check("D: placeholder database settings are refused", "placeholders" in out.stdout and "CONNECTED" not in out.stdout, out.stdout[:120])
    # admin portal key (index.php)
    def portal(env, query):
        p = start(18097, dict({"APP_ENV": "production", "JWT_SECRET": JWT}, **env))
        try:
            html = urllib.request.urlopen("http://127.0.0.1:18097/index.php" + query, timeout=10).read().decode()
        finally: stop(p)
        m = re.search(r"window\.ADMIN_PORTAL = (true|false)", html); return m.group(1) if m else html[:80]
    LONG = "kq83-long-random-portal-key-for-tests-only"
    check("D: portal closed when no key is configured, even for the old hard-coded key", portal({}, "?bkp=bk_adm_2026") == "false")
    check("D: portal opens only with the configured key", portal({"ADMIN_PORTAL_KEY": LONG}, "?bkp=" + LONG) == "true")
    check("D: wrong key does not open the portal", portal({"ADMIN_PORTAL_KEY": LONG}, "?bkp=wrong") == "false")
    check("D: old hard-coded key does not open a configured portal", portal({"ADMIN_PORTAL_KEY": LONG}, "?bkp=bk_adm_2026") == "false")
    check("D: placeholder portal key never opens the portal", portal({"ADMIN_PORTAL_KEY": "REPLACE_WITH_A_LONG_RANDOM_SECRET"}, "?bkp=REPLACE_WITH_A_LONG_RANDOM_SECRET") == "false")
    check("D: short portal key never opens the portal", portal({"ADMIN_PORTAL_KEY": "abc"}, "?bkp=abc") == "false")
    grep = subprocess.run(["grep", "-rIl", "bk_adm_2026", os.path.join(ROOT, "api"), os.path.join(ROOT, "tests"), os.path.join(ROOT, "docs")], capture_output=True, text=True).stdout.split()
    grep = [g for g in grep if not g.endswith("run_tests.py")]
    check("D: hard-coded portal key is absent from shipped files", not grep, grep)
    env_ex = open(os.path.join(ROOT, "api", ".env.example")).read()
    check("D: .env.example holds placeholders only (no real-looking secrets)", not re.search(r"(?m)^(JWT_SECRET|DB_PASS|OPAY_SECRET_KEY|ADMIN_PORTAL_KEY)=(?!REPLACE_WITH|$)", env_ex))
    names = set(re.findall(r'env\("([A-Z0-9_]+)"', open(os.path.join(ROOT, "api", "config", "config.php")).read())) | set(re.findall(r'\$_ENV\["([A-Z_]+)"\]', open(os.path.join(ROOT, "api", "config", "db.php")).read())) | {"ADMIN_PORTAL_KEY"}
    declared = set(re.findall(r"(?m)^#?\s*([A-Z][A-Z0-9_]+)=", env_ex))
    check("D: every environment variable the app reads is documented in .env.example", names - {"APP_ENV"} <= declared | {"APP_ENV"}, sorted(names - declared))
    check("D: .env is git-ignored", ".env" in open(os.path.join(ROOT, ".gitignore")).read())

    # ================= E. FRONTEND / API =================
    for ep, act in [("bookings.php", "nope"), ("payments.php", "nope"), ("admin.php", "nope"), ("cars.php", "nope"), ("auth.php", "nope")]:
        c, r = P(ep, act, token=AT); check("E: %s unknown action -> JSON error" % ep, "error" in r and isinstance(r["error"], str), (c, r))
    c, r = P("payments.php", "initiate", {"booking_id": 99999, "method": "cash"}, CT); check("E: unknown booking -> JSON error, no internals", "error" in r and "SQL" not in json.dumps(r) and "PDO" not in json.dumps(r), r)
    out = subprocess.run(["node", os.path.join(ROOT, "tests", "functional", "esc.test.js"), os.path.join(ROOT, "api", "js", "utils.js")], capture_output=True, text=True)
    check("E: esc() neutralises HTML/attribute/template payloads", "ESC_OK" in out.stdout, out.stdout + out.stderr)
    fields = r"(?:name|car_name|customer_name|driver_name|short_name|place_name|pickup_address|dropoff_address|message_text|email|phone|customer_phone|driver_phone|city|state|make|model|plate_number|title|tagline|description|bio|bank_name|account_name|account_number|url|main_photo|photo_url|image_url|context)"
    simple = re.compile(r"\$\{\s*([A-Za-z_][\w]*(?:\??\.[A-Za-z_]\w*)*(?:\s*\|\|\s*(?:\"[^\"]*\"|'[^']*'))?)\s*\}")
    bad = []
    for f in ("admin.js", "app.js", "auth.js", "customer.js", "driver.js", "maps.js"):
        for i, line in enumerate(open(os.path.join(ROOT, "api", "js", f), encoding="utf8").read().split("\n"), 1):
            for m in simple.finditer(line):
                base = re.split(r"\s*\|\|", m.group(1))[0]
                if re.fullmatch(fields, re.split(r"\??\.", base)[-1]): bad.append("%s:%d %s" % (f, i, m.group(0)))
    check("E: no user-controlled text field is interpolated into HTML unescaped", not bad, bad[:5])
    js = open(os.path.join(ROOT, "api", "js", "customer.js"), encoding="utf8").read()
    check("E: 'Simulate Payment' button is gated on server-reported test mode", "methods.test_mode" in js and js.index("methods.test_mode") < js.index("Simulate Payment"))
    check("E: payment return page asks the server, not the browser, for the result", "checkPaymentReturn" in js and "action=verify" in js)
    check("E: bookings list shows payment status and a retry button", "Payment pending" in js and "Pay now" in js)
    check("E: top-up sends an idempotency key and no client 'method'", "idempotency_key" in js and 'method:"test"' not in js)

    # ================= F. BOOKING INPUT VALIDATION, DOUBLE-BOOKING, SOCIAL SIGN-IN =================
    free_car(); n0 = db("SELECT COUNT(*) n FROM bookings")[0]["n"]
    bad_inputs = [({"booking_type": "bogus"}, "unknown booking_type"), ({"booking_type": "hourly", "duration_hours": -5}, "negative hours"),
                  ({"booking_type": "hourly", "duration_hours": 0}, "zero hours"), ({"booking_type": "hourly", "duration_hours": 1e9}, "absurd hours"),
                  ({"booking_type": "daily", "duration_days": 0}, "zero days"), ({"booking_type": "daily", "duration_days": 100000}, "absurd days"),
                  ({"booking_type": "trip", "pickup_address": "x" * 300}, "300-char pickup"), ({"booking_type": "trip", "city": ""}, "empty city")]
    for extra, label in bad_inputs:
        free_car()  # a previous accepted request would lock the car and mask a missing validation
        body = dict({"car_id": car_id, "booking_type": "trip", "pickup_address": "Marian Rd", "city": "Calabar"}, **extra)
        c, r = P("bookings.php", "create", body, CT)
        check("F: booking rejected: %s" % label, c in (400, 422) and "error" in r, (c, r))
    check("F: rejected requests created no booking and did not lock the car", db("SELECT COUNT(*) n FROM bookings")[0]["n"] == n0 and db("SELECT is_available a FROM cars WHERE id=?", (car_id,))[0]["a"] == 1)
    check("F: no negative or zero priced booking exists", db("SELECT COUNT(*) n FROM bookings WHERE total_price<=0")[0]["n"] == 0)

    free_car()
    res = parallel(8, lambda: P("bookings.php", "create", {"car_id": car_id, "booking_type": "trip", "pickup_address": "Race", "city": "Calabar"}, CT)[1])
    check("F: 8 simultaneous bookings of one chauffeur car -> exactly one succeeds", sum(1 for x in res if "booking_id" in x) == 1, [x.get("booking_id") or x.get("error") for x in res])
    check("F: exactly one live booking on the car", db("SELECT COUNT(*) n FROM bookings WHERE car_id=? AND status IN ('pending','confirmed','active')", (car_id,))[0]["n"] == 1)
    c, r = P("cars.php", "admin_add", {"name": "Race Prado", "car_type": "self_drive", "plate_number": "RC-01", "city": "Calabar", "security_deposit": 5000}, AT); sd = int(r["id"])
    res = parallel(8, lambda: P("bookings.php", "create", {"car_id": sd, "booking_type": "daily", "pickup_address": "Race", "duration_days": 1, "city": "Calabar"}, C2T)[1])
    check("F: 8 simultaneous bookings of one self-drive car -> exactly one succeeds", sum(1 for x in res if "booking_id" in x) == 1, [x.get("booking_id") or x.get("error") for x in res])

    # set_city: an anonymous caller must not be able to mint accounts or sessions
    users0 = db("SELECT COUNT(*) n FROM users")[0]["n"]
    c, r = P("auth.php", "set_city", {"city": "Calabar", "google_id": "forged-1", "role": "admin", "email": "evil@test.local", "name": "Evil"})
    check("F: set_city without a server-issued token is refused (401), no session", c == 401 and "token" not in r, (c, r))
    c, r = P("auth.php", "set_city", {"city": "Calabar", "apple_id": "forged-2", "role": "admin"})
    check("F: set_city with a client-chosen apple_id is refused", c == 401 and "token" not in r, (c, r))
    check("F: no user was created by the forged requests", db("SELECT COUNT(*) n FROM users")[0]["n"] == users0)
    c, r = P("auth.php", "set_city", {"city": "Calabar", "pending_token": make_pending("wrong-secret-wrong-secret-wrong-secret-1234")})
    check("F: pending token signed with another secret rejected", c == 401, (c, r))
    c, r = P("auth.php", "set_city", {"city": "Calabar", "pending_token": make_pending(JWT, exp_delta=-10)})
    check("F: expired pending token rejected", c == 401, (c, r))
    c, r = P("auth.php", "set_city", {"city": "Calabar", "pending_token": make_jwt(JWT, 1, "admin")})
    check("F: an ordinary login token is not accepted as a pending token", c == 401, (c, r))
    c, r = P("auth.php", "set_city", {"city": "Calabar", "pending_token": make_pending(JWT, role="admin", sub="g-admin")})
    check("F: a pending token can never create an admin", c == 401 and not db("SELECT 1 FROM users WHERE google_id='g-admin'"), (c, r))
    c, r = P("bookings.php", "get_my_bookings", token=make_pending(JWT))
    check("F: a pending token is not usable as a bearer/login token", c == 401, (c, r))
    pt = make_pending(JWT, sub="g-good", email="good@test.local")
    c, r = P("auth.php", "set_city", {"city": "Calabar", "pending_token": pt, "role": "admin", "google_id": "other", "email": "other@test.local"})
    u = db("SELECT role,google_id,email FROM users WHERE google_id='g-good'")
    check("F: valid pending token creates the account from the TOKEN's identity (client role/id/email ignored)", c == 200 and r.get("token") and u == [{"role": "customer", "google_id": "g-good", "email": "good@test.local"}], (c, r, u))
    c, r = P("auth.php", "set_city", {"city": "Ikom", "pending_token": pt})
    check("F: replaying the pending token does not duplicate the account", c == 200 and db("SELECT COUNT(*) n FROM users WHERE google_id='g-good'")[0]["n"] == 1, (c, r))
    c, r = P("auth.php", "apple_login", {"id_token": "a.eyJzdWIiOiJ4In0.c", "role": "customer"})
    check("F: Sign in with Apple is disabled in production (token signature is not verified)", c == 503 and "token" not in r, (c, r))
    c, r = D("auth.php", "apple_login", {"id_token": "a.eyJzdWIiOiJ4In0.c", "role": "customer"})
    check("F: Apple login in development returns a pending token, not a session", r.get("needs_city") is True and r.get("pending_token") and "token" not in r and "apple_id" not in r, (c, r))
    src = open(os.path.join(ROOT, "api", "api", "auth.php")).read()
    check("F: Google sign-in links an existing account by e-mail only when Google says the e-mail is verified", "email_verified" in src)
    print("NOTE: google_login's call to Google's tokeninfo endpoint is not exercised (no network in tests).")

    for p in procs:
        stop(p)
    shutil.rmtree(TMP, ignore_errors=True)
    f = [n for n, ok, _ in results if not ok]
    print("\nSUMMARY: %d checks, %d pass, %d fail" % (len(results), len(results) - len(f), len(f)))
    sys.exit(1 if f else 0)

def _dup_rejected():
    try:
        db("INSERT INTO payments(booking_id,amount,deposit_amount,method,status,provider_reference) VALUES(1,1,0,'test','pending','DUP-1')")
        db("INSERT INTO payments(booking_id,amount,deposit_amount,method,status,provider_reference) VALUES(2,1,0,'test','pending','DUP-1')")
        return False
    except sqlite3.IntegrityError:
        return True

if __name__ == "__main__":
    try: main()
    finally:
        for p in procs:
            try: stop(p)
            except Exception: pass
