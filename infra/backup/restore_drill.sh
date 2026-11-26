#!/bin/sh
# تجربة النسخ الاحتياطي والاسترجاع (NFR-05، 09 §10): نسخة كاملة من قاعدة التطبيق، واسترجاعها في قاعدة منفصلة،
# ومقارنة العدد الدقيق لصفوف كل جدول، ثم حذف قاعدة التجربة. يعمل داخل حاوية MySQL، وكلمة المرور من بيئتها فلا تظهر.
#   docker compose exec -T mysql sh -s < infra/backup/restore_drill.sh
# الاسترجاع لنقطة زمنية (RPO ≤ 15 دقيقة) يحتاج سجل binlog على خادم الإنتاج، ويُختبر بعد حسم الاستضافة.
set -eu
DB="${MYSQL_DATABASE:-dwahi}"
DRILL="${DB}_restore_drill"
export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
q() { mysql -uroot -N -B -e "$1"; }

t0=$(date +%s)
mysqldump -uroot --single-transaction --routines --triggers --events --set-gtid-purged=OFF "$DB" | gzip > /tmp/drill.sql.gz
t1=$(date +%s)
size=$(du -k /tmp/drill.sql.gz | cut -f1)

q "DROP DATABASE IF EXISTS \`$DRILL\`; CREATE DATABASE \`$DRILL\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
gunzip -c /tmp/drill.sql.gz | mysql -uroot "$DRILL"
t2=$(date +%s)

tables=$(q "SELECT table_name FROM information_schema.tables WHERE table_schema='$DB' AND table_type='BASE TABLE' ORDER BY table_name")
total=0; bad=0; rows=0
for t in $tables; do
  a=$(q "SELECT COUNT(*) FROM \`$DB\`.\`$t\`")
  b=$(q "SELECT COUNT(*) FROM \`$DRILL\`.\`$t\`")
  total=$((total+1)); rows=$((rows+a))
  if [ "$a" != "$b" ]; then bad=$((bad+1)); echo "MISMATCH $t: $a vs $b"; fi
done
trig_a=$(q "SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema='$DB'")
trig_b=$(q "SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema='$DRILL'")
mig=$(q "SELECT COUNT(*) FROM \`$DRILL\`.migrations")

q "DROP DATABASE \`$DRILL\`"
rm -f /tmp/drill.sql.gz

echo "date: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "database: $DB"
echo "backup: ${size} KB gzip in $((t1-t0))s"
echo "restore: $((t2-t1))s"
echo "tables: $total, rows: $rows, mismatches: $bad"
echo "triggers: $trig_a source, $trig_b restored"
echo "migrations restored: $mig"
if [ "$bad" -eq 0 ] && [ "$trig_a" = "$trig_b" ]; then echo "RESULT: PASS"; else echo "RESULT: FAIL"; exit 1; fi
