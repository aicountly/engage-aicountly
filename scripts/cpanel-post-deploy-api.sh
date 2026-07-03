#!/usr/bin/env bash
# Post-deploy hook for Engage server-php on cPanel (run via SSH after rsync).
# Never overwrites or edits an existing .env — server secrets are managed only on the server.

set -euo pipefail

API_DIR="${1:-.}"
cd "$API_DIR"

if [ -f .env ]; then
  echo ".env already exists — leaving server secrets unchanged (deploy will not modify .env)"
else
  echo "ERROR: missing .env in ${API_DIR}"
  echo "Create api/.env manually on the server (copy from .env.example) before running production deploy."
  exit 1
fi

mkdir -p writable/cache writable/session writable/logs writable/uploads
chmod -R 775 writable/cache writable/session writable/logs writable/uploads 2>/dev/null || \
  chmod -R 777 writable/cache writable/session writable/logs writable/uploads

echo "---- Running database migrations ----"
CI_ENVIRONMENT=production php spark migrate 2>&1

MARKER="writable/.engage_seed_complete"
if [ -f "$MARKER" ]; then
  echo "Seeders already applied — skipping (marker: ${MARKER})"
else
  echo "---- First deploy: running seeders ----"
  for seeder in RolesSeeder ProductsSeeder PipelineStagesSeeder BotActionsSeeder LeadSourcesSeeder SettingsSeeder OwnerSeeder; do
    echo "--- php spark db:seed ${seeder} ---"
    CI_ENVIRONMENT=production php spark db:seed "$seeder"
  done
  touch "$MARKER"
  chmod 644 "$MARKER"
  echo "Seeders complete. Marker created — future deploys will skip seeding."
fi

if php -r 'if (function_exists("opcache_reset")) { opcache_reset(); echo "OPcache reset\n"; }'; then
  :
fi

chmod 600 .env 2>/dev/null || true
echo "Post-deploy complete (api/.env content was not modified)."
