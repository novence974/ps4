#!/usr/bin/env bash
# Update the installed library to the latest version from GitHub (keeps config, database, uploads).
#   bash ~/ps4/server/deploy/alwaysdata/update.sh
set -euo pipefail
REPO="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$REPO"
git pull --ff-only
php server/bin/upgrade.php
# alwaysdata reports HTTPS to PHP correctly, so redirect plain http:// visits to https://
if grep -q "'url' => 'https://" server/config/config.php && grep -q "'force_https' => false" server/config/config.php; then
  sed -i "s/'force_https' => false/'force_https' => true/" server/config/config.php && echo "HTTPS: التحويل التلقائي مفعّل"
fi
php server/bin/cron.php >/dev/null 2>&1 || true
echo "تم التحديث إلى: $(git log -1 --format='%h %s')"
