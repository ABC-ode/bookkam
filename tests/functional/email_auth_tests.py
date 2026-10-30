#!/usr/bin/env python3
"""BOOKKAM tests: e-mail verification, password reset, login throttling, sessions, welcome credit, migrations 008/009.

Local only: 127.0.0.1 servers, FAKE data, a THROWAWAY database, a fake SMTP server (the real Mailer talks to it), and a
stub for Google's token endpoint. No real e-mail, SMS, payment provider or production system is ever contacted.
Each test asserts the SECURE behaviour; run it against old code and the weakness shows up as a FAIL.

  python3 tests/functional/email_auth_tests.py                       # SQLite stand-in (what CI runs)
  BK_BACKEND=mysql python3 tests/functional/email_auth_tests.py      # real MySQL/MariaDB: row locking + migrations
MySQL env: BK_MYSQL_DB (default bookkam_auth_test), BK_MYSQL_SOCKET *or* BK_MYSQL_HOST/BK_MYSQL_PORT,
           BK_MYSQL_ADMIN_USER/PASS (DDL user for the mysql CLI), BK_MYSQL_APP_USER/PASS (user PHP connects as).
NEVER point this at a real database: it DROPs and recreates tables.
"""
import base64, concurrent.futures as cf, email as emaillib, hashlib, http.server, json, os, re, shutil, socketserver, sqlite3, subprocess, sys, tempfile, threading, time
import urllib.error, urllib.parse, urllib.request

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
HERE = os.path.dirname(os.path.abspath(__file__))
BACKEND = os.environ.get("BK_BACKEND", "sqlite")
API_SRC = os.environ.get("BK_API_DIR", os.path.join(ROOT, "api"))
TMP = tempfile.mkdtemp(prefix="bookkam-auth-test-")
TESTBED = os.path.join(TMP, "testbed")
SQLITE_DB = os.path.join(TMP, "t.db")
MY_DB = os.environ.get("BK_MYSQL_DB", "bookkam_auth_test")
JWT = "unit-test-jwt-secret-0123456789abcdef0123456789abcdef"
# A fresh port range per run (override with BK_TEST_PORT_BASE): sockets left in TIME_WAIT by a previous run cannot collide.
_BASE = int(os.environ.get("BK_TEST_PORT_BASE", 20000 + (os.getpid() * 7) % 20000))
PORTS = {n: _BASE + i for i, n in enumerate(["main", "ipcap", "wc", "noapp", "prodfile", "dev", "smtp", "google"])}
SMTP_PASS = "smtp-secret-123"
DEAD = "http://127.0.0.1:9"
procs, results = [], []
PW = "correct-horse-9"

# ----------------------------------------------------------------------------- database
def _mysql_args():
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

def db(sql, args=(), database=None):
    if BACKEND == "sqlite":
        c = sqlite3.connect(SQLITE_DB, timeout=30); c.row_factory = sqlite3.Row
        try:
            rows = c.execute(sql, args).fetchall(); c.commit(); return [dict(r) for r in rows]
        finally: c.close()
    parts = sql.split("?"); assert len(parts) == len(args) + 1, sql
    q = parts[0] + "".join(_lit(a) + p for a, p in zip(args, parts[1:]))
    r = subprocess.run(_mysql_args() + ["-D", database or MY_DB, "-B", "-e", q], capture_output=True, text=True)
    if r.returncode: raise RuntimeError(r.stderr.strip() + " :: " + q[:140])
    lines = [l for l in r.stdout.split("\n") if l != ""]
    if not lines: return []
    head = lines[0].split("\t")
    return [dict(zip(head, [None if x == "NULL" else x for x in l.split("\t")])) for l in lines[1:]]

def one(sql, args=()):
    r = db(sql, args); return list(r[0].values())[0] if r else None

def run_sql_file(path, database=None):
    r = subprocess.run(_mysql_args() + ["-D", database or MY_DB, "-t"], input=open(path).read(), capture_output=True, text=True)
    if r.returncode: raise RuntimeError(r.stderr.strip())
    return r.stdout

def migration(n): return os.path.join(ROOT, "migrations", n)

def setup_db():
    if BACKEND == "sqlite":
        c = sqlite3.connect(SQLITE_DB); c.executescript(open(os.path.join(HERE, "schema.sql")).read()); c.commit(); c.close(); return
    adm = _mysql_args()
    subprocess.run(adm + ["-e", "DROP DATABASE IF EXISTS %s; CREATE DATABASE %s CHARACTER SET utf8mb4;" % (MY_DB, MY_DB)], check=True)
    subprocess.run(adm + ["-D", MY_DB], input=open(os.path.join(HERE, "mysql_schema.sql")).read(), text=True, check=True)
    au, ap = os.environ.get("BK_MYSQL_APP_USER", "root"), os.environ.get("BK_MYSQL_APP_PASS", "")
    if au != "root":
        subprocess.run(adm + ["-e", "CREATE USER IF NOT EXISTS '%s'@'%%' IDENTIFIED BY '%s'; GRANT ALL ON %s.* TO '%s'@'%%'; FLUSH PRIVILEGES;" % (au, ap, MY_DB, au)], check=True)

