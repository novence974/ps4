#!/usr/bin/env bash
# One-command install of the library on an alwaysdata account (run over SSH, from the account's home).
#
#   bash ~/www/ps4/server/deploy/alwaysdata/install.sh
#
# Before running it, in the alwaysdata panel:
#   1. Databases > MySQL: create a database (e.g. ACCOUNT_upsl) and a user with full rights on it.
# Passwords are read without echo and passed through the environment, never on the command line.
set -euo pipefail

ACCOUNT="$(whoami)"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"     # .../server
URL="${UPSL_URL:-https://${ACCOUNT}.alwaysdata.net}"
DB_HOST="${UPSL_DB_HOST:-mysql-${ACCOUNT}.alwaysdata.net}"

say() { printf '\n\033[1m%s\033[0m\n' "$*"; }

say "ULTIMATE PS LIBRARY — تثبيت على alwaysdata (${ACCOUNT})"
if [ -f "$ROOT/config/config.php" ]; then
  echo "المكتبة مثبتة مسبقاً (config/config.php موجود). للتحديث استخدم: bash $ROOT/deploy/alwaysdata/update.sh"
  exit 0
fi

php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || { echo "يلزم PHP 8.2 أو أحدث. غيّره من لوحة alwaysdata: Environment > PHP"; exit 1; }

read -r -p "اسم قاعدة البيانات [${ACCOUNT}_upsl]: " DB_NAME; DB_NAME="${DB_NAME:-${ACCOUNT}_upsl}"
read -r -p "مستخدم قاعدة البيانات [${ACCOUNT}]: " DB_USER; DB_USER="${DB_USER:-${ACCOUNT}}"
read -r -s -p "كلمة مرور قاعدة البيانات: " DB_PASS; echo
read -r -p "اسم مستخدم المدير [admin]: " ADMIN_USER; ADMIN_USER="${ADMIN_USER:-admin}"
read -r -p "بريد المدير: " ADMIN_EMAIL
while :; do
  read -r -s -p "كلمة مرور المدير (8 أحرف أو أكثر، حروف وأرقام): " ADMIN_PASS; echo
  read -r -s -p "أعد كتابتها: " ADMIN_PASS2; echo
  [ "$ADMIN_PASS" = "$ADMIN_PASS2" ] && [ ${#ADMIN_PASS} -ge 8 ] && break
  echo "غير متطابقتين أو أقصر من 8 أحرف، حاول مجدداً."
done

say "تثبيت قاعدة البيانات والإعدادات…"
cd "$ROOT"
UPSL_DB_PASS="$DB_PASS" UPSL_ADMIN_PASS="$ADMIN_PASS" php bin/install.php \
  --db-host="$DB_HOST" --db-name="$DB_NAME" --db-user="$DB_USER" \
  --url="$URL" --admin-user="$ADMIN_USER" --admin-email="$ADMIN_EMAIL" --timezone=Asia/Qatar
unset DB_PASS ADMIN_PASS ADMIN_PASS2

# tight permissions on secrets (HTTPS is forced from the panel: site > SSL > Force HTTPS)
chmod 600 config/config.php
chmod -R go-rwx storage config

say "تم. باقي خطوتان في لوحة alwaysdata:"
cat <<EOF
  1) Web > Sites > عدّل الموقع ${ACCOUNT}.alwaysdata.net:
       Type: PHP    PHP version: 8.3    Root directory: ${ROOT#$HOME}/public
       SSL: فعّل «Force HTTPS usage»
  2) Advanced > Scheduled tasks > Add:
       Type: Command    Command: php ${ROOT}/bin/cron.php    Frequency: every 5 minutes (*/5 * * * *)

ثم افتح ${URL} وسجّل الدخول، وفي تطبيق الإدارة اكتب الخادم: ${URL}
EOF
