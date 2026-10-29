#!/usr/bin/env python3
"""BOOKKAM tests: OTP brute-force limits and the one-time welcome credit.

Local only: 127.0.0.1 servers, FAKE data, a THROWAWAY database, no real SMS or payment provider.
Each test asserts the SECURE behaviour, so a FAIL against the old code means the weakness is present.

  python3 tests/functional/otp_welcome_tests.py                       # SQLite stand-in (what CI runs)
  BK_BACKEND=mysql python3 tests/functional/otp_welcome_tests.py      # real MySQL/MariaDB: true row locking,
                                                                      # migration 008 and its legacy backfill
MySQL env: BK_MYSQL_DB (default bookkam_otp_test), BK_MYSQL_SOCKET *or* BK_MYSQL_HOST/BK_MYSQL_PORT,
           BK_MYSQL_ADMIN_USER/BK_MYSQL_ADMIN_PASS (DDL user for the mysql CLI), BK_MYSQL_APP_USER/BK_MYSQL_APP_PASS
           (user the PHP code connects as). NEVER point this at a real database: it DROPs and recreates tables.
"""
import base64, concurrent.futures as cf, hashlib, hmac, json, os, shutil, sqlite3, subprocess, sys, tempfile, time
import urllib.error, urllib.request

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
HERE = os.path.dirname(os.path.abspath(__file__))
BACKEND = os.environ.get("BK_BACKEND", "sqlite")
API_SRC = os.environ.get("BK_API_DIR", os.path.join(ROOT, "api"))   # point at another checkout's api/ to test old code
TMP = tempfile.mkdtemp(prefix="bookkam-otp-test-")
TESTBED = os.path.join(TMP, "testbed")
SQLITE_DB = os.path.join(TMP, "t.db")
JWT = "unit-test-jwt-secret-0123456789abcdef0123456789abcdef"
MY_DB = os.environ.get("BK_MYSQL_DB", "bookkam_otp_test")
procs, results = [], []
PORTS = dict(main=18201, ipcap=18202, off=18203, dev=18204)
DEAD = "http://127.0.0.1:9"      # any outbound HTTP (SMS provider) goes nowhere

# ---------------------------------------------------------------------------- database access
def _mysql_args(user_env="ADMIN"):
    a = ["mysql"]
    if os.environ.get("BK_MYSQL_SOCKET"): a.append("--socket=" + os.environ["BK_MYSQL_SOCKET"])
    else: a += ["--protocol=TCP", "-h", os.environ.get("BK_MYSQL_HOST", "127.0.0.1"), "-P", os.environ.get("BK_MYSQL_PORT", "3306")]
    a += ["-u", os.environ.get("BK_MYSQL_ADMIN_USER", "root")]
    if os.environ.get("BK_MYSQL_ADMIN_PASS"): a.append("-p" + os.environ["BK_MYSQL_ADMIN_PASS"])
    return a

def _lit(v):
    if v is None: return "NULL"
    if isinstance(v, (int, float)): return str(v)
    return "'" + str(v).replace("\\", "\\\\").replace("'", "''") + "'"

def db(sql, args=()):
    """Run a statement; return rows as list of dicts (SELECT) or []."""
    if BACKEND == "sqlite":
        c = sqlite3.connect(SQLITE_DB, timeout=30); c.row_factory = sqlite3.Row
        try:
            rows = c.execute(sql, args).fetchall(); c.commit(); return [dict(r) for r in rows]
        finally: c.close()
    parts = sql.split("?"); assert len(parts) == len(args) + 1, sql
    q = parts[0] + "".join(_lit(a) + p for a, p in zip(args, parts[1:]))
    r = subprocess.run(_mysql_args() + ["-D", MY_DB, "-B", "-e", q], capture_output=True, text=True)
    if r.returncode: raise RuntimeError(r.stderr.strip() + " :: " + q[:120])
    lines = [l for l in r.stdout.split("\n") if l != ""]
    if not lines: return []
    head = lines[0].split("\t")
    return [dict(zip(head, [None if x == "NULL" else x for x in l.split("\t")])) for l in lines[1:]]

def one(sql, args=()):
    r = db(sql, args); return list(r[0].values())[0] if r else None

def php_hash(pw): return subprocess.check_output(["php", "-r", "echo password_hash('%s',PASSWORD_BCRYPT,['cost'=>4]);" % pw]).decode()