# ----------------------------------------------------------------------------- fake SMTP + Google stub
MAILS, MAIL_LOCK, SMTP_LOG = [], threading.Lock(), {"creds": [], "fail": False}
class SmtpHandler(socketserver.StreamRequestHandler):
    def handle(self):
        w = lambda s: (self.wfile.write((s + "\r\n").encode()), self.wfile.flush())
        w("220 fake ESMTP"); rcpt = []
        while True:
            line = self.rfile.readline()
            if not line: return
            cmd = line.decode(errors="replace").strip(); up = cmd.upper()
            if up.startswith("EHLO"): w("250-fake"); w("250 AUTH LOGIN")
            elif up == "AUTH LOGIN":
                w("334 VXNlcm5hbWU6"); u = self.rfile.readline(); w("334 UGFzc3dvcmQ6"); p = self.rfile.readline()
                SMTP_LOG["creds"].append((base64.b64decode(u.strip()).decode(), base64.b64decode(p.strip()).decode())); w("235 ok")
            elif up.startswith("RCPT TO"):
                if SMTP_LOG["fail"]: w("550 mailbox unavailable")
                else: rcpt.append(re.search(r"<(.*)>", cmd).group(1)); w("250 ok")
            elif up == "DATA":
                w("354 go"); data = b""
                while True:
                    l = self.rfile.readline()
                    if l == b".\r\n": break
                    data += l[1:] if l.startswith(b"..") else l
                with MAIL_LOCK: MAILS.append({"to": list(rcpt), "raw": data})
                w("250 queued")
            elif up == "QUIT": w("221 bye"); return
            else: w("250 ok")

def parse_mail(m):
    msg = emaillib.message_from_bytes(m["raw"])
    subj = emaillib.header.decode_header(msg["Subject"])[0]; subj = subj[0].decode(subj[1] or "utf-8") if isinstance(subj[0], bytes) else subj[0]
    return subj, msg.get_payload(decode=True).decode("utf-8")

def mails_to(addr):
    with MAIL_LOCK: return [m for m in MAILS if addr.lower() in [t.lower() for t in m["to"]]]

def link_token(addr, kind, nth=-1):
    ms = mails_to(addr); assert ms, "no mail for " + addr
    body = parse_mail(ms[nth])[1]
    m = re.search(r"#%s=([a-f0-9]{64})" % kind, body); assert m, body
    return m.group(1)

class GoogleStub(http.server.BaseHTTPRequestHandler):   # id_token is a ';'-separated key=value string
    def do_GET(self):
        q = urllib.parse.parse_qs(urllib.parse.urlparse(self.path).query).get("id_token", [""])[0]
        d = dict(kv.split("=", 1) for kv in q.split(";") if "=" in kv)
        out = {"sub": d.get("sub"), "aud": d.get("aud", "test-client"), "email_verified": d.get("verified", "true"), "name": "G User"}
        if d.get("email"): out["email"] = d["email"]
        body = json.dumps(out).encode(); self.send_response(200); self.send_header("Content-Type", "application/json"); self.end_headers(); self.wfile.write(body)
    def log_message(self, *a): pass

class _TcpServer(socketserver.ThreadingTCPServer):
    allow_reuse_address = True      # must be set before bind(): quick re-runs would otherwise hit TIME_WAIT
    daemon_threads = True
class _HttpServer(http.server.ThreadingHTTPServer):
    allow_reuse_address = True
    daemon_threads = True

def serve(handler, port, kind):
    srv = (_TcpServer if kind == "tcp" else _HttpServer)(("127.0.0.1", port), handler)
    threading.Thread(target=srv.serve_forever, daemon=True).start(); return srv

# ----------------------------------------------------------------------------- PHP servers
STUB_DB = '''<?php
$pdo = new PDO("sqlite:" . getenv("TESTBED_DB"), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction("NOW", fn() => date("Y-m-d H:i:s"), 0);
$pdo->sqliteCreateFunction("CURDATE", fn() => date("Y-m-d"), 0);
'''
def setup_testbed():
    shutil.copytree(API_SRC, TESTBED, ignore=shutil.ignore_patterns(".env", ".git"))
    if BACKEND == "sqlite": open(os.path.join(TESTBED, "config", "db.php"), "w").write(STUB_DB)
    else:
        open(os.path.join(TESTBED, ".env"), "w").write("DB_HOST=%s\nDB_PORT=%s\nDB_NAME=%s\nDB_USER=%s\nDB_PASS=%s\n" % (
            os.environ.get("BK_MYSQL_HOST", "127.0.0.1"), os.environ.get("BK_MYSQL_PORT", "3306"), MY_DB,
            os.environ.get("BK_MYSQL_APP_USER", "root"), os.environ.get("BK_MYSQL_APP_PASS", "")))

def start(name, env_extra):
    port = PORTS[name]
    env = {k: v for k, v in os.environ.items() if not k.startswith(("APP_", "JWT_", "MAIL_", "SMTP_", "LOGIN_", "WELCOME_", "GOOGLE_", "OPAY"))}
    env.update({"TESTBED_DB": SQLITE_DB, "PHP_CLI_SERVER_WORKERS": "8", "https_proxy": DEAD, "http_proxy": DEAD, "all_proxy": DEAD, "no_proxy": "127.0.0.1,localhost"})
    env.update(env_extra)
    p = subprocess.Popen(["php", "-d", "display_errors=0", "-S", "127.0.0.1:%d" % port, "-t", TESTBED], env=env, stdout=subprocess.DEVNULL,
                         stderr=open(os.path.join(TMP, "server-%d.log" % port), "a"))
    procs.append(p)
    for _ in range(60):
        try: urllib.request.urlopen("http://127.0.0.1:%d/" % port, timeout=1); break
        except urllib.error.HTTPError: break
        except Exception: time.sleep(0.1)

def phplog(name): 
    try: return open(os.path.join(TMP, "server-%d.log" % PORTS[name])).read()
    except Exception: return ""

