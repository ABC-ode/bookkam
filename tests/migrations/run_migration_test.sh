#!/usr/bin/env bash
# Applies the payment-related migrations to a DISPOSABLE MySQL database and checks the result.
# Usage: MYSQL_HOST=127.0.0.1 MYSQL_USER=root MYSQL_PWD=root bash tests/migrations/run_migration_test.sh
# Never point this at a real database: it drops and recreates `bookkam_migration_test`.
set -euo pipefail
cd "$(dirname "$0")/../.."
DB=bookkam_migration_test
M() { mysql -h"${MYSQL_HOST:-127.0.0.1}" -u"${MYSQL_USER:-root}" "$@"; }
Q() { M -N -B "$DB" -e "$1"; }
fail() { echo "FAIL: $1"; exit 1; }
ok()   { echo "PASS $1"; }

M -e "DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB CHARACTER SET utf8mb4;"
M "$DB" < tests/migrations/base_schema.sql
for f in 003_payments_booking_kind 005_payments_method_paystack_opay 006_provider_reference 007_provider_reference_unique; do
  M "$DB" < "migrations/$f.sql" || fail "migration $f failed"
  ok "applied $f"
done

[ "$(Q "SELECT COUNT(*) FROM payments")" = "5" ] || fail "rows lost"; ok "no payment rows lost"
[ "$(Q "SELECT provider_reference FROM payments WHERE id=1")" = "BKM-WEBSITE-1-1700000001" ] || fail "legacy reference not copied"; ok "legacy Paystack references copied to provider_reference"
[ "$(Q "SELECT paystack_reference FROM payments WHERE id=1")" = "BKM-WEBSITE-1-1700000001" ] || fail "legacy column altered"; ok "legacy paystack_reference column preserved"
[ "$(Q "SELECT COUNT(*) FROM payments WHERE provider_reference IS NULL")" = "3" ] || fail "NULL references changed"; ok "rows without a reference stay NULL"
[ "$(Q "SELECT method FROM payments WHERE id=1")" = "paystack" ] || fail "historical method value lost"; ok "historical method='paystack' rows intact (005 original enum retained)"
[ "$(Q "SELECT booking_kind FROM payments WHERE id=1")" = "website" ] || fail "booking_kind default"; ok "booking_kind added with default 'website'"
Q "INSERT INTO payments (booking_id,amount,method,status,provider_reference,booking_kind) VALUES (9,1,'opay','pending','BKM-OP-X-1','website')" || fail "new-style insert"
ok "new-style insert (method opay, provider_reference) works"
if Q "INSERT INTO payments (booking_id,amount,method,status,provider_reference) VALUES (10,1,'opay','pending','BKM-OP-X-1')" 2>/dev/null; then fail "duplicate provider_reference accepted"; fi
ok "duplicate provider_reference rejected by unique index"
Q "INSERT INTO payments (booking_id,amount,method,status) VALUES (11,1,'cash','pending'),(12,1,'cash','pending')" || fail "multiple NULL references"
ok "multiple NULL provider_reference rows allowed"
Q "INSERT INTO payments (booking_id,amount,method,status,is_test,provider_reference,booking_kind) VALUES (0,100,'test','success',1,'test_wallet_1_k','topup')" || fail "test top-up row (booking_kind fits VARCHAR(10), booking_id 0)"
ok "test top-up audit row fits the schema"
if Q "INSERT INTO payments (booking_id,amount,method,status,provider_reference,booking_kind) VALUES (0,1,'test','success','x2','wallet_top_up')" 2>/dev/null; then fail "13-char booking_kind should not fit VARCHAR(10) in strict mode"; fi
ok "13-character booking_kind is rejected in strict mode (reason top-ups use 'topup')"
if M "$DB" < migrations/006_provider_reference.sql 2>/dev/null; then fail "006 re-run unexpectedly succeeded"; fi
ok "re-running 006 fails safely (duplicate column) and changes nothing"
[ "$(Q "SELECT COUNT(*) FROM payments WHERE paystack_reference IS NOT NULL AND provider_reference IS NULL")" = "0" ] || fail "data changed by failed re-run"
# duplicate-reference pre-check on a fresh copy: 007 must refuse (and leave the table untouched) when duplicates exist
M -e "DROP DATABASE IF EXISTS ${DB}_dup; CREATE DATABASE ${DB}_dup CHARACTER SET utf8mb4;"
M "${DB}_dup" < tests/migrations/base_schema.sql
M "${DB}_dup" -e "INSERT INTO payments (booking_id,amount,method,status,paystack_reference) VALUES (6,1,'paystack','pending','DUPREF'),(7,1,'paystack','pending','DUPREF')"
for f in 003_payments_booking_kind 005_payments_method_paystack_opay 006_provider_reference; do M "${DB}_dup" < "migrations/$f.sql"; done
if M "${DB}_dup" < migrations/007_provider_reference_unique.sql 2>/dev/null; then fail "007 accepted duplicates"; fi
[ "$(M -N -B "${DB}_dup" -e "SELECT COUNT(*) FROM payments")" = "7" ] || fail "rows changed by refused 007"
ok "007 refuses to run when duplicate references exist and leaves the table unchanged"
M -e "DROP DATABASE $DB; DROP DATABASE ${DB}_dup;"
echo "MIGRATION CHECKS PASSED"
