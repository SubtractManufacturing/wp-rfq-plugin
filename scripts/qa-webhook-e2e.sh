#!/usr/bin/env bash
# HTTP-level ERP webhook QA against scripts/ci-webhook-mock.mjs (no real ERP).
#
# Requires: mock server already running (CI starts it), wp-env up for optional full submit path.
#
# Usage:
#   node scripts/ci-webhook-mock.mjs &
#   npm run qa:webhook-e2e
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

MOCK_HOST="${RFQ_WEBHOOK_MOCK_HOST:-127.0.0.1}"
MOCK_PORT="${RFQ_WEBHOOK_MOCK_PORT:-8765}"
MOCK_PATH="${RFQ_WEBHOOK_MOCK_PATH:-/rfq/import}"
WEBHOOK_SECRET="${RFQ_ERP_WEBHOOK_SECRET:-ci-webhook-secret}"
# wp-env WordPress/cli containers reach the host mock via host.docker.internal (Linux + Docker 20.10+).
WEBHOOK_URL="${RFQ_ERP_WEBHOOK_URL:-http://host.docker.internal:${MOCK_PORT}${MOCK_PATH}}"

pass=0
fail=0

echo "QA webhook-e2e (mock ${MOCK_HOST}:${MOCK_PORT}${MOCK_PATH}, target ${WEBHOOK_URL})"
echo

echo "0. Mock server health"
health_code="$(curl -sS -o /dev/null -w "%{http_code}" "http://${MOCK_HOST}:${MOCK_PORT}/health" 2>/dev/null || echo "000")"
if [[ "$health_code" == "200" ]]; then
  echo "  ok  mock health (HTTP ${health_code})"
  pass=$((pass + 1))
else
  echo "  FAIL  mock not reachable at http://${MOCK_HOST}:${MOCK_PORT}/health (HTTP ${health_code})" >&2
  echo "        Start it with: node scripts/ci-webhook-mock.mjs" >&2
  fail=$((fail + 1))
  exit 1
fi

if ! npx wp-env status >/dev/null 2>&1; then
  echo "  FAIL  wp-env is not running (npm run wp-env start)" >&2
  exit 1
fi

echo "1. Direct notify_receipt dispatch (wp-cli → mock via host.docker.internal)"
npx wp-env run cli --env-cwd=wp-content/rfq-plugin-root wp eval "
update_option('rfq_erp_webhook_url', '${WEBHOOK_URL}');
RFQ_Secrets::ensure_encryption_key();
RFQ_Secrets::set_secret('rfq_erp_webhook_secret', '${WEBHOOK_SECRET}');
RFQ_Webhook::notify_receipt(
    'RFQ-20260627-009999',
    '550e8400-e29b-41d4-a716-446655440000',
    'intake/550e8400-e29b-41d4-a716-446655440000/meta/receipt.json'
);
" >/dev/null

sleep 2

requests_json="$(curl -sS "http://${MOCK_HOST}:${MOCK_PORT}/requests")"
request_count="$(echo "$requests_json" | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo (int)($j["count"] ?? 0);')"

if [[ "$request_count" -ge 1 ]]; then
  echo "  ok  mock received webhook POST (${request_count} total)"
  pass=$((pass + 1))
else
  echo "  FAIL  mock received no webhook requests" >&2
  fail=$((fail + 1))
fi

sig_ok="$(echo "$requests_json" | php -r '
$j = json_decode(stream_get_contents(STDIN), true);
$last = $j["requests"][count($j["requests"]) - 1] ?? null;
echo ($last && ($last["signature_valid"] ?? false)) ? "1" : "0";
')"

if [[ "$sig_ok" == "1" ]]; then
  echo "  ok  X-RFQ-Signature validated by mock"
  pass=$((pass + 1))
else
  echo "  FAIL  mock did not validate X-RFQ-Signature" >&2
  fail=$((fail + 1))
fi

echo
if [[ "$fail" -eq 0 ]]; then
  echo "QA webhook-e2e passed (${pass} checks)."
  exit 0
fi

echo "QA webhook-e2e failed (${fail} check(s), ${pass} passed)." >&2
exit 1