def call(name, ep, action, body=None, token=None, raw=None):
    req = urllib.request.Request("http://127.0.0.1:%d/api/%s?action=%s" % (PORTS[name], ep, action),
                                 data=raw if raw is not None else (json.dumps(body).encode() if body is not None else None), method="POST" if (body is not None or raw is not None) else "GET")
    req.add_header("Content-Type", "application/json")
    if token: req.add_header("Authorization", "Bearer " + token)
    try:
        with urllib.request.urlopen(req, timeout=60) as r: code, txt = r.status, r.read().decode()
    except urllib.error.HTTPError as e: code, txt = e.code, e.read().decode()
    try: js = json.loads(txt)
    except Exception: js = {"_raw": txt[:200]}
    return code, js

def parallel(n, fn):
    with cf.ThreadPoolExecutor(n) as ex: return list(ex.map(lambda i: fn(i), range(n)))

def check(name, ok, detail=""):
    results.append((name, bool(ok)))
    print(("PASS " if ok else "FAIL ") + name + ((" :: " + str(detail)[:260]) if (detail != "" and not ok) else ""))

# ----------------------------------------------------------------------------- helpers
_n = [0]
def addr(prefix="u"): _n[0] += 1; return "%s%d@test.example" % (prefix, _n[0])
def clear_throttle(): db("DELETE FROM auth_throttle")
def uid_of(e): return int(one("SELECT id FROM users WHERE LOWER(email)=?", (e.lower(),)))
def wallet(uid): return float(one("SELECT wallet_balance FROM users WHERE id=?", (uid,)))
def claims(uid): return int(one("SELECT COUNT(*) FROM welcome_credit_claims WHERE user_id=?", (uid,)))
def tok(j): return bool(isinstance(j, dict) and j.get("token"))
def register(e, name="Test User", pw=PW, role="customer", server="main", confirm=None):
    return call(server, "auth.php", "register_email", {"name": name, "email": e, "password": pw, "confirm": pw if confirm is None else confirm, "role": role, "city": "Calabar"})
def login(e, pw=PW, server="main"): return call(server, "auth.php", "login_email", {"email": e, "password": pw})
def verify_link(t, server="main"): return call(server, "auth.php", "verify_email", {"token": t})
def signup_verified(e=None, role="customer", server="main", pw=PW):
    e = e or addr(); c, j = register(e, role=role, server=server, pw=pw); assert c == 200, (c, j)
    assert verify_link(link_token(e, "verify_email"), server)[0] == 200
    return e