def setup_db():
    if BACKEND == "sqlite":
        c = sqlite3.connect(SQLITE_DB); c.executescript(open(os.path.join(HERE, "schema.sql")).read()); c.commit(); c.close()
        return
    adm = _mysql_args()
    subprocess.run(adm + ["-e", "DROP DATABASE IF EXISTS %s; CREATE DATABASE %s CHARACTER SET utf8mb4;" % (MY_DB, MY_DB)], check=True)
    subprocess.run(adm + ["-D", MY_DB], input=open(os.path.join(HERE, "mysql_schema.sql")).read(), text=True, check=True)
    app_user = os.environ.get("BK_MYSQL_APP_USER", "root"); app_pass = os.environ.get("BK_MYSQL_APP_PASS", "")
    if app_user != "root":
        subprocess.run(adm + ["-e", "CREATE USER IF NOT EXISTS '%s'@'%%' IDENTIFIED BY '%s'; GRANT ALL ON %s.* TO '%s'@'%%'; FLUSH PRIVILEGES;"
                              % (app_user, app_pass, MY_DB, app_user)], check=True)

def apply_migration_008():
    subprocess.run(_mysql_args() + ["-D", MY_DB], input=open(os.path.join(ROOT, "migrations", "008_otp_throttle_and_welcome_claims.sql")).read(),
                   text=True, check=True)

# ---------------------------------------------------------------------------- servers
STUB_DB = '''<?php
$pdo = new PDO("sqlite:" . getenv("TESTBED_DB"), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction("NOW", fn() => date("Y-m-d H:i:s"), 0);
$pdo->sqliteCreateFunction("CURDATE", fn() => date("Y-m-d"), 0);
'''
def setup_testbed():
    shutil.copytree(API_SRC, TESTBED, ignore=shutil.ignore_patterns(".env", ".git"))
    if BACKEND == "sqlite":
        open(os.path.join(TESTBED, "config", "db.php"), "w").write(STUB_DB)
    else:   # the REAL config/db.php, pointed at the throwaway database through .env
        open(os.path.join(TESTBED, ".env"), "w").write("DB_HOST=%s\nDB_PORT=%s\nDB_NAME=%s\nDB_USER=%s\nDB_PASS=%s\n" % (
            os.environ.get("BK_MYSQL_HOST", "127.0.0.1"), os.environ.get("BK_MYSQL_PORT", "3306"), MY_DB,
            os.environ.get("BK_MYSQL_APP_USER", "root"), os.environ.get("BK_MYSQL_APP_PASS", "")))

def start(name, env_extra):
    port = PORTS[name]
    env = {k: v for k, v in os.environ.items() if not k.startswith(("APP_ENV", "JWT_", "OTP_", "WELCOME_"))}
    env.update({"TESTBED_DB": SQLITE_DB, "PHP_CLI_SERVER_WORKERS": "8", "https_proxy": DEAD, "http_proxy": DEAD, "all_proxy": DEAD})
    env.update(env_extra)
    p = subprocess.Popen(["php", "-d", "display_errors=0", "-S", "127.0.0.1:%d" % port, "-t", TESTBED], env=env,
                         stdout=subprocess.DEVNULL, stderr=open(os.path.join(TMP, "server-%d.log" % port), "a"))
    procs.append(p)
    for _ in range(60):
        try: urllib.request.urlopen("http://127.0.0.1:%d/" % port, timeout=1); break
        except urllib.error.HTTPError: break
        except Exception: time.sleep(0.1)

def call(name, ep, action, body=None, token=None):
    req = urllib.request.Request("http://127.0.0.1:%d/api/%s?action=%s" % (PORTS[name], ep, action),
                                 data=json.dumps(body).encode() if body is not None else None, method="POST" if body is not None else "GET")
    req.add_header("Content-Type", "application/json")
    if token: req.add_header("Authorization", "Bearer " + token)
    try:
        with urllib.request.urlopen(req, timeout=30) as r: code, txt = r.status, r.read().decode()
    except urllib.error.HTTPError as e: code, txt = e.code, e.read().decode()
    try: js = json.loads(txt)
    except Exception: js = {"_raw": txt[:200]}
    return code, js

def parallel(n, fn):
    with cf.ThreadPoolExecutor(n) as ex: return list(ex.map(lambda i: fn(i), range(n)))

def check(name, ok, detail=""):
    results.append((name, bool(ok)))
    print(("PASS " if ok else "FAIL ") + name + ((" :: " + str(detail)[:240]) if (detail != "" and not ok) else ""))

