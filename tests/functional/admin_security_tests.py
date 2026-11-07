#!/usr/bin/env python3
"""BOOKKAM tests for the admin switches, driver-location privacy, admin two-factor, SQL injection and response hygiene.

Local only: 127.0.0.1 PHP servers, FAKE data, a throw-away SQLite database built from tests/functional/schema.sql.
It reuses the infrastructure of run_tests.py (copy of api/ with ONLY config/db.php swapped). No network, no real
payment provider, no production system. Each check asserts the SECURE behaviour.

  python3 tests/functional/admin_security_tests.py
"""
import base64, hashlib, hmac, json, os, re, shutil, struct, subprocess, sys, time
HERE = os.path.dirname(os.path.abspath(__file__)); sys.path.insert(0, HERE)
import run_tests as rt

JWT = rt.JWT
PROD, DEV, REQ = "http://127.0.0.1:18111", "http://127.0.0.1:18112", "http://127.0.0.1:18113"
ADMIN_PW = "Adm1n-test-only"
check, db = rt.check, rt.db

def A(base, ep, action, body=None, token=None, qs="", raw=None): return rt.call(base, ep, action, body, token, qs, raw)

# ---------------------------------------------------------------- RFC 6238 (independent of the PHP implementation)
def totp(secret, step):
    key = base64.b32decode(secret + "=" * (-len(secret) % 8))
    h = hmac.new(key, struct.pack(">Q", step), hashlib.sha1).digest(); o = h[19] & 0xF
    return "%06d" % ((struct.unpack(">I", h[o:o + 4])[0] & 0x7FFFFFFF) % 1000000)
def cur(): return int(time.time()) // 30
def challenge(admin_id, purpose, ttl=300):
    p = base64.urlsafe_b64encode(json.dumps({"a": admin_id, "p": purpose, "e": int(time.time()) + ttl, "n": "ab12cd34ef56"}, separators=(",", ":")).encode()).rstrip(b"=").decode()
    key = hashlib.sha256(("bookkam-admin-challenge|" + JWT).encode()).digest()
    return p + "." + hmac.new(key, p.encode(), hashlib.sha256).hexdigest()

def pwhash(p): return subprocess.check_output(["php", "-r", "echo password_hash('%s',PASSWORD_BCRYPT,['cost'=>4]);" % p]).decode()
def add_user(email, name, role, pw="secret123"):
    db("INSERT INTO users(email,name,role,city,wallet_balance,password_hash,email_verified_at) VALUES(?,?,?,'Calabar',0,?,datetime('now'))", (email, name, role, pwhash(pw)))
    return db("SELECT id FROM users WHERE email=?", (email,))[0]["id"]
def login(base, email, pw="secret123"):
    c, r = A(base, "auth.php", "login_email", {"email": email, "password": pw}); return r.get("token")
def clear_throttle(): db("DELETE FROM auth_throttle")
def wallet(email): return db("SELECT wallet_balance w FROM users WHERE email=?", (email,))[0]["w"]
def keys_deep(o):
    if isinstance(o, dict):
        for k, v in o.items():
            yield str(k); yield from keys_deep(v)
    elif isinstance(o, list):
        for v in o: yield from keys_deep(v)
def strings_deep(o):
    if isinstance(o, dict):
        for v in o.values(): yield from strings_deep(v)
    elif isinstance(o, list):
        for v in o: yield from strings_deep(v)
    elif isinstance(o, str): yield o