def main():
    print("backend:", BACKEND)
    setup_db(); setup_testbed()
    srv = [serve(SmtpHandler, PORTS["smtp"], "tcp"), serve(GoogleStub, PORTS["google"], "http")]
    legacy = {}
    if BACKEND == "mysql":   # accounts that exist BEFORE the migrations (old behaviour: credited at signup, never verified)
        pw_hash = subprocess.check_output(["php", "-r", "echo password_hash('%s',PASSWORD_BCRYPT,['cost'=>4]);" % PW]).decode()
        db("INSERT INTO users(phone,email,name,role,city,wallet_balance,password_hash) VALUES (NULL,'legacy.mail@test.example','Legacy Mail','customer','Calabar',1000,?)", (pw_hash,))
        db("INSERT INTO users(phone,email,name,role,city,wallet_balance) VALUES ('2348100000900',NULL,'Legacy Phone','customer','Calabar',1000)")
        db("INSERT INTO users(phone,email,name,role,city,wallet_balance,password_hash) VALUES (NULL,'legacy.driver@test.example','Legacy Driver','driver','Calabar',0,?)", (pw_hash,))
        run_sql_file(migration("008_otp_throttle_and_welcome_claims.sql")); run_sql_file(migration("009_email_auth.sql"))
        legacy = {r["name"]: int(r["id"]) for r in db("SELECT id,name FROM users")}
    smtp = {"APP_ENV": "production", "JWT_SECRET": JWT, "APP_URL": "https://test.bookkam.example", "MAIL_TRANSPORT": "smtp", "SMTP_HOST": "127.0.0.1",
            "SMTP_PORT": str(PORTS["smtp"]), "SMTP_ENCRYPTION": "none", "SMTP_USER": "mailer@test.example", "SMTP_PASS": SMTP_PASS, "MAIL_FROM": "no-reply@test.example"}
    start("main", smtp)
    start("ipcap", dict(smtp, LOGIN_IP_MAX_FAILS="6"))
    start("wc", dict(smtp, WELCOME_CREDIT_ENABLED="1"))
    start("noapp", {k: v for k, v in smtp.items() if k != "APP_URL"})
    outbox = os.path.join(TMP, "outbox")
    start("prodfile", {"APP_ENV": "production", "JWT_SECRET": JWT, "APP_URL": "https://test.bookkam.example", "MAIL_TRANSPORT": "file", "MAIL_OUTBOX_DIR": outbox, "MAIL_FROM": "no-reply@test.example"})
    start("dev", dict(smtp, APP_ENV="development", WELCOME_CREDIT_ENABLED="1", GOOGLE_CLIENT_ID="test-client", GOOGLE_TOKENINFO_URL="http://127.0.0.1:%d/tokeninfo" % PORTS["google"]))

    # ================= registration and e-mail verification =================
    clear_throttle(); e = addr(); c, j = register(e, name="Ada Obi")
    row = db("SELECT * FROM users WHERE LOWER(email)=?", (e,))[0]
    check("E1a register returns a generic 200 with no session token", c == 200 and j.get("success") is True and "token" not in j, (c, j))
    check("E1b the account is created UNVERIFIED with a bcrypt password hash", row["email_verified_at"] in (None, "") and str(row["password_hash"]).startswith("$2y$"), row)
    t = link_token(e, "verify_email"); subj, body = parse_mail(mails_to(e)[-1])
    check("E1c a verification mail reaches the address through the real SMTP client (AUTH LOGIN, encoded subject)", "BOOKKAM" in subj and ("https://test.bookkam.example/#verify_email=" + t) in body and SMTP_LOG["creds"] and SMTP_LOG["creds"][-1] == ("mailer@test.example", SMTP_PASS), (subj, SMTP_LOG["creds"][-1:] ))
    stored = db("SELECT token_hash FROM auth_tokens WHERE user_id=?", (int(row["id"]),))[0]["token_hash"]
    check("E1d only the SHA-256 of the token is stored (the raw token is not in the database)", stored == hashlib.sha256(t.encode()).hexdigest() and stored != t)
    check("E1e the link carries the token in the URL fragment (never sent to servers, logs or Referer)", "/#verify_email=" in body and "?token=" not in body and "verify_email&token" not in body)
    c, j = login(e)
    check("E2 login before verification is refused (403, code email_unverified, no token)", c == 403 and j.get("code") == "email_unverified" and not tok(j), (c, j))
    c, j = verify_link(t)
    check("E3a the link verifies the address", c == 200 and j.get("success") is True and db("SELECT email_verified_at FROM users WHERE LOWER(email)=?", (e,))[0]["email_verified_at"], (c, j))
    c, j = login(e)
    check("E3b login now works and returns a token", c == 200 and tok(j), (c, j))
    c, j = verify_link(t)
    check("E3c the link cannot be used twice", c == 400 and not tok(j), (c, j))

    e = addr(); register(e); t = link_token(e, "verify_email")
    db("UPDATE auth_tokens SET expires_at=? WHERE token_hash=?", ("2000-01-01 00:00:00", hashlib.sha256(t.encode()).hexdigest()))
    check("E4a an expired link is refused", verify_link(t)[0] == 400)
    e = addr(); register(e); t = link_token(e, "verify_email")
    check("E4b a one-character-changed link is refused", verify_link(t[:-1] + ("0" if t[-1] != "0" else "1"))[0] == 400)
    check("E4c malformed / empty / non-string tokens are refused", all(verify_link(x)[0] == 400 for x in ["", "abc", "x" * 64, None, 12345, ["a"]]))
    c, j = call("main", "auth.php", "reset_password", {"token": t, "password": "another-pass-1", "confirm": "another-pass-1"})
    check("E4d a verification link cannot be used as a password-reset link", c == 400, (c, j))
    clear_throttle()

    e = addr(); register(e); t = link_token(e, "verify_email")
    res = parallel(12, lambda i: verify_link(t)[0])
    check("E5 12 concurrent uses of one verification link succeed exactly once", res.count(200) == 1, sorted(res))

    clear_throttle(); e = addr(); a = register(e); before = len(mails_to(e)); t1 = link_token(e, "verify_email")
    b = register(e.upper()); after = len(mails_to(e)); t2 = link_token(e, "verify_email")
    check("E6a registering the same (upper-cased) address again is the same account: one row, fresh link, old link dead",
          int(one("SELECT COUNT(*) FROM users WHERE LOWER(email)=?", (e,))) == 1 and after == before + 1 and t1 != t2 and verify_link(t1)[0] == 400 and verify_link(t2)[0] == 200, (before, after))
    n1 = len(mails_to(e)); c3, j3 = register(e, pw="something-else-9"); n2 = len(mails_to(e))
    check("E6b a VERIFIED address receives no mail and its password cannot be replaced by re-registering", n1 == n2 and login(e)[0] == 200 and login(e, "something-else-9")[0] == 401, (n1, n2))
    new_c, new_j = register(addr()); old_c, old_j = register(e)
    check("E7 the registration reply is identical for a new address and an existing one", (new_c, new_j) == (old_c, old_j), (new_j, old_j))

    clear_throttle(); ee = addr()
    cases = {"7 chars": ("Abcdef1", None), "73 bytes": ("a" * 73, None), "equals email": (ee, None), "mismatch": (PW, "different-9")}
    bad = {k: register(ee, pw=p, confirm=cf)[0] for k, (p, cf) in cases.items() if register(ee, pw=p, confirm=cf)[0] != 400}
    check("E8 weak/too-long/mismatched passwords are refused (400)", not bad and int(one("SELECT COUNT(*) FROM users WHERE LOWER(email)=?", (ee,))) == 0, bad)
    clear_throttle(); ee = addr(); register(ee, name="<img src=x onerror=alert(1)>Eve\u0000 <b>Z</b>")
    nm = one("SELECT name FROM users WHERE LOWER(email)=?", (ee,))
    check("E9 names are stored as plain text (tags and control characters removed)", nm and "<" not in nm and ">" not in nm and "\x00" not in nm and "Eve" in nm, nm)
    check("E10 an address containing line breaks is refused (no mail-header injection)", register("victim@test.example\r\nBcc: evil@test.example")[0] == 400 and register("a b@test.example")[0] == 400)

    clear_throttle(); codes = [register(addr())[0] for _ in range(12)]
    check("E11 registration floods are limited per IP (429 after 10 an hour)", codes[:10] == [200] * 10 and set(codes[10:]) == {429}, codes)
    clear_throttle(); e = addr(); register(e)
    codes = [call("main", "auth.php", "resend_verification", {"email": e})[0] for _ in range(6)]
    check("E12 mail to one address is limited (429 after 5 an hour), resend replies are generic", set(codes[:4]) == {200} and 429 in codes, codes)
    clear_throttle(); ge = call("main", "auth.php", "resend_verification", {"email": "nobody-here@test.example"}); gk = call("main", "auth.php", "resend_verification", {"email": e})
    check("E13 resend_verification answers identically for unknown and existing addresses", ge == gk, (ge, gk))

    # ================= login limits =================
    clear_throttle(); e = signup_verified(); clear_throttle()
    wrong = [login(e, "wrong-pass-%d" % i)[0] for i in range(8)]; locked = login(e, PW)
    check("L1 8 wrong passwords then the CORRECT one: the account is locked (429, no token)", wrong == [401] * 8 and locked[0] == 429 and not tok(locked[1]), (wrong, locked[0]))
    clear_throttle(); e2 = signup_verified(); clear_throttle()
    [login(e2, "nope-nope-%d" % i) for i in range(3)]; ok = login(e2)
    more = [login(e2, "nope-again-%d" % i)[0] for i in range(7)]
    check("L2 a correct login forgives earlier failures (3 wrong, correct, then 7 more wrong are still not locked)", ok[0] == 200 and more == [401] * 7, (ok[0], more))
    clear_throttle(); a = login(e2, "wrong-wrong-1"); b = login("nobody@test.example", "wrong-wrong-1")
    check("L3 unknown account and wrong password are indistinguishable (same status and body)", a == b and a[0] == 401, (a, b))
    clear_throttle(); es = [signup_verified() for _ in range(2)]; clear_throttle()
    res = [login(x, "bad-guess-%d" % i, "ipcap")[0] for i, x in enumerate(es * 5)]
    check("L4 per-IP limit: guesses spread over several accounts hit 429 after 6 failures", res[:6] == [401] * 6 and set(res[6:]) == {429}, res)
    clear_throttle(); e3 = signup_verified(); db("UPDATE users SET is_blacklisted=1 WHERE LOWER(email)=?", (e3,)); clear_throttle()
    c, j = login(e3)
    check("L5 a suspended account cannot log in even with the right password", c == 400 and not tok(j), (c, j))
    clear_throttle(); db("INSERT INTO users(email,name,role,city,wallet_balance,password_hash,email_verified_at) VALUES ('admin@test.example','Admin','admin','Calabar',0,?,datetime('now'))" if BACKEND == "sqlite" else "INSERT INTO users(email,name,role,city,wallet_balance,password_hash,email_verified_at) VALUES ('admin@test.example','Admin','admin','Calabar',0,?,NOW())",
       (subprocess.check_output(["php", "-r", "echo password_hash('%s',PASSWORD_BCRYPT,['cost'=>4]);" % "Adm1n-pass-9"]).decode(),))
    aw = [call("main", "auth.php", "admin_login", {"email": "admin@test.example", "password": "bad-%d" % i})[0] for i in range(5)]
    al = call("main", "auth.php", "admin_login", {"email": "admin@test.example", "password": "Adm1n-pass-9"})
    check("L6 admin login is throttled: 5 wrong then the correct password is refused (429)", aw == [401] * 5 and al[0] == 429 and not tok(al[1]), (aw, al[0]))
    clear_throttle(); al = call("main", "auth.php", "admin_login", {"email": "admin@test.example", "password": "Adm1n-pass-9"})
    check("L7 admin login still works normally", al[0] == 200 and tok(al[1]), al)
    c, j = login("admin@test.example", "Adm1n-pass-9")
    check("L8 an admin account cannot sign in through the customer/driver login", c == 401 and not tok(j), (c, j))

    # ================= password reset =================
    clear_throttle(); e = signup_verified(); n0 = len(mails_to(e))
    ra = call("main", "auth.php", "forgot_password", {"email": e}); rb = call("main", "auth.php", "forgot_password", {"email": "ghost@test.example"}); rc = call("main", "auth.php", "forgot_password", {"email": "admin@test.example"})
    check("R1a forgot_password answers identically for known, unknown and admin addresses", ra == rb == rc and ra[0] == 200, (ra, rb, rc))
    check("R1b only the known non-admin address receives a mail", len(mails_to(e)) == n0 + 1 and not mails_to("ghost@test.example") and not mails_to("admin@test.example"))
    rt = link_token(e, "reset_password"); old_session = login(e)[1]["token"]
    c, j = call("main", "auth.php", "reset_password", {"token": rt, "password": "short", "confirm": "short"})
    c2, j2 = call("main", "auth.php", "reset_password", {"token": rt, "password": "n3w-password-ok", "confirm": "n3w-password-ok"})
    check("R2a a weak password does not burn the link; a valid one then works", c == 400 and c2 == 200, (c, c2, j2))
    check("R2b the old password stops working and the new one works", login(e, PW)[0] == 401 and login(e, "n3w-password-ok")[0] == 200)
    check("R2c the reset link cannot be reused", call("main", "auth.php", "reset_password", {"token": rt, "password": "third-password-1", "confirm": "third-password-1"})[0] == 400)
    time.sleep(1.1)
    check("R3 a session issued BEFORE the reset is rejected afterwards (revocation)", call("main", "auth.php", "update_profile", {"name": "x"}, token=old_session)[0] == 401)
    fresh = login(e, "n3w-password-ok")[1]["token"]
    check("R3b a session issued after the reset works", call("main", "auth.php", "update_profile", {"name": "Still Me"}, token=fresh)[0] == 200)

    clear_throttle(); e = addr(); register(e); call("main", "auth.php", "forgot_password", {"email": e}); rt = link_token(e, "reset_password")
    call("main", "auth.php", "reset_password", {"token": rt, "password": "reset-pass-123", "confirm": "reset-pass-123"})
    check("R4 resetting proves inbox control: an unverified account becomes verified and can log in", login(e, "reset-pass-123")[0] == 200)
    clear_throttle(); e = addr(); register(e); call("main", "auth.php", "forgot_password", {"email": e}); r1 = link_token(e, "reset_password")
    call("main", "auth.php", "forgot_password", {"email": e}); r2 = link_token(e, "reset_password")
    check("R5 asking for a new reset link kills the previous one", r1 != r2 and call("main", "auth.php", "reset_password", {"token": r1, "password": "xxxxxxxx-1", "confirm": "xxxxxxxx-1"})[0] == 400 and call("main", "auth.php", "reset_password", {"token": r2, "password": "yyyyyyyy-1", "confirm": "yyyyyyyy-1"})[0] == 200)
    clear_throttle(); e = signup_verified(); call("main", "auth.php", "forgot_password", {"email": e}); rt = link_token(e, "reset_password")
    db("UPDATE auth_tokens SET expires_at=? WHERE token_hash=?", ("2000-01-01 00:00:00", hashlib.sha256(rt.encode()).hexdigest()))
    check("R6 an expired reset link is refused", call("main", "auth.php", "reset_password", {"token": rt, "password": "zzzzzzzz-1", "confirm": "zzzzzzzz-1"})[0] == 400)
    clear_throttle(); admin_id = int(one("SELECT id FROM users WHERE email='admin@test.example'"))
    raw = "ab" * 32; db("INSERT INTO auth_tokens(user_id,purpose,token_hash,expires_at,created_at) VALUES (?,?,?,?,?)", (admin_id, "password_reset", hashlib.sha256(raw.encode()).hexdigest(), "2999-01-01 00:00:00", "2026-01-01 00:00:00"))
    c, j = call("main", "auth.php", "reset_password", {"token": raw, "password": "hacked-pass-12", "confirm": "hacked-pass-12"})
    check("R7 even a valid-looking token cannot reset an ADMIN password through the public flow", c == 400 and call("main", "auth.php", "admin_login", {"email": "admin@test.example", "password": "Adm1n-pass-9"})[0] == 200)
    clear_throttle(); codes = [call("main", "auth.php", "reset_password", {"token": "%064x" % i, "password": "whatever-123", "confirm": "whatever-123"})[0] for i in range(33)]
    check("R8 guessing reset tokens is rate-limited per IP (429 after 30)", codes[:30] == [400] * 30 and 429 in codes[30:], codes[-4:])

    # ================= e-mail transport safety =================
    check("M1 the SMTP password never appears in the PHP error log", SMTP_PASS not in phplog("main") and "smtp-secret" not in phplog("main"))
    clear_throttle(); e = addr(); c, j = register(e, server="prodfile")
    check("M2 MAIL_TRANSPORT=file is ignored in production: nothing is written, the reply is unchanged", c == 200 and not os.path.exists(outbox), (c, os.path.exists(outbox)))
    clear_throttle(); e = addr(); before = len(mails_to(e)); c, j = register(e, server="noapp")
    check("M3 without APP_URL no mail is sent and no broken link is produced (reply still generic)", c == 200 and len(mails_to(e)) == before, c)
    clear_throttle(); SMTP_LOG["fail"] = True; e = addr(); c, j = register(e); SMTP_LOG["fail"] = False
    check("M4 a failing mail server does not reveal itself or crash the request", c == 200 and j.get("success") is True, (c, j))
    c, j = login(e)
    check("M5 the account is still unverified, and resend works once mail recovers", c == 403 and call("main", "auth.php", "resend_verification", {"email": e})[0] == 200 and verify_link(link_token(e, "verify_email"))[0] == 200)

    # ================= profile + removed phone login =================
    clear_throttle(); e = signup_verified(); T = login(e)[1]["token"]
    c1 = call("main", "auth.php", "update_profile", {"email": "someone.else@test.example"}, token=T)
    c2 = call("main", "auth.php", "update_profile", {"name": "<script>alert(1)</script>New Name", "city": "Calabar<b>"}, token=T)
    row = db("SELECT name,city,email FROM users WHERE LOWER(email)=?", (e,))[0]
    check("P1 update_profile cannot change the login e-mail; name/city are stored as plain text", c1[0] == 400 and c2[0] == 200 and row["email"] == e and "<" not in row["name"] and "<" not in row["city"], (c1, c2, row))
    check("P2 phone-OTP actions no longer exist", all(call("main", "auth.php", a, {"phone": "2348000000000", "otp": "123456", "user_id": 1})[1].get("error") == "Invalid action" for a in ("send_otp", "verify_otp", "login")))
    src = "".join(open(os.path.join(root, f), errors="ignore").read() for root, _, fs in os.walk(os.path.join(ROOT, "api")) for f in fs if f.endswith(".php"))
    check("P3 no OTP / Termii / fixed test-code remains in the PHP code", not re.search(r"TERMII|TEST_OTP|verify_otp|send_otp|otp_code", src))

    # ================= admin-assisted recovery =================
    clear_throttle(); phone_only = None
    db("INSERT INTO users(phone,name,role,city,wallet_balance) VALUES ('2348100000777','Phone Only','customer','Calabar',250)"); phone_only = int(one("SELECT id FROM users WHERE phone='2348100000777'"))
    AT = call("main", "auth.php", "admin_login", {"email": "admin@test.example", "password": "Adm1n-pass-9"})[1]["token"]
    CT = login(signup_verified())[1]["token"]
    check("AR1 only an admin may issue recovery (customer 403, anonymous 401)", call("main", "admin.php", "issue_account_recovery", {"user_id": phone_only, "email": "po@test.example"}, token=CT)[0] == 403 and call("main", "admin.php", "issue_account_recovery", {"user_id": phone_only, "email": "po@test.example"})[0] == 401)
    c, j = call("main", "admin.php", "issue_account_recovery", {"user_id": phone_only, "email": "PO@test.example"}, token=AT)
    check("AR2 the admin action e-mails a reset link and never returns it in the response", c == 200 and j.get("emailed") is True and "token" not in json.dumps(j) and not re.search(r"[a-f0-9]{64}", json.dumps(j)), (c, j))
    rt = link_token("po@test.example", "reset_password")
    check("AR3 the person sets a password through the link and signs in; the balance is intact", call("main", "auth.php", "reset_password", {"token": rt, "password": "phone-user-pw-1", "confirm": "phone-user-pw-1"})[0] == 200 and login("po@test.example", "phone-user-pw-1")[0] == 200 and wallet(phone_only) == 250)
    check("AR4 an e-mail already used by another account (409) and admin targets (404) are refused",
          call("main", "admin.php", "issue_account_recovery", {"user_id": phone_only, "email": e}, token=AT)[0] == 409 and call("main", "admin.php", "issue_account_recovery", {"user_id": admin_id, "email": "x@test.example"}, token=AT)[0] == 404)

    # ================= welcome credit =================
    clear_throttle(); e = signup_verified(); uid = uid_of(e)
    check("W1 default configuration: no welcome credit, no claim (feature is off)", wallet(uid) == 0 and claims(uid) == 0, wallet(uid))
    clear_throttle(); e = addr(); register(e, server="wc"); uid = uid_of(e)
    check("W2a an UNVERIFIED address gets nothing", wallet(uid) == 0 and claims(uid) == 0)
    verify_link(link_token(e, "verify_email"), "wc")
    check("W2b verifying the address credits exactly 1000 and records one 'email' claim", wallet(uid) == 1000 and claims(uid) == 1 and one("SELECT identity_type FROM welcome_credit_claims WHERE user_id=?", (uid,)) == "email", (wallet(uid), claims(uid)))
    call("wc", "auth.php", "forgot_password", {"email": e}); call("wc", "auth.php", "reset_password", {"token": link_token(e, "reset_password"), "password": "brand-new-pass-1", "confirm": "brand-new-pass-1"})
    [login(e, "brand-new-pass-1", "wc") for _ in range(2)]
    check("W3 reset / repeated logins never credit again", wallet(uid) == 1000 and claims(uid) == 1, wallet(uid))
    base = "alias%d" % int(time.time()); first = base + "@gmail.com"
    clear_throttle(); signup_verified(first, server="wc"); w1 = wallet(uid_of(first))
    alias = [base[:5] + "." + base[5:] + "+promo@gmail.com", base + "+two@googlemail.com", base.upper() + "+Z@GMAIL.COM"]
    got = []
    for a in alias:
        clear_throttle(); register(a, server="wc"); rows = db("SELECT id FROM users WHERE LOWER(email)=?", (a.lower(),))
        if rows: verify_link(link_token(a, "verify_email"), "wc"); got.append(wallet(int(rows[0]["id"])))
    check("W4 the same inbox under dot/plus/case/googlemail aliases cannot claim again", w1 == 1000 and all(g == 0 for g in got), (w1, got))
    clear_throttle(); a, b = addr("race"), addr("race")
    for x in (a, b): register(x, server="wc")
    ta, tb = link_token(a, "verify_email"), link_token(b, "verify_email")
    db("UPDATE users SET email=? WHERE LOWER(email)=?", ("shared+x@test.example", b.lower()))   # same canonical identity as ...
    db("UPDATE users SET email=? WHERE LOWER(email)=?", ("shared@test.example", a.lower()))
    res = parallel(2, lambda i: verify_link([ta, tb][i], "wc")[0])
    tot = sum(wallet(int(r["id"])) for r in db("SELECT id FROM users WHERE LOWER(email) IN ('shared@test.example','shared+x@test.example')"))
    check("W5 two accounts of one identity verified at the same instant receive ONE credit in total", res == [200, 200] and tot == 1000, (res, tot))
    clear_throttle(); d = signup_verified(role="driver", server="wc"); du = uid_of(d)
    check("W6 drivers never receive the credit", wallet(du) == 0 and claims(du) == 0)
    # Google (stubbed endpoint, dev server). The id_token string carries the claims the stub returns.
    gs = "g%d" % int(time.time() * 1000); ge = "google.user%d@test.example" % _n[0]
    c, j = call("dev", "auth.php", "google_login", {"id_token": "sub=%s;email=%s;verified=true" % (gs, ge), "role": "customer", "city": "Calabar"})
    gu = int(one("SELECT id FROM users WHERE google_id=?", (gs,)))
    check("G1 Google sign-in (verified e-mail) creates a verified account, signs in and credits once", c == 200 and tok(j) and db("SELECT email_verified_at FROM users WHERE id=?", (gu,))[0]["email_verified_at"] and wallet(gu) == 1000 and claims(gu) == 1, (c, wallet(gu)))
    call("dev", "auth.php", "google_login", {"id_token": "sub=%s;email=%s;verified=true" % (gs, ge), "role": "customer", "city": "Calabar"})
    check("G2 signing in with Google again does not credit again or duplicate the account", wallet(gu) == 1000 and int(one("SELECT COUNT(*) FROM users WHERE google_id=?", (gs,))) == 1)
    gs2 = gs + "x"; c, j = call("dev", "auth.php", "google_login", {"id_token": "sub=%s;email=unverified.%s;verified=false" % (gs2, ge), "role": "customer", "city": "Calabar"})
    gu2 = int(one("SELECT id FROM users WHERE google_id=?", (gs2,)))
    check("G3 a Google e-mail that Google has NOT verified is never stored or trusted", c == 200 and one("SELECT email FROM users WHERE id=?", (gu2,)) in (None, "") and db("SELECT email_verified_at FROM users WHERE id=?", (gu2,))[0]["email_verified_at"] in (None, ""), db("SELECT email,email_verified_at FROM users WHERE id=?", (gu2,)))
    # pre-registration hijack: attacker registers the victim's address with a password; the victim then proves ownership with Google
    victim = "victim%d@test.example" % _n[0]; clear_throttle(); register(victim, pw="attacker-chosen-1", server="dev")
    attacker_token = None; db("UPDATE users SET email_verified_at=NULL WHERE LOWER(email)=?", (victim,))
    c, j = call("dev", "auth.php", "google_login", {"id_token": "sub=%s;email=%s;verified=true" % (gs + "v", victim), "role": "customer", "city": "Calabar"})
    vrow = db("SELECT * FROM users WHERE LOWER(email)=?", (victim,))[0]
    check("G4 pre-registration hijack closed: when the real owner signs in with Google, the attacker's password is wiped", c == 200 and tok(j) and vrow["password_hash"] in (None, "") and vrow["google_id"] == gs + "v" and vrow["email_verified_at"], vrow)
    check("G5 ...and the attacker can no longer log in with it", login(victim, "attacker-chosen-1", "dev")[0] == 401)
    adm = call("dev", "auth.php", "google_login", {"id_token": "sub=adm-sub;email=admin@test.example;verified=true", "role": "customer", "city": "Calabar"})
    check("G5b a Google account whose verified e-mail matches an ADMIN is refused (admins sign in with their password only)", adm[0] == 403 and not tok(adm[1]) and one("SELECT google_id FROM users WHERE email='admin@test.example'") in (None, ""), adm)
    bad = call("dev", "auth.php", "google_login", {"id_token": "sub=zz;email=a@b.c;verified=true;aud=someone-elses-client", "role": "customer", "city": "Calabar"})
    check("G6 a Google token issued for another client id is refused", bad[0] == 401 and not tok(bad[1]), bad)

    if BACKEND == "mysql":
        # ================= migrations on a real MySQL-family engine =================
        lp, lm, ld = legacy["Legacy Phone"], legacy["Legacy Mail"], legacy["Legacy Driver"]
        check("MG1 009 grandfathers existing accounts that have an e-mail (they keep working) and not phone-only ones",
              db("SELECT email_verified_at FROM users WHERE id=?", (lm,))[0]["email_verified_at"] is not None and db("SELECT email_verified_at FROM users WHERE id=?", (lp,))[0]["email_verified_at"] is None)
        clear_throttle(); c, j = login("legacy.mail@test.example")
        check("MG2 an existing e-mail customer signs in with the old password after the migrations", c == 200 and tok(j), (c, j))
        check("MG3 008 closed every pre-existing customer against a second welcome credit (none unclaimed)", int(one("SELECT COUNT(*) FROM users u LEFT JOIN welcome_credit_claims c ON c.user_id=u.id WHERE u.role='customer' AND u.name LIKE 'Legacy%' AND c.id IS NULL")) == 0)
        call("wc", "auth.php", "forgot_password", {"email": "legacy.mail@test.example"}); call("wc", "auth.php", "reset_password", {"token": link_token("legacy.mail@test.example", "reset_password"), "password": "legacy-new-pass-1", "confirm": "legacy-new-pass-1"})
        check("MG4 a legacy customer who resets their password gets no welcome credit", wallet(lm) == 1000, wallet(lm))
        nm = addr("late"); register(nm, server="wc"); lu = uid_of(nm)
        run_sql_file(migration("008_otp_throttle_and_welcome_claims.sql"))
        check("MG5 RE-RUNNING 008 does not backfill (a customer registered after the first run is still unclaimed)", claims(lu) == 0)
        verify_link(link_token(nm, "verify_email"), "wc")
        check("MG6 ...so that customer can still receive their one welcome credit afterwards", wallet(lu) == 1000 and claims(lu) == 1, (wallet(lu), claims(lu)))
        nv = addr("unver"); register(nv); nvu = uid_of(nv)
        run_sql_file(migration("009_email_auth.sql"))
        check("MG7 RE-RUNNING 009 does not mark an unverified new registration as verified", db("SELECT email_verified_at FROM users WHERE id=?", (nvu,))[0]["email_verified_at"] in (None, ""))
        check("MG8 009 created the unique e-mail index when no duplicates existed", int(one("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND INDEX_NAME='uq_users_email'")) >= 1)
        dd = MY_DB + "_dup"
        subprocess.run(_mysql_args() + ["-e", "DROP DATABASE IF EXISTS %s; CREATE DATABASE %s;" % (dd, dd)], check=True)
        subprocess.run(_mysql_args() + ["-D", dd], input="CREATE TABLE users(id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(150), role VARCHAR(20)); INSERT INTO users(email,role) VALUES ('Same@x.com','customer'),('same@X.com','customer'),(NULL,'customer');", text=True, check=True)
        out = run_sql_file(migration("009_email_auth.sql"), dd)
        idx = int(one("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME='users' AND INDEX_NAME='uq_users_email'", (dd,)))
        check("MG9 with duplicate e-mails in old data, 009 still succeeds, skips the unique index and says so", idx == 0 and "duplicate e-mail" in out and int(one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='users' AND COLUMN_NAME='email_verified_at'", (dd,))) == 1, out[-200:])
        subprocess.run(_mysql_args() + ["-e", "DROP DATABASE IF EXISTS %s;" % dd])

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
