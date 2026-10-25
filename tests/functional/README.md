# Functional tests (local only)

Runs the real PHP endpoints against a throw-away SQLite database. It never touches production,
MySQL, or real payment providers (Paystack webhooks are simulated with a locally generated signature).

Limits: SQLite is only a stand-in, so MySQL-only SQL (e.g. `request_payout`'s `DATE_SUB`, `FOR UPDATE`
in discount-code booking) is not exercised. `schema.sql` is inferred from the code, not the live schema.

```
S=$(mktemp -d); cp -r api $S/testbed
cat > $S/testbed/config/db.php <<'PHP'
<?php
$pdo = new PDO("sqlite:" . getenv("TESTBED_DB"), null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction("NOW", fn() => date("Y-m-d H:i:s"), 0);
$pdo->sqliteCreateFunction("CURDATE", fn() => date("Y-m-d"), 0);
PHP
mkdir $S/tests && python3 -c "import sqlite3;sqlite3.connect('$S/tests/t.db').executescript(open('tests/functional/schema.sql').read())"
(cd $S/testbed && APP_ENV=production PAYSTACK_SECRET=testbed-only-paystack JWT_SECRET=x TESTBED_DB=$S/tests/t.db php -S 127.0.0.1:8099 &)
SCRATCH=$S python3 tests/functional/run_tests.py
```
Checks labelled `[OPEN]` document known gaps that are not fixed yet.
