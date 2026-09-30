#!/usr/bin/env bash
# post-deploy-checks.sh — is the live Engage portal really there, and is it the build just deployed?
#
#   scripts/ci/post-deploy-checks.sh production
#
# Every check goes through scripts/ci/verify-live.sh, which accepts only the app's real answer
# (JSON that satisfies a jq filter, or a page that carries a given string) and asks again from the
# server over VERIFY_SSH when the runner is shown the host's anti-bot page or cannot connect.
# Exits 1 when any check fails; health that depends on configuration only warns. The Engage
# portal has one environment, engage.aicountly.org.
#
# Environment (all optional):
#   EXPECTED_ENTRY     the hashed entry asset of the build just deployed (assets/index-<hash>.js
#                      from web/dist/index.html). Empty, as when checking without a deploy: the
#                      page must carry the Engage portal <title> instead.
#   EXPECTED_REVISION  not compared: the deploy stamps api/REVISION but the API does not serve it.
#   VERIFY_SSH         the command prefix that runs one command on the server (verify-live.sh).
#   VERIFY_BASE_URL    check this origin instead of engage.aicountly.org (tests only).
set -uo pipefail

env_name="${1:-}"
case "$env_name" in
  production) host=engage.aicountly.org ;;
  *) echo "usage: $0 production" >&2; exit 2 ;;
esac
base="${VERIFY_BASE_URL:-https://${host}}"
base="${base%/}"
verify="$(dirname "$0")/verify-live.sh"

failed=0
check() {
  bash "$verify" "$@" || failed=$((failed + 1))
}

# The API: it must be the Engage API; "ready" (JWT secret and database in api/.env) only warns.
check json "Engage API (${env_name})" "${base}/api/health" \
  '.service == "aicountly-engage-api"' \
  '.status == "ready"'

# The SPA: the build just deployed, or (no deploy) the Engage portal page at all.
check page "Engage SPA (${env_name})" "${base}/" "${EXPECTED_ENTRY:-<title>AICOUNTLY Engage Portal</title>}"

if [ "$failed" -ne 0 ]; then
  echo "${failed} post-deploy check(s) failed on ${base}" >&2
  exit 1
fi
echo "All post-deploy checks passed on ${base}"