def make_pending(provider, sub, role="customer", email=None):
    b = lambda d: base64.b64encode(json.dumps(d, separators=(",", ":")).encode()).decode()
    h = b({"alg": "HS256", "typ": "JWT"})
    pl = b({"typ": "social_pending", "provider": provider, "sub": sub, "email": email, "name": "Social User", "photo": None,
            "role": role, "exp": int(time.time()) + 900, "iat": int(time.time())})
    sig = base64.urlsafe_b64encode(hmac.new(JWT.encode(), (h + "." + pl).encode(), hashlib.sha256).digest()).decode().rstrip("=")
    return h + "." + pl + "." + sig

# ---------------------------------------------------------------------------- helpers
_ph = [0]
def fresh_phone(): _ph[0] += 1; return "23481%08d" % (7000000 + _ph[0])
def clear_throttle(): db("DELETE FROM auth_throttle")
def uid_of(phone): return int(one("SELECT id FROM users WHERE phone=?", (phone,)))
def otp_of(uid): return one("SELECT otp_code FROM users WHERE id=?", (uid,))
def wallet(uid): return float(one("SELECT wallet_balance FROM users WHERE id=?", (uid,)))
def claims(uid): return int(one("SELECT COUNT(*) FROM welcome_credit_claims WHERE user_id=?", (uid,)))
def signup(server="main", phone=None, role="customer"):
    """send_otp (creates the account when new) -> (user_id, phone)"""
    phone = phone or fresh_phone()
    c, j = call(server, "auth.php", "send_otp", {"phone": phone, "role": role})
    assert c == 200, (c, j)
    return int(j["user_id"]), phone
def verify(uid, otp, server="main"): return call(server, "auth.php", "verify_otp", {"user_id": uid, "otp": otp})
def tok(j): return bool(isinstance(j, dict) and j.get("token"))
def wrong(otp): return "%06d" % ((int(otp) + 1) % 1000000)
def guesses(otp, n): return ["%06d" % ((int(otp) + 1 + i) % 1000000) for i in range(n)]

