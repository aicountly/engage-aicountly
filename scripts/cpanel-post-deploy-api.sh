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

if [ ! -f app/Views/errors/html/production.php ]; then
  echo "Installing CI4 error views (required for production exception pages)..."
  mkdir -p app/Views/errors
  cp -r vendor/codeigniter4/framework/app/Views/errors/. app/Views/errors/ 2>/dev/null || true
fi
mkdir -p app/Language/en
if [ ! -f app/Language/en/Errors.php ]; then
  cp vendor/codeigniter4/framework/system/Language/en/Errors.php app/Language/en/Errors.php 2>/dev/null || true
fi

echo "---- Console SSO env (values masked) ----"
grep -E '^(CONSOLE_API_URL|CONSOLE_API_BASE_URL|CONTROLLER_APP_CODE)=' .env | sed 's/=.*/=***/' || echo "WARNING: add CONSOLE_API_URL and CONTROLLER_APP_CODE=engage to api/.env"

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
