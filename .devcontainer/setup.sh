#!/usr/bin/env bash
# First start in Codespaces: create .env with random passwords and print them once.
set -e
cd "$(dirname "$0")/.."
if [ ! -f .env ]; then
  url="http://localhost:8080"
  [ -n "$CODESPACE_NAME" ] && url="https://${CODESPACE_NAME}-8080.${GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN}"
  pass="Admin-$(openssl rand -hex 6)"
  cat > .env <<ENV
DB_PASS=$(openssl rand -hex 16)
ADMIN_USER=admin
ADMIN_EMAIL=admin@example.com
ADMIN_PASS=$pass
APP_URL=$url
INSTALL_DEMO=1
ENV
  echo "Admin login: admin / $pass   (saved in .env)"
fi