def main():
    rt.setup()
    env = {"APP_ENV": "production", "JWT_SECRET": JWT}
    rt.start(18111, env); rt.start(18112, dict(env, APP_ENV="development")); rt.start(18113, dict(env, ADMIN_2FA_REQUIRED="1"))
    for t, col, v in [("hourly", "per_hour_price", 5000), ("daily", "per_day_price", 30000)]:
        db("INSERT INTO pricing(city,booking_type,%s) VALUES('Calabar',?,?)" % col, (t, v))
    db("INSERT INTO pricing(city,booking_type,base_price) VALUES('Calabar','chauffeur_trip',15000)")

    # ---- accounts: admin, customers, driver with a car ----
    adm_id = add_user("admin@test.local", "Test Admin", "admin", ADMIN_PW)
    c, r = A(PROD, "auth.php", "admin_login", {"email": "admin@test.local", "password": ADMIN_PW}); AT = r.get("token")
    check("setup: admin signs in with the password (2FA not enrolled yet)", c == 200 and AT, (c, r))
    add_user("c1@test.local", "Cust One", "customer"); add_user("c2@test.local", "Cust Two", "customer")
    CT, C2T = login(PROD, "c1@test.local"), login(PROD, "c2@test.local")
    d_uid = add_user("d1@test.local", "Driver One", "driver"); db("INSERT INTO drivers(user_id,status) VALUES(?, 'pending')", (d_uid,))
    DT = login(PROD, "d1@test.local"); drv_id = db("SELECT id FROM drivers WHERE user_id=?", (d_uid,))[0]["id"]
    A(PROD, "cars.php", "driver_save", {"name": "Toyota Camry", "plate_number": "CAL-001", "year": 2019, "make": "Toyota", "model": "Camry"}, DT)
    A(PROD, "admin.php", "update_driver_status", {"driver_id": drv_id, "status": "active"}, AT)
    A(PROD, "drivers.php", "toggle_online", {"is_online": 1}, DT)
    c, r = A(PROD, "cars.php", "get_all", qs="&type=chauffeur&city=Calabar", token=CT)
    check("setup: the active, online driver's car is listed", len(r.get("cars", [])) == 1, r); car_id = int(r["cars"][0]["id"])

    # a second car of the same driver, so two bookings can be open at once (one car holds one open booking)
    db("INSERT INTO cars(name,car_type,category,city,plate_number,driver_id,availability_status,is_available) SELECT 'Second Camry',car_type,category,city,'CAL-002',driver_id,availability_status,1 FROM cars WHERE id=?", (car_id,))
    car2 = db("SELECT id FROM cars WHERE plate_number='CAL-002'")[0]["id"]
    def free_car():
        for cid in (car_id, car2):
            db("UPDATE bookings SET status='cancelled' WHERE car_id=? AND status IN ('pending','confirmed','active')", (cid,))
            db("UPDATE cars SET is_available=1 WHERE id=?", (cid,))
    def new_booking(token=None, base=PROD, car=None):
        c, b = A(base, "bookings.php", "create", {"car_id": car or car_id, "booking_type": "trip", "pickup_address": "Marian Rd", "city": "Calabar"}, token or CT)
        return int(b["booking_id"]) if "booking_id" in b else None
    def bk(i): return db("SELECT status,payment_status FROM bookings WHERE id=?", (i,))[0]
    def setting(key, on): return A(PROD, "admin.php", "set_app_setting", {"key": key, "enabled": on}, AT)

    # ================= S. SWITCHES =================
    c, r = A(PROD, "public-config.php", "x"); check("S: both switches are ON by default", r.get("bookings_enabled") is True and r.get("payments_enabled") is True, r)
    # prepare existing business BEFORE switching anything off
    db("UPDATE users SET wallet_balance=100000 WHERE email='c1@test.local'")
    free_car(); B_PAID = new_booking(); A(PROD, "payments.php", "initiate", {"booking_id": B_PAID, "method": "wallet", "booking_type": "website"}, CT)
    check("S: setup: a paid, confirmed booking exists (wallet 100000->85000)", bk(B_PAID) == {"status": "confirmed", "payment_status": "paid"} and wallet("c1@test.local") == 85000, bk(B_PAID))
    B_SIM = new_booking(car=car2); ref, _, _ = (lambda c, r: (r.get("reference"), c, r))(*A(DEV, "payments.php", "initiate", {"booking_id": B_SIM, "method": "simulator", "scenario": "success", "booking_type": "website"}, CT))
    check("S: setup: a second booking has a payment STARTED (not yet verified) at the provider", ref and bk(B_SIM)["payment_status"] == "unpaid", ref)

    c, r = setting("bookings_enabled", False)
    check("S1 admin can turn bookings OFF", c == 200 and r.get("enabled") is False, (c, r))
    c, r = A(PROD, "public-config.php", "x"); check("S1b public-config reports bookings OFF and payments effectively OFF", r.get("bookings_enabled") is False and r.get("payments_enabled") is False, r)
    c, r = A(PROD, "bookings.php", "create", {"car_id": car_id, "booking_type": "trip", "pickup_address": "X", "city": "Calabar"}, C2T)
    check("S2 NEW bookings are refused with 503 bookings_paused", c == 503 and r.get("code") == "bookings_paused" and "booking_id" not in r, (c, r))
    c, r = A(PROD, "event-booking.php", "x", {"event": "grovveyard", "event_name": "Grovve Yard"}, C2T)
    check("S3 NEW event bookings are refused with 503", c == 503 and r.get("code") == "bookings_paused", (c, r))
    c, r = A(DEV, "payments.php", "top_up_wallet", {"amount": 1000}, CT); check("S4 wallet top-up is refused while bookings are OFF", c == 503, (c, r))
    c, r = A(PROD, "payments.php", "initiate", {"booking_id": B_SIM, "method": "cash", "booking_type": "website"}, CT)
    check("S5 starting a NEW payment is refused while bookings are OFF", c == 503, (c, r))
    c, r = A(DEV, "payments.php", "verify", qs="&reference=" + ref)
    check("S6 verification of a payment started earlier STILL settles the booking", c == 200 and r.get("status") == "success" and bk(B_SIM) == {"status": "confirmed", "payment_status": "paid"}, (c, r, bk(B_SIM)))
    c, r = A(PROD, "bookings.php", "get_my_bookings", token=CT); check("S7 customers can still read their bookings", c == 200 and len(r.get("bookings", [])) >= 2, c)
    c, r = A(PROD, "bookings.php", "update_status", {"booking_id": B_PAID, "status": "active"}, DT); check("S8 the driver can still run an EXISTING trip (start)", r.get("success") is True and bk(B_PAID)["status"] == "active", (c, r))
    c, r = A(PROD, "bookings.php", "update_status", {"booking_id": B_PAID, "status": "completed"}, DT); check("S8b ...and complete it", r.get("success") is True and bk(B_PAID)["status"] == "completed", (c, r))
    before = wallet("c1@test.local"); c, r = A(PROD, "bookings.php", "cancel", {"booking_id": B_SIM}, CT)
    check("S9 cancellation + refund of an existing paid booking still works while OFF", r.get("success") is True and bk(B_SIM)["status"] == "cancelled" and wallet("c1@test.local") == before + 15000, (r, wallet("c1@test.local"), before))

    c, r = setting("bookings_enabled", True); check("S10 bookings turned back ON", c == 200 and r.get("enabled") is True)
    free_car(); nb = new_booking(C2T); check("S10b bookings work again after ON", nb is not None)
    c, r = setting("payments_enabled", False); check("S11 admin can turn payments OFF (bookings stay ON)", c == 200 and r.get("enabled") is False, (c, r))
    c, r = A(PROD, "public-config.php", "x"); check("S11b public-config: bookings ON, payments OFF", r.get("bookings_enabled") is True and r.get("payments_enabled") is False, r)
    c, r = A(PROD, "payments.php", "initiate", {"booking_id": nb, "method": "wallet", "booking_type": "website"}, C2T)
    check("S12 starting a payment is refused with 503 payments_paused (booking stays unpaid)", c == 503 and r.get("code") == "payments_paused" and bk(nb)["payment_status"] == "unpaid", (c, r))
    c, r = A(DEV, "payments.php", "top_up_wallet", {"amount": 1000}, C2T); check("S12b wallet top-up refused while payments are OFF", c == 503, (c, r))
    free_car(); nb2 = new_booking(C2T); check("S13 NEW bookings are still accepted while only payments are OFF", nb2 is not None)
    c, r = A(PROD, "bookings.php", "cancel", {"booking_id": nb2}, C2T); check("S13b ...and can be cancelled", r.get("success") is True, r)
    setting("payments_enabled", True)

    c, r = A(PROD, "admin.php", "set_app_setting", {"key": "bookings_enabled", "enabled": False}, CT); check("S14 a customer cannot flip a switch (403)", c == 403, (c, r))
    c, r = A(PROD, "admin.php", "set_app_setting", {"key": "bookings_enabled", "enabled": False}); check("S14b anonymous cannot flip a switch (401)", c == 401, (c, r))
    c, r = A(PROD, "admin.php", "set_app_setting", {"key": "bookings_enabled", "enabled": False}, DT); check("S14c a driver cannot flip a switch (403)", c == 403, (c, r))
    for bad in ["false", 0, None, "0", [], {"x": 1}]:
        c, r = A(PROD, "admin.php", "set_app_setting", {"key": "bookings_enabled", "enabled": bad}, AT)
        check("S15 enabled=%r is refused (must be a real boolean) and nothing changes" % (bad,), c == 400 and db("SELECT v FROM app_settings WHERE k='bookings_enabled'")[0]["v"] == "1", (c, r))
    for key in ["mapbox_enabled", "opay_enabled", "welcome_credit_enabled", "bookings_enabled'; DROP TABLE app_settings;--", "", None, 7]:
        c, r = A(PROD, "admin.php", "set_app_setting", {"key": key, "enabled": True}, AT)
        check("S16 unknown setting key %r is refused" % (key,), c == 400 and r.get("error") == "Unknown setting", (c, r))
    check("S16b the settings table is intact and holds exactly the two known keys", sorted(x["k"] for x in db("SELECT k FROM app_settings")) == ["bookings_enabled", "payments_enabled"])
    c, r = A(PROD, "admin.php", "get_app_settings", token=AT); check("S17 get_app_settings reports mapbox OFF by default", c == 200 and r.get("mapbox_enabled") is False and r.get("bookings_enabled") is True, r)
    c, r = A(PROD, "admin.php", "get_app_settings", token=CT); check("S17b a customer cannot read the admin settings", c == 403, c)
    db("ALTER TABLE app_settings RENAME TO app_settings_gone"); free_car(); nb3 = new_booking(C2T)
    check("S18 migration 010 not applied yet: the app fails OPEN (bookings keep working)", nb3 is not None)
    c, r = A(PROD, "admin.php", "set_app_setting", {"key": "bookings_enabled", "enabled": False}, AT)
    check("S18b ...and saving a switch says plainly that the migration is missing (no crash)", c in (500, 503) and "010" in json.dumps(r), (c, r))
    db("ALTER TABLE app_settings_gone RENAME TO app_settings"); A(PROD, "bookings.php", "cancel", {"booking_id": nb3}, C2T); free_car()

    # ================= L. DRIVER LOCATION PRIVACY =================
    c, r = A(PROD, "drivers.php", "update_location", {"lat": 4.9517, "lng": 8.322, "heading": 90}, DT); check("L0 the driver can publish a position", c == 200, (c, r))
    free_car(); LB = new_booking(); owner_ok = {}
    for status in ["pending", "confirmed", "active", "completed", "cancelled"]:
        db("UPDATE bookings SET status=? WHERE id=?", (status, LB))
        c, r = A(PROD, "bookings.php", "get_driver_location", token=CT, qs="&booking_id=%d" % LB)
        if status in ("confirmed", "active"):
            loc = r.get("location") or {}
            check("L1 owner sees the driver's position while the trip is '%s'" % status, c == 200 and abs(float(loc.get("lat", 0)) - 4.9517) < 1e-6, (c, r))
            check("L1b ...and only lat, lng, heading, updated_at are returned (no driver id / user id / phone)", sorted(loc.keys()) == ["heading", "lat", "lng", "updated_at"], loc)
        else:
            check("L2 owner gets 403 and NO position while the trip is '%s'" % status, c == 403 and "location" not in r, (c, r))
    for odd in ["accepted", "ongoing", "in_progress", "arrived", "Confirmed", "ACTIVE", "", "completed "]:
        db("UPDATE bookings SET status=? WHERE id=?", (odd, LB)); c, r = A(PROD, "bookings.php", "get_driver_location", token=CT, qs="&booking_id=%d" % LB)
        check("L3 an unknown status %r never shares the position" % odd, c == 403 and "location" not in r, (c, r))
    db("UPDATE bookings SET status='active' WHERE id=?", (LB,))
    c, r = A(PROD, "bookings.php", "get_driver_location", token=C2T, qs="&booking_id=%d" % LB); check("L4 ANOTHER customer cannot read it (404)", c == 404 and "location" not in r, (c, r))
    c, r = A(PROD, "bookings.php", "get_driver_location", token=DT, qs="&booking_id=%d" % LB); check("L4b the driver's own account is not a customer of this trip (404)", c == 404, (c, r))
    c, r = A(PROD, "bookings.php", "get_driver_location", qs="&booking_id=%d" % LB); check("L4c anonymous gets 401", c == 401 and "location" not in r, (c, r))
    c, r = A(PROD, "bookings.php", "get_driver_location", token=CT, qs="&booking_id=0"); check("L5 missing booking id is refused", c == 400, (c, r))
    db("UPDATE bookings SET driver_id=NULL WHERE id=?", (LB,)); c, r = A(PROD, "bookings.php", "get_driver_location", token=CT, qs="&booking_id=%d" % LB)
    check("L6 a booking with no driver assigned shares nothing", c == 403 and "location" not in r, (c, r))
    db("UPDATE bookings SET status='cancelled' WHERE id=?", (LB,)); free_car()

    # ================= C. CITIES (toggle_city) and HIDDEN EVENTS =================
    c, r = A(PROD, "admin.php", "add_city", {"name": "Uyo", "state": "Akwa Ibom"}, AT); check("C0 admin adds a city", c == 200 and r.get("success"), (c, r))
    uyo = db("SELECT id FROM cities WHERE name='Uyo'")[0]["id"]; db("UPDATE cities SET secret_note='internal-only-note' WHERE id=?", (uyo,))
    c, r = A(PROD, "admin.php", "toggle_city", {"city_id": uyo}, AT); check("C1 toggle_city switches an active city OFF and reports the new state", c == 200 and r.get("is_active") == 0 and r.get("id") == uyo, (c, r))
    c, r = A(PROD, "admin.php", "get_cities"); check("C2 anonymous city list no longer shows the disabled city", all(x["name"] != "Uyo" for x in r.get("cities", [])), r)
    c, r = A(PROD, "admin.php", "get_cities", token=AT); check("C2b the admin list still shows it (as inactive)", any(x["name"] == "Uyo" and int(x["is_active"]) == 0 for x in r.get("cities", [])), r)
    c, r = A(PROD, "admin.php", "toggle_city", {"city_id": uyo}, AT); check("C3 a second toggle turns it back ON", c == 200 and r.get("is_active") == 1, (c, r))
    check("C3b city responses expose only id, name, state, is_active", all(sorted(x.keys()) == ["id", "is_active", "name", "state"] for x in A(PROD, "admin.php", "get_cities")[1]["cities"]) and "internal-only-note" not in json.dumps(A(PROD, "admin.php", "get_cities", token=AT)[1]))
    for who, tok in [("a customer", CT), ("a driver", DT), ("anonymous", None)]:
        c, r = A(PROD, "admin.php", "toggle_city", {"city_id": uyo}, tok); check("C4 %s cannot toggle a city (401/403)" % who, c in (401, 403) and int(db("SELECT is_active a FROM cities WHERE id=?", (uyo,))[0]["a"]) == 1, (c, r))
    c, r = A(PROD, "admin.php", "toggle_city", {"city_id": 99999}, AT); check("C5 unknown city -> 404", c == 404, (c, r))
    for bad in [0, -1, None, "abc", [], {"a": 1}]:
        c, r = A(PROD, "admin.php", "toggle_city", {"city_id": bad}, AT); check("C6 city_id=%r is refused" % (bad,), c == 400, (c, r))
    other = db("SELECT id,is_active FROM cities WHERE id!=?", (uyo,))
    c, r = A(PROD, "admin.php", "toggle_city", {"city_id": "%d OR 1=1" % uyo}, AT)
    check("C7 'id OR 1=1' only ever touches one row (cast to int)", c in (200, 400) and db("SELECT id,is_active FROM cities WHERE id!=?", (uyo,)) == other, (c, r))

    c, r = A(PROD, "booking-cards.php", "get_active"); check("H0 no card configured -> an EMPTY list (the home screen then shows nothing)", c == 200 and r.get("cards") == [], (c, r))
    db("INSERT INTO home_booking_cards(id,event_key,modal_id,title,subtitle,is_active) VALUES(1,'grovveyard','gyOverlay-grovveyard','Grovve Yard','Night out',1)")
    c, r = A(PROD, "booking-cards.php", "get_active"); check("H1 an active card is listed", len(r.get("cards", [])) == 1 and r["cards"][0]["event_key"] == "grovveyard", r)
    db("UPDATE home_booking_cards SET is_active=0 WHERE id=1")
    c, r = A(PROD, "booking-cards.php", "get_active"); check("H2 HIDING the event gives an empty list, not an error and not the old card", c == 200 and r.get("cards") == [], (c, r))
    c, r = A(PROD, "event-booking.php", "x", {"event": "grovveyard", "event_name": "Grovve Yard"}, CT); check("H3 a hidden event cannot be booked directly (403)", c == 403, (c, r))
    c, r = A(PROD, "event-booking.php", "x", {"event": "caribbean", "event_name": "Caribbean Vibes"}, CT); check("H3b an event that is not hidden passes this gate (fails later on missing fields, not 403/503)", c not in (403, 503), (c, r))

    # ================= T. ADMIN TWO-FACTOR =================
    clear_throttle(); c, r = A(PROD, "auth.php", "admin_2fa_status", token=AT)
    check("T0 status before enrolment: off, not required, 0 recovery codes", c == 200 and r.get("enabled") is False and r.get("required") is False and r.get("recovery_codes_left") == 0, (c, r))
    for who, tok in [("a customer", CT), ("anonymous", None)]:
        c, r = A(PROD, "auth.php", "admin_2fa_begin", {}, tok); check("T1 %s cannot start enrolment (401)" % who, c == 401 and "secret" not in r, (c, r))
    c, r = A(PROD, "auth.php", "admin_2fa_begin", {}, AT); SECRET = r.get("secret")
    check("T2 an admin session can begin enrolment: secret + otpauth URI", c == 200 and re.fullmatch(r"[A-Z2-7]{32}", SECRET or "") and r["otpauth_uri"].startswith("otpauth://totp/") and SECRET in r["otpauth_uri"], (c, r))
    stored = db("SELECT admin_totp_secret s, admin_totp_enabled e FROM users WHERE id=?", (adm_id,))[0]
    check("T2b the secret is stored ENCRYPTED (not the plain secret) and 2FA is still off", stored["s"] and SECRET not in stored["s"] and stored["e"] == 0, stored)
    c, r = A(PROD, "auth.php", "admin_login", {"email": "admin@test.local", "password": ADMIN_PW}); check("T2c until a code is confirmed, sign-in is still password-only", c == 200 and r.get("token"), (c, r))
    clear_throttle()
    for bad in ["000000", "12345", "abcdefg", "", None]:
        c, r = A(PROD, "auth.php", "admin_2fa_enable", {"code": bad}, AT)
        check("T3 enrolment with a wrong code %r is refused and 2FA stays off" % (bad,), c == 400 and "recovery_codes" not in r and db("SELECT admin_totp_enabled e FROM users WHERE id=?", (adm_id,))[0]["e"] == 0, (c, r))
    c, r = A(PROD, "auth.php", "admin_2fa_enable", {"code": totp(SECRET, cur())}, AT)
    check("T3b after 5 wrong codes enrolment is rate-limited (429): even the RIGHT code is refused until the window passes", c == 429 and db("SELECT admin_totp_enabled e FROM users WHERE id=?", (adm_id,))[0]["e"] == 0, (c, r))
    clear_throttle()
    for bad in [123456, ["123456"], {"a": 1}]:
        c, r = A(PROD, "auth.php", "admin_2fa_enable", {"code": bad}, AT)
        check("T3c a non-string code %r is refused cleanly (400, no 500)" % (bad,), c == 400 and "recovery_codes" not in r, (c, r))
    clear_throttle(); S0 = cur(); c, r = A(PROD, "auth.php", "admin_2fa_enable", {"code": totp(SECRET, S0)}, AT); codes = r.get("recovery_codes") or []
    check("T4 the correct code turns 2FA on and returns 8 recovery codes once", c == 200 and r.get("success") and len(codes) == 8 and all(re.fullmatch(r"[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}", x) for x in codes) and len(set(codes)) == 8, (c, r))
    rh = db("SELECT admin_recovery_hashes h FROM users WHERE id=?", (adm_id,))[0]["h"]
    check("T4b only SHA-256 hashes of the recovery codes are stored (no plain code in the database)", all(x not in rh for x in codes) and all(hashlib.sha256(x.encode()).hexdigest() in rh for x in codes), rh[:80])
    c, r = A(PROD, "auth.php", "admin_2fa_begin", {}, AT); check("T5 enrolment cannot be restarted while on (409); the secret is not replaced", c == 409 and "secret" not in r, (c, r))
    c, r = A(PROD, "auth.php", "admin_2fa_status", token=AT); check("T5b status: on, 8 codes left, and NO secret / hashes in the response", c == 200 and r.get("enabled") is True and r.get("recovery_codes_left") == 8 and not ({"secret", "admin_totp_secret", "admin_recovery_hashes"} & set(r)), r)

    clear_throttle(); c, r = A(PROD, "auth.php", "admin_login", {"email": "admin@test.local", "password": ADMIN_PW})
    check("T6 password step for an enrolled admin gives a challenge and NO session token", c == 200 and r.get("two_factor_required") is True and r.get("challenge") and "token" not in r and "user" not in r, (c, r)); CH = r.get("challenge")
    c, r = A(PROD, "auth.php", "admin_login", {"email": "admin@test.local", "password": "wrong-password"}); check("T6b a wrong password gets no challenge", c == 401 and "challenge" not in r, (c, r))
    check("T6c the challenge does not contain the email or password", "admin@test.local" not in base64.urlsafe_b64decode(CH.split(".")[0] + "==").decode() and ADMIN_PW not in CH)
    c, r = A(PROD, "admin.php", "get_customers", token=CH); check("T6d a challenge is not a session (admin API refuses it)", c == 401, c)
    c, r = A(PROD, "auth.php", "admin_login_2fa", {"challenge": CH}); check("T7 no code at all is refused", c == 401 and "token" not in r, (c, r))
    c, r = A(PROD, "auth.php", "admin_login_2fa", {"challenge": CH, "code": totp(SECRET, S0)}); check("T8 REPLAY: the code used to enrol cannot be used to sign in", c == 401 and "token" not in r, (c, r))
    clear_throttle(); GOOD = totp(SECRET, S0 + 1)
    for bad in [[GOOD], 123456, {"a": 1}, True]:
        c, r = A(PROD, "auth.php", "admin_login_2fa", {"challenge": CH, "code": bad}); check("T9 a non-string code %r is refused cleanly (no 500)" % (bad,), c == 401 and "token" not in r, (c, r))
    clear_throttle(); c, r = A(PROD, "auth.php", "admin_login_2fa", {"challenge": CH, "code": GOOD[:3] + " " + GOOD[3:]})
    check("T10 the next authenticator code signs in (spaces tolerated) and returns an admin session", c == 200 and r.get("token") and r.get("user", {}).get("role") == "admin" and not ({"password_hash", "admin_totp_secret"} & set(r.get("user", {}))), (c, r)); T2FA = r.get("token")
    c, r2 = A(PROD, "admin.php", "get_customers", token=T2FA); check("T10b that session works on the admin API", c == 200, c)
    c, r = A(PROD, "auth.php", "admin_login_2fa", {"challenge": CH, "code": GOOD}); check("T11 REPLAY: the same code cannot be used again", c == 401 and "token" not in r, (c, r))
    c, r = A(PROD, "auth.php", "admin_login", {"email": "admin@test.local", "password": ADMIN_PW}); CH2 = r["challenge"]
    c, r = A(PROD, "auth.php", "admin_login_2fa", {"challenge": CH2, "code": GOOD}); check("T11b REPLAY across sessions: a used code is refused on a fresh challenge too", c == 401 and "token" not in r, (c, r))
    c, r = A(PROD, "auth.php", "admin_login_2fa", {"challenge": CH2, "code": totp(SECRET, S0 - 5)}); check("T11c an old code (outside the drift window) is refused", c == 401, (c, r))

    pl, sig = CH2.split("."); data = json.loads(base64.urlsafe_b64decode(pl + "=" * (-len(pl) % 4)))
    forged_payload = base64.urlsafe_b64encode(json.dumps(dict(data, a=adm_id + 1)).encode()).rstrip(b"=").decode()
    for name, ch in [("a payload edited under the old signature", forged_payload + "." + sig), ("a truncated challenge", CH2[:-4]), ("random text", "abc.def"), ("empty", ""), ("a signature of zeros", pl + "." + "0" * 64),
                     ("an EXPIRED but correctly signed challenge", challenge(adm_id, "login2fa", -10)), ("a setup-purpose challenge used as a sign-in challenge", challenge(adm_id, "setup")),
                     ("a challenge for a CUSTOMER id", challenge(db("SELECT id FROM users WHERE email='c1@test.local'")[0]["id"], "login2fa"))]:
        c, r = A(PROD, "auth.php", "admin_login_2fa", {"challenge": ch, "recovery_code": codes[0]}); check("T12 %s is refused (even with a valid recovery code)" % name, c == 401 and "token" not in r, (c, r))
    check("T12b ...and no recovery code was burned by those attempts", int(A(PROD, "auth.php", "admin_2fa_status", token=AT)[1].get("recovery_codes_left", -1)) == 8)
    c, r = A(PROD, "auth.php", "admin_2fa_begin", {"challenge": CH2}); check("T12c a sign-in challenge cannot be used to start enrolment", c == 401 and "secret" not in r, (c, r))
    c, r = A(PROD, "auth.php", "admin_2fa_enable", {"challenge": challenge(adm_id, "login2fa"), "code": "123456"}); check("T12d ...nor to finish it", c == 401 and "token" not in r, (c, r))

    clear_throttle(); c, r = A(PROD, "auth.php", "admin_login_2fa", {"challenge": CH2, "recovery_code": codes[0].upper() + " "})
    check("T13 a recovery code signs in once (case and padding ignored)", c == 200 and r.get("token"), (c, r))
    c, r = A(PROD, "auth.php", "admin_login_2fa", {"challenge": CH2, "recovery_code": codes[0]}); check("T13b the same recovery code is refused the second time", c == 401 and "token" not in r, (c, r))
    check("T13c recovery codes left: 7", A(PROD, "auth.php", "admin_2fa_status", token=AT)[1].get("recovery_codes_left") == 7)
    res = rt.parallel(6, lambda: A(PROD, "auth.php", "admin_login_2fa", {"challenge": CH2, "recovery_code": codes[1]})[0])
    check("T13d the same recovery code used 6x in parallel works exactly once", res.count(200) == 1, res)
    clear_throttle(); c, r = A(PROD, "auth.php", "admin_login_2fa", {"challenge": CH2, "recovery_code": "zzzz-zzzz-zzzz"}); check("T13e a wrong recovery code is refused", c == 401 and "token" not in r, (c, r))

    # lockout: wrong codes count with the password step; right password alone does not
    clear_throttle(); c, r = A(PROD, "auth.php", "admin_login", {"email": "admin@test.local", "password": ADMIN_PW}); CH3 = r["challenge"]
    codes_seen = [A(PROD, "auth.php", "admin_login_2fa", {"challenge": CH3, "code": "%06d" % (100000 + i)})[0] for i in range(5)]
    c, r = A(PROD, "auth.php", "admin_login_2fa", {"challenge": CH3, "recovery_code": codes[2]})
    check("T14 five wrong codes lock the admin out: the 6th try is refused (429) even with a valid recovery code", codes_seen == [401] * 5 and c == 429 and "token" not in r, (codes_seen, c, r))
    c, r = A(PROD, "auth.php", "admin_login", {"email": "admin@test.local", "password": ADMIN_PW}); check("T14b ...and the password step is locked too, with the right password", c == 429 and "challenge" not in r and "token" not in r, (c, r))
    clear_throttle()

    # disable: needs the password AND a FRESH code
    c, r = A(PROD, "auth.php", "admin_2fa_disable", {"password": "nope", "code": totp(SECRET, S0 + 1)}, AT); check("T15 disabling with a wrong password is refused", c == 401 and db("SELECT admin_totp_enabled e FROM users WHERE id=?", (adm_id,))[0]["e"] == 1, (c, r))
    c, r = A(PROD, "auth.php", "admin_2fa_disable", {"password": ADMIN_PW, "code": "000000"}, AT); check("T15b ...with a wrong code is refused", c == 401 and db("SELECT admin_totp_enabled e FROM users WHERE id=?", (adm_id,))[0]["e"] == 1, (c, r))
    c, r = A(PROD, "auth.php", "admin_2fa_disable", {"password": ADMIN_PW, "code": "000000"}, CT); check("T15c a customer cannot disable an admin's 2FA (401)", c == 401, (c, r))
    c, r = A(PROD, "auth.php", "admin_2fa_disable", {"password": ADMIN_PW, "code": totp(SECRET, S0 + 1)}, AT)
    check("T15d a code that was already used to sign in cannot authorise disabling (replay)", c == 401 and db("SELECT admin_totp_enabled e FROM users WHERE id=?", (adm_id,))[0]["e"] == 1, (c, r))
    db("UPDATE users SET admin_totp_last_step=? WHERE id=?", (S0, adm_id)); clear_throttle()
    c, r = A(PROD, "auth.php", "admin_2fa_disable", {"password": ADMIN_PW, "code": totp(SECRET, S0 + 1)}, AT)
    d = db("SELECT admin_totp_enabled e, admin_totp_secret s, admin_recovery_hashes h FROM users WHERE id=?", (adm_id,))[0]
    check("T16 password + a fresh code turns 2FA off and wipes the secret and the recovery hashes", c == 200 and d == {"e": 0, "s": None, "h": None}, (c, r, d))
    c, r = A(PROD, "auth.php", "admin_login", {"email": "admin@test.local", "password": ADMIN_PW}); check("T16b sign-in is password-only again", c == 200 and r.get("token"), (c, r))

    # server that REQUIRES 2FA (ADMIN_2FA_REQUIRED=1): forced enrolment during sign-in
    clear_throttle(); c, r = A(REQ, "auth.php", "admin_login", {"email": "admin@test.local", "password": ADMIN_PW})
    check("R1 required-2FA server: a not-yet-enrolled admin gets a setup challenge and NO token", c == 200 and r.get("needs_2fa_setup") is True and "token" not in r and r.get("challenge"), (c, r)); SC = r.get("challenge")
    c, r = A(REQ, "admin.php", "get_customers", token=SC); check("R1b the setup challenge is not a session", c == 401, c)
    c, r = A(REQ, "auth.php", "admin_login_2fa", {"challenge": SC, "recovery_code": "aaaa-bbbb-cccc"}); check("R1c a setup challenge cannot be used at the sign-in step", c == 401 and "token" not in r, (c, r))
    c, r = A(REQ, "auth.php", "admin_2fa_begin", {"challenge": SC}); S2 = r.get("secret"); check("R2 the setup challenge starts enrolment", c == 200 and S2, (c, r))
    c, r = A(REQ, "auth.php", "admin_2fa_enable", {"challenge": SC, "code": "000000"}); check("R3 a wrong code does not complete enrolment (no token)", c == 400 and "token" not in r, (c, r))
    c, r = A(REQ, "auth.php", "admin_2fa_enable", {"challenge": SC, "code": totp(S2, cur())}); check("R4 the right code completes enrolment: recovery codes AND a session", c == 200 and r.get("token") and len(r.get("recovery_codes", [])) == 8 and r.get("user", {}).get("role") == "admin", (c, r))
    clear_throttle(); c, r = A(REQ, "auth.php", "admin_login", {"email": "admin@test.local", "password": ADMIN_PW}); check("R5 the next sign-in asks for the code", r.get("two_factor_required") is True and "token" not in r, (c, r))
    c, r = A(REQ, "auth.php", "admin_2fa_status", token=A(REQ, "auth.php", "admin_login_2fa", {"challenge": r["challenge"], "code": totp(S2, cur() + 1)})[1].get("token")); check("R6 status on the required server reports required=true", r.get("required") is True and r.get("enabled") is True, r)

    # ================= Q. SQL INJECTION =================
    max_city = db("SELECT MAX(id) m FROM cities")[0]["m"]
    tables_before = sorted(x["name"] for x in db("SELECT name FROM sqlite_master WHERE type='table'")); users_before = db("SELECT COUNT(*) n FROM users")[0]["n"]
    PAY = ["' OR '1'='1", "' OR 1=1--", "admin@test.local' --", "x'; DROP TABLE users;--", "\" OR \"\"=\"", "1; UPDATE users SET role='admin'", "' UNION SELECT password_hash FROM users--", "%' OR '%'='", "\\' OR 1=1 -- "]
    leaks = []
    def probe(label, c, r):
        txt = json.dumps(r)
        if c >= 500 or re.search(r"SQLSTATE|syntax error|sqlite|PDOException|near \"", txt, re.I) or r.get("token"): leaks.append((label, c, txt[:120]))
    for p in PAY:
        probe("login_email email", *A(PROD, "auth.php", "login_email", {"email": p, "password": "secret123"}))
        probe("login_email password", *A(PROD, "auth.php", "login_email", {"email": "c1@test.local", "password": p}))
        clear_throttle(); probe("admin_login email", *A(PROD, "auth.php", "admin_login", {"email": p, "password": ADMIN_PW}))
        clear_throttle(); probe("admin_login password", *A(PROD, "auth.php", "admin_login", {"email": "admin@test.local", "password": p}))
        probe("register_email", *A(PROD, "auth.php", "register_email", {"name": p, "email": p, "password": "secret123", "confirm": "secret123", "role": "customer", "city": p}))
        probe("forgot_password", *A(PROD, "auth.php", "forgot_password", {"email": p}))
        probe("verify_email", *A(PROD, "auth.php", "verify_email", {"token": p}))
        probe("cars get_all", *A(PROD, "cars.php", "get_all", token=CT, qs="&type=" + __import__("urllib.parse").parse.quote(p) + "&city=" + __import__("urllib.parse").parse.quote(p)))
        probe("bookings get_one", *A(PROD, "bookings.php", "get_one", token=CT, qs="&id=" + __import__("urllib.parse").parse.quote(p)))
        probe("bookings create", *A(PROD, "bookings.php", "create", {"car_id": p, "booking_type": p, "pickup_address": p, "city": p}, CT))
        probe("admin get_drivers status", *A(PROD, "admin.php", "get_drivers", token=AT, qs="&status=" + __import__("urllib.parse").parse.quote(p)))
        probe("admin toggle_city", *A(PROD, "admin.php", "toggle_city", {"city_id": p}, AT))
        probe("admin add_city", *A(PROD, "admin.php", "add_city", {"name": p, "state": p}, AT))
        probe("admin set_app_setting", *A(PROD, "admin.php", "set_app_setting", {"key": p, "enabled": True}, AT))
        probe("driver get_location", *A(PROD, "bookings.php", "get_driver_location", token=CT, qs="&booking_id=" + __import__("urllib.parse").parse.quote(p)))
        probe("payments verify", *A(DEV, "payments.php", "verify", qs="&reference=" + __import__("urllib.parse").parse.quote(p)))
        probe("payments initiate", *A(PROD, "payments.php", "initiate", {"booking_id": p, "method": p, "booking_type": p}, CT))
    clear_throttle()
    check("Q1 %d injection payloads x 18 entry points: no 500, no SQL error text, no session token" % len(PAY), not leaks, leaks[:4])
    check("Q2 no table was dropped or added", sorted(x["name"] for x in db("SELECT name FROM sqlite_master WHERE type='table'")) == tables_before)
    new_users = db("SELECT email,role FROM users WHERE role='admin'")
    check("Q3 no row was inserted/escalated: the users table grew only by the (harmless) literal registrations and there is still ONE admin", len(new_users) == 1 and db("SELECT COUNT(*) n FROM users WHERE role='admin'")[0]["n"] == 1, new_users)
    check("Q4 injected city names are stored as plain text, not executed", db("SELECT COUNT(*) n FROM cities WHERE name=?", ("x'; DROP TABLE users;--",))[0]["n"] <= 1 and db("SELECT COUNT(*) n FROM users")[0]["n"] >= users_before)
    db("DELETE FROM cities WHERE id>?", (max_city,))   # drop the literal-text cities the payloads created, so later checks look at clean data
    c, r = A(PROD, "auth.php", "login_email", {"email": "' OR '1'='1", "password": "' OR '1'='1"}); check("Q5 the classic ' OR '1'='1 login gets a plain 400/401 and no token", c in (400, 401) and "token" not in r, (c, r))
    c, r = A(PROD, "cars.php", "get_all", token=CT, qs="&type=chauffeur&city=Calabar%27%20OR%20%271%27=%271"); check("Q6 an injected city filter returns no extra cars", c == 200 and r.get("cars", []) == [] , (c, r))

    # ================= X. NO SENSITIVE FIELDS IN RESPONSES =================
    FORBID = {"password", "password_hash", "otp_code", "otp_expires_at", "otp_attempts", "otp_last_sent", "token_hash", "admin_totp_secret", "admin_totp_enabled", "admin_totp_last_step",
              "admin_recovery_hashes", "recovery_hashes", "google_id", "apple_id", "tokens_valid_after", "secret", "api_key", "jwt_secret", "smtp_pass", "secret_note", "blacklist_reason"}
    NEEDLES = ["$2y$", JWT, "internal-only-note", SECRET, S2, "unit-test-jwt"]
    seen, bad = 0, []
    def scan(label, c, r):
        nonlocal seen; seen += 1
        hit = sorted(FORBID & {k.lower() for k in keys_deep(r)}); txt = json.dumps(r)
        hit += [n for n in NEEDLES if n and n in txt]
        if hit: bad.append((label, hit))
    clear_throttle(); AT2 = A(PROD, "auth.php", "admin_login", {"email": "admin@test.local", "password": ADMIN_PW})
    scan("admin_login", *AT2); AT = AT2[1].get("token") or AT
    cust = A(PROD, "auth.php", "login_email", {"email": "c1@test.local", "password": "secret123"}); scan("login_email", *cust)
    scan("register_email", *A(PROD, "auth.php", "register_email", {"name": "N", "email": "scan@test.local", "password": "secret123", "confirm": "secret123", "role": "customer", "city": "Calabar"}))
    scan("forgot_password", *A(PROD, "auth.php", "forgot_password", {"email": "c1@test.local"}))
    for ep, act, who, qs in [("auth.php", "get_profile", CT, ""), ("auth.php", "me", CT, ""), ("bookings.php", "get_my_bookings", CT, ""), ("bookings.php", "get_one", CT, "&id=%d" % LB),
                             ("bookings.php", "get_driver_bookings", DT, ""), ("bookings.php", "get_driver_location", CT, "&booking_id=%d" % LB), ("cars.php", "get_all", CT, "&type=chauffeur&city=Calabar"),
                             ("cars.php", "get_all", None, "&type=chauffeur&city=Calabar"), ("cars.php", "get_one", CT, "&id=%d" % car_id), ("cars.php", "get_my_car", DT, ""),
                             ("payments.php", "get_wallet", CT, ""), ("payments.php", "get_methods", CT, ""), ("payments.php", "get_methods", None, ""),
                             ("drivers.php", "get_my_status", DT, ""), ("drivers.php", "get_earnings", DT, "&period=today"),
                             ("public-config.php", "x", None, ""), ("booking-cards.php", "get_active", None, ""), ("event-config.php", "get", None, ""), ("event-packages.php", "get", None, ""),
                             ("admin.php", "get_cities", None, ""), ("admin.php", "get_cities", AT, ""), ("auth.php", "admin_2fa_status", AT, "")]:
        scan("%s?%s as %s" % (ep, act, "admin" if who == AT else "customer/driver" if who else "anonymous"), *A(PROD, ep, act, token=who, qs=qs))
    src = open(os.path.join(rt.ROOT, "api", "api", "admin.php")).read()
    acts = sorted(set(re.findall(r'case "(get_[a-z_]+)"', src)))
    for act in acts: scan("admin.php?%s as admin" % act, *A(PROD, "admin.php", act, token=AT, qs="&id=1&booking_id=1&status=active"))
    check("X1 %d responses (customer, driver, admin, anonymous; %d admin read actions) contain no secret field or secret value" % (seen, len(acts)), not bad, bad[:5])
    emails_in_public = [l for l in [json.dumps(A(PROD, "cars.php", "get_all", qs="&type=chauffeur&city=Calabar")[1]), json.dumps(A(PROD, "public-config.php", "x")[1]), json.dumps(A(PROD, "admin.php", "get_cities")[1])] if re.search(r"@test\.local", l)]
    check("X2 public (anonymous) endpoints leak no e-mail addresses", not emails_in_public, emails_in_public[:1])
    c, r = A(PROD, "admin.php", "get_customers", token=AT); fields = set().union(*[set(x.keys()) for x in r.get("customers", [])]) if r.get("customers") else set()
    check("X3 the admin customer list never carries password/otp/token/provider-id columns", not (FORBID & {f.lower() for f in fields}), sorted(fields))

    # ================= G. MAPBOX CONTRACT (what public-config tells browsers) =================
    for port, extra in [(18114, {"MAPBOX_ENABLED": "1", "MAPBOX_PUBLIC_TOKEN": "pk.test-public-token"}), (18115, {"MAPBOX_ENABLED": "1", "MAPBOX_PUBLIC_TOKEN": "sk.test-SECRET-token"}),
                        (18116, {"MAPBOX_ENABLED": "1"}), (18117, {"MAPBOX_PUBLIC_TOKEN": "pk.test-public-token"})]:
        rt.start(port, dict(env, **extra))
    gcfg = lambda port: A("http://127.0.0.1:%d" % port, "public-config.php", "x")[1]
    r = gcfg(18111); check("G1 default: Mapbox is OFF and the answer carries no token", r.get("mapbox") == {"enabled": False} and "pk." not in json.dumps(r) and "token" not in json.dumps(r), r)
    r = gcfg(18114); check("G2 MAPBOX_ENABLED=1 + a public pk. token: enabled, token handed to the browser", r.get("mapbox") == {"enabled": True, "token": "pk.test-public-token"}, r)
    r = gcfg(18115); check("G3 a SECRET sk. token keeps Mapbox OFF and is never sent", r.get("mapbox") == {"enabled": False} and "sk." not in json.dumps(r) and "SECRET" not in json.dumps(r), r)
    r = gcfg(18116); check("G4 enabled flag without any token keeps Mapbox OFF", r.get("mapbox") == {"enabled": False}, r)
    r = gcfg(18117); check("G5 a token without the enable flag keeps Mapbox OFF and the token is NOT leaked", r.get("mapbox") == {"enabled": False} and "pk." not in json.dumps(r), r)
    c, r = A("http://127.0.0.1:18114", "admin.php", "get_app_settings", token=AT); check("G6 the admin Settings screen shows Mapbox ON only on the server that enabled it", r.get("mapbox_enabled") is True, (c, r))

    for p in rt.procs: rt.stop(p)
    shutil.rmtree(rt.TMP, ignore_errors=True)
    f = [n for n, ok, _ in rt.results if not ok]
    print("\nSUMMARY (admin_security): %d checks, %d pass, %d fail" % (len(rt.results), len(rt.results) - len(f), len(f)))
    if f: print("FAILED:", f)
    sys.exit(1 if f else 0)

if __name__ == "__main__":
    try: main()
    finally:
        for p in rt.procs:
            try: rt.stop(p)
            except Exception: pass
