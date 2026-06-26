#!/usr/bin/env bash
# REST smoke: HTTP probe from host + full checks via wp-env cli.
set -euo pipefail

BASE_URL="${WP_BASE_URL:-http://localhost:8888}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

echo "REST smoke"
echo

echo "0. HTTP probe (host → rest_route health)"
code="$(curl -sS -o /dev/null -w "%{http_code}" "${BASE_URL}/?rest_route=/rfq/v1/health" 2>/dev/null || echo "000")"
if [[ "$code" == "503" || "$code" == "200" ]]; then
  echo "  ok  HTTP probe (HTTP ${code})"
else
  wp_json_code="$(curl -sS -o /dev/null -w "%{http_code}" "${BASE_URL}/wp-json/rfq/v1/health" 2>/dev/null || echo "000")"
  if [[ "$wp_json_code" == "503" || "$wp_json_code" == "200" ]]; then
    echo "  ok  HTTP probe via /wp-json (HTTP ${wp_json_code})"
  else
    echo "  FAIL  could not reach RFQ REST API at ${BASE_URL}" >&2
    echo "        Start wp-env: npm run wp-env start" >&2
    exit 1
  fi
fi

echo
echo "1–8. REST dispatch checks (tests-cli — isolated from dev site settings)"
# Use tests-cli (port 8889), not cli (port 8888). smoke-rest.php clears S3 options
# to assert health-without-config; running it on cli wipes local admin credentials.
npx wp-env run tests-cli --env-cwd=wp-content/rfq-plugin-root wp eval-file scripts/smoke-rest.php

echo
bash "$ROOT/scripts/restore-dev-s3-from-env.sh"
