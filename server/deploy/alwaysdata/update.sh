#!/usr/bin/env bash
# Update the installed library to the latest version from GitHub (keeps config, database, uploads).
#   bash ~/ps4/server/deploy/alwaysdata/update.sh
set -euo pipefail
REPO="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$REPO"
git pull --ff-only
php server/bin/cron.php >/dev/null 2>&1 || true
echo "تم التحديث إلى: $(git log -1 --format='%h %s')"
