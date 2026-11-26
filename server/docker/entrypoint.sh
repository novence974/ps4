#!/bin/sh
# Waits for the database, installs the library on first start, then runs Apache.
set -e
cd /var/www/app

until mysqladmin ping --skip-ssl -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" --silent 2>/dev/null; do
  echo "waiting for database..."; sleep 2
done

if [ ! -f config/config.php ]; then
  if [ -z "$ADMIN_PASS" ]; then
    echo "Set ADMIN_PASS (8+ chars, letters and digits) before the first start." >&2; exit 1
  fi
  DEMO=""; [ "${INSTALL_DEMO:-1}" = "1" ] && DEMO="--demo"
  su -s /bin/sh www-data -c "php bin/install.php --db-host='$DB_HOST' --db-name='$DB_NAME' --db-user='$DB_USER' \
     --db-pass='$DB_PASS' --url='${APP_URL:-http://localhost:8080}' --admin-user='${ADMIN_USER:-admin}' \
     --admin-email='${ADMIN_EMAIL:-admin@example.com}' --admin-pass='$ADMIN_PASS' $DEMO"
fi

# background jobs (imports, cleanup) once a minute
( while true; do su -s /bin/sh www-data -c "php bin/cron.php" >/dev/null 2>&1; sleep 60; done ) &

exec "$@"
