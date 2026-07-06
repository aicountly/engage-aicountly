#!/usr/bin/env bash
# Normalize VITE_API_URL for production builds.
# API is served from public_html/api/ — frontend must call /api/v1/..., not /v1/...
set -euo pipefail

raw="${1:-}"
raw="$(printf '%s' "$raw" | tr -d '\r\n' | sed 's/[[:space:]]*$//')"

if [ -z "$raw" ] || [ "$raw" = "/" ]; then
  printf '/api\n'
  exit 0
fi

raw="${raw%/}"

if [[ "$raw" =~ ^https?:// ]]; then
  if [[ "$raw" == */api ]]; then
    printf '%s\n' "$raw"
  else
    printf '%s/api\n' "$raw"
  fi
  exit 0
fi

if [[ "$raw" != /* ]]; then
  raw="/${raw}"
fi

printf '%s\n' "$raw"