# ---------------------------------------------------------------------------- tests
def main():
    print("backend:", BACKEND)
    setup_db(); setup_testbed()
    legacy = {}
    if BACKEND == "mysql":
        # accounts that exist BEFORE the migration (they already received the old unconditional credit)
        db("INSERT INTO users(phone,name,role,city,wallet_balance) VALUES ('2348100000900','Legacy One','customer','Calabar',1000)")
        db("INSERT INTO users(phone,name,role,city,wallet_balance) VALUES ('08100000901','Legacy Dup A','customer','Calabar',1000)")
        db("INSERT INTO users(phone,name,role,city,wallet_balance) VALUES ('+2348100000901','Legacy Dup B','customer','Calabar',1000)")
        db("INSERT INTO users(phone,name,role,city,wallet_balance) VALUES (NULL,'Legacy Email','customer','Calabar',1000)")
        apply_migration_008()
        legacy = {r["name"]: int(r["id"]) for r in db("SELECT id,name FROM users")}
    prod = {"APP_ENV": "production", "JWT_SECRET": JWT, "OTP_VERIFY_IP_MAX_FAILS": "100000"}
    start("main", prod)
    start("ipcap", dict(prod, OTP_VERIFY_IP_MAX_FAILS="8"))
    start("off", dict(prod, WELCOME_CREDIT_ENABLED="0"))
    start("dev", dict(prod, APP_ENV="development"))

    # ======================= 1. OTP brute-force protection =======================
    clear_throttle()
    uid, ph = signup(); code, j = verify(uid, otp_of(uid))
    check("O1 correct OTP logs in and returns a token", code == 200 and tok(j), (code, j))

    clear_throttle(); uid, ph = signup(); good = otp_of(uid)
    seq = [verify(uid, g)[0] for g in guesses(good, 5)]
    left = otp_of(uid)
    check("O2a five wrong guesses are each answered 401", seq == [401] * 5, seq)
    check("O2b reaching the limit destroys the live OTP", left in (None, ""), left)
    uid2, _ = signup(phone=ph); new_good = otp_of(uid)          # resend works (resend protection untouched) ...
    code, j = verify(uid, new_good)
    check("O2c ... but the correct NEW OTP is refused during the lockout (429, no token)", code == 429 and not tok(j), (code, j))

    clear_throttle(); uid, ph = signup(); good = otp_of(uid)
    for g in guesses(good, 5): verify(uid, g)
    signup(phone=ph); good2 = otp_of(uid)
    db("UPDATE auth_throttle SET window_start='2000-01-01 00:00:00'")      # the window has elapsed
    code, j = verify(uid, good2)
    check("O3 after the lockout window elapses a fresh OTP works again", code == 200 and tok(j), (code, j))

    clear_throttle(); uid, ph = signup(); good = otp_of(uid)
    db("UPDATE users SET otp_expires_at=? WHERE id=?", ("2000-01-01 00:00:00", uid))
    code, j = verify(uid, good)
    check("O4 an expired OTP is rejected (401)", code == 401 and not tok(j), (code, j))

    clear_throttle(); uid, ph = signup(); good = otp_of(uid)
    code, j = verify(uid, good); code2, j2 = verify(uid, good)
    check("O5a a used OTP cannot be reused", code == 200 and code2 == 401 and not tok(j2), (code, code2))
    clear_throttle(); uid, ph = signup(); good = otp_of(uid)
    res = parallel(10, lambda i: verify(uid, good)[0])
    check("O5b 10 concurrent requests with the correct OTP yield exactly one session", res.count(200) == 1, sorted(res))

    clear_throttle(); uid, ph = signup(); good = otp_of(uid)
    gs = guesses(good, 40); res = parallel(40, lambda i: verify(uid, gs[i])[0])
    check("O6 40 concurrent wrong guesses: exactly 5 are evaluated (401), the other 35 are locked out (429)",
          res.count(401) == 5 and res.count(429) == 35, {k: res.count(k) for k in set(res)})

    clear_throttle(); uid, ph = signup(); good = otp_of(uid)
    for g in guesses(good, 5): verify(uid, g)
    signup(phone=ph); good2 = otp_of(uid)
    res = parallel(20, lambda i: verify(uid, good2)[0])
    check("O7 once locked, 20 concurrent requests carrying the CORRECT OTP all fail (no race around the lock)", res.count(200) == 0 and set(res) == {429}, sorted(set(res)))

    clear_throttle(); ids = [signup()[0] for _ in range(12)]
    res = [call("ipcap", "auth.php", "verify_otp", {"user_id": u, "otp": "000000"})[0] for u in ids]
    check("O8 per-IP limit: guesses spread over 12 different accounts hit 429 after 8", res[:8] == [401] * 8 and set(res[8:]) == {429}, res)

    clear_throttle(); uid, ph = signup(); good = otp_of(uid); w = wrong(good)
    a = verify(uid, w); b = verify(999999, w)
    db("UPDATE users SET otp_expires_at=? WHERE id=?", ("2000-01-01 00:00:00", uid)); clear_throttle()
    c = verify(uid, good)
    uidb, _ = signup(); goodb = otp_of(uidb); db("UPDATE users SET is_blacklisted=1 WHERE id=?", (uidb,)); d = verify(uidb, wrong(goodb))
    check("O9a wrong OTP, unknown user, expired OTP and blacklisted+wrong OTP are indistinguishable (same status and body)",
          a[0] == b[0] == c[0] == d[0] == 401 and a[1] == b[1] == c[1] == d[1], [a, b, c, d])
    clear_throttle(); e = verify(uidb, goodb)
    check("O9b only someone who proves OTP possession learns the account is suspended (no token)", e[0] == 400 and not tok(e[1]) and "suspended" in str(e[1]), e)

    clear_throttle(); uid, ph = signup(); good = otp_of(uid); codes = []
    for step in ("w", "w", "s", "w", "s", "w", "w"):
        if step == "s": signup(phone=ph); good = otp_of(uid)
        else: codes.append(verify(uid, wrong(good))[0])
    signup(phone=ph); good = otp_of(uid); code, j = verify(uid, good)
    check("O10 resends and failures cannot reset the counter: 5 wrongs interleaved with resends still lock the account",
          codes == [401] * 5 and code == 429 and not tok(j), (codes, code))

    clear_throttle(); uid, ph = signup(); good = otp_of(uid)
    sends = [call("main", "auth.php", "send_otp", {"phone": ph})[0] for _ in range(6)]
    check("O11 existing resend protection untouched: 6th send_otp inside the window is 429", sends[-1] == 429, sends)

    clear_throttle(); uid, ph = signup(); good = otp_of(uid)
    for i in range(3): verify(uid, wrong(good))
    code, j = verify(uid, good)
    signup(phone=ph); good = otp_of(uid)
    more = [verify(uid, wrong(good))[0] for _ in range(4)]
    check("O12 a correct login forgives earlier failures (3 wrong, then correct, then 4 more wrong are not locked)", code == 200 and more == [401] * 4, (code, more))

    clear_throttle(); uid, ph = signup("dev"); seq = [verify(uid, "000001", "dev")[0] for _ in range(5)]
    code, j = verify(uid, "123456", "dev")
    check("O13 the limit also applies in development/test mode (fixed test OTP refused after 5 wrong guesses)", seq == [401] * 5 and code == 429 and not tok(j), (seq, code))

    # ======================= 2. Welcome credit =======================
    clear_throttle(); uid, ph = signup()
    check("W1 creating the account (send_otp) grants nothing before the phone is verified", wallet(uid) == 0 and claims(uid) == 0, wallet(uid))
    code, j = verify(uid, otp_of(uid))
    check("W2 first verified phone login credits exactly 1000 and records one claim", code == 200 and wallet(uid) == 1000 and claims(uid) == 1 and j["user"]["wallet_balance"] in (1000, 1000.0, "1000", "1000.00"), (wallet(uid), claims(uid), j.get("user")))
    for _ in range(3):
        clear_throttle(); signup(phone=ph); verify(uid, otp_of(uid))
    check("W3 logging in again (3 more times) never credits again", wallet(uid) == 1000 and claims(uid) == 1, wallet(uid))

    clear_throttle(); db("DELETE FROM users WHERE id=?", (uid,))              # the same person comes back with a new account row
    uid_b, _ = signup(phone=ph); verify(uid_b, otp_of(uid_b))
    check("W4a re-signup with the same phone after the account row is gone gets no second credit", wallet(uid_b) == 0 and claims(uid_b) == 0, wallet(uid_b))
    variant = "0" + ph[-10:]
    clear_throttle(); uid_c, _ = signup(phone=variant); verify(uid_c, otp_of(uid_c))
    check("W4b the same number written as 0xxxxxxxxxx is recognised as the same identity", wallet(uid_c) == 0, wallet(uid_c))
    clear_throttle(); uid_d, _ = signup(phone="+" + ph); verify(uid_d, otp_of(uid_d))
    check("W4c the same number written as +234... is recognised as the same identity", wallet(uid_d) == 0, wallet(uid_d))

    clear_throttle(); p5 = fresh_phone()
    ids = []
    for fmt in (p5, "0" + p5[-10:]):
        db("INSERT INTO users(phone,role,name,city,wallet_balance,otp_code,otp_expires_at) VALUES (?,?,?,?,0,?,?)",
           (fmt, "customer", "Dup", "Calabar", "424242", "2999-01-01 00:00:00"))
        ids.append(uid_of(fmt))
    res = parallel(2, lambda i: verify(ids[i], "424242")[0])
    total = sum(wallet(i) for i in ids); cl = sum(claims(i) for i in ids)
    check("W5 two duplicate account rows for one phone verified concurrently receive ONE credit in total", res == [200, 200] and total == 1000 and cl == 1, (res, total, cl))

    clear_throttle(); uid, ph = signup(); good = otp_of(uid)
    res = parallel(10, lambda i: verify(uid, good)[0])
    check("W6 10 concurrent verifications of one account credit exactly once (1000)", wallet(uid) == 1000 and claims(uid) == 1 and res.count(200) == 1, (wallet(uid), sorted(res)))

    sub = "g-sub-%d" % int(time.time())
    c, j = call("main", "auth.php", "set_city", {"city": "Calabar", "pending_token": make_pending("google", sub, email="g1@test.local")})
    gu = int(one("SELECT id FROM users WHERE google_id=?", (sub,)))
    check("W7a a Google-verified new customer receives the credit once", c == 200 and wallet(gu) == 1000 and claims(gu) == 1, (c, wallet(gu)))
    call("main", "auth.php", "set_city", {"city": "Calabar", "pending_token": make_pending("google", sub, email="g1@test.local")})
    check("W7b replaying the same Google identity logs into the same account without extra credit", wallet(gu) == 1000 and int(one("SELECT COUNT(*) FROM users WHERE google_id=?", (sub,))) == 1, wallet(gu))
    db("DELETE FROM users WHERE id=?", (gu,))
    c, j = call("main", "auth.php", "set_city", {"city": "Calabar", "pending_token": make_pending("google", sub, email="g1@test.local")})
    gu2 = int(one("SELECT id FROM users WHERE google_id=?", (sub,)))
    check("W7c the same Google identity cannot claim again after account recreation", wallet(gu2) == 0, wallet(gu2))
    c, j = call("main", "auth.php", "set_city", {"city": "Calabar", "pending_token": make_pending("apple", "a-sub-1", email="a1@test.local")})
    au = int(one("SELECT id FROM users WHERE apple_id=?", ("a-sub-1",)))
    check("W7d an Apple identity (token not verified by this server) is not eligible", c == 200 and wallet(au) == 0 and claims(au) == 0, (c, wallet(au)))

    c, j = call("main", "auth.php", "register_email", {"name": "Mail User", "email": "mail1@test.local", "password": "secret123", "confirm": "secret123", "role": "customer", "city": "Calabar"})
    mu = int(one("SELECT id FROM users WHERE email=?", ("mail1@test.local",)))
    check("W8 e-mail/password signup (e-mail ownership is not verified) gets no welcome credit", c == 200 and wallet(mu) == 0 and claims(mu) == 0, (c, wallet(mu)))

    clear_throttle(); uid, ph = signup("off"); code, j = verify(uid, otp_of(uid), "off")
    check("W9 WELCOME_CREDIT_ENABLED=0 switches the credit off (no credit, no claim, login still works)", code == 200 and tok(j) and wallet(uid) == 0 and claims(uid) == 0, (code, wallet(uid)))

    clear_throttle(); uid, ph = signup(role="driver"); code, j = verify(uid, otp_of(uid))
    check("W10 driver accounts never receive the credit", code == 200 and wallet(uid) == 0 and claims(uid) == 0, wallet(uid))

    if BACKEND == "mysql":
        n_open = one("SELECT COUNT(*) FROM users u LEFT JOIN welcome_credit_claims c ON c.user_id=u.id WHERE u.role='customer' AND u.name LIKE 'Legacy%' AND c.id IS NULL")
        check("M1 migration 008 backfill closes every pre-existing customer (none left without a claim)", int(n_open) == 0, n_open)
        clear_throttle(); db("UPDATE users SET otp_code='515151', otp_expires_at='2999-01-01 00:00:00' WHERE id=?", (legacy["Legacy One"],))
        code, j = verify(legacy["Legacy One"], "515151")
        check("M2 an existing customer logging in after the migration is NOT credited a second time", code == 200 and wallet(legacy["Legacy One"]) == 1000, wallet(legacy["Legacy One"]))
        for nm in ("Legacy Dup A", "Legacy Dup B"):
            clear_throttle(); db("UPDATE users SET otp_code='515151', otp_expires_at='2999-01-01 00:00:00' WHERE id=?", (legacy[nm],))
            verify(legacy[nm], "515151")
        check("M3 two old accounts sharing one phone are both closed (no credit for either)", wallet(legacy["Legacy Dup A"]) == 1000 and wallet(legacy["Legacy Dup B"]) == 1000, (wallet(legacy["Legacy Dup A"]), wallet(legacy["Legacy Dup B"])))
        clear_throttle(); uid_n, _ = signup(phone="08100000900"); verify(uid_n, otp_of(uid_n))
        check("M4 a NEW account for a legacy customer's number (different format) gets no credit", wallet(uid_n) == 0, wallet(uid_n))
        n_claim = int(one("SELECT COUNT(*) FROM welcome_credit_claims WHERE source='backfill'"))
        check("M5 backfill rows carry amount 0 and are marked 'backfill'", n_claim >= 4 and float(one("SELECT COALESCE(SUM(amount),0) FROM welcome_credit_claims WHERE source='backfill'")) == 0, n_claim)
        legacy_ids = ",".join(str(v) for v in legacy.values())
        before = db("SELECT user_id,identity_key,source FROM welcome_credit_claims WHERE user_id IN (%s) ORDER BY user_id" % legacy_ids)
        apply_migration_008()
        after = db("SELECT user_id,identity_key,source FROM welcome_credit_claims WHERE user_id IN (%s) ORDER BY user_id" % legacy_ids)
        check("M6 re-running migration 008 does not error and leaves the legacy claim rows unchanged (note: it also closes any customer who has not claimed yet, so run it once, just before deploying)", before == after and len(before) == len(legacy), (len(before), len(after)))

    bad = [n for n, ok in results if not ok]
    print("\nSUMMARY (%s): %d passed, %d failed of %d" % (BACKEND, len(results) - len(bad), len(bad), len(results)))
    if bad: print("FAILED:", bad)
    return 1 if bad else 0

if __name__ == "__main__":
    code = 1
    try: code = main()
    finally:
        for p in procs:
            p.terminate()
            try: p.wait(5)
            except Exception: p.kill()
        shutil.rmtree(TMP, ignore_errors=True)
    sys.exit(code)
