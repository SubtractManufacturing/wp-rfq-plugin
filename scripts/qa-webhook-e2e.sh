#!/usr/bin/env bash
# HTTP-level ERP webhook QA against scripts/ci-webhook-mock.mjs (no real ERP).
#
# Requires: mock server already running (CI starts it), wp-env up.
#
# Usage:
#   node scripts/ci-webhook-mock.mjs &
#   npm run qa:webhook-e2e
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

MOCK_PROBE_HOST="${RFQ_WEBHOOK_MOCK_PROBE_HOST:-127.0.0.1}"
MOCK_PORT="${RFQ_WEBHOOK_MOCK_PORT:-8765}"
MOCK_PATH="${RFQ_WEBHOOK_MOCK_PATH:-/rfq/import}"
WEBHOOK_SECRET="${RFQ_ERP_WEBHOOK_SECRET:-ci-webhook-secret}"

rfq_webhook_target_url() {
  if [[ -n "${RFQ_ERP_WEBHOOK_URL:-}" ]]; then
    echo "$RFQ_ERP_WEBHOOK_URL"
    return 0
  fi

  local gateway=""
  gateway="$(docker network inspect bridge --format='{{(index .IPAM.Config 0).Gateway}}' 2>/dev/null || true)"

  local candidates=(host.docker.internal)
  [[ -n "$gateway" ]] && candidates+=("$gateway")
  candidates+=(172.17.0.1)

  local host
  for host in "${candidates[@]}"; do
    if npx wp-env run cli -- sh -c "curl -sf --connect-timeout 2 http://${host}:${MOCK_PORT}/health >/dev/null" >/dev/null 2>&1; then
      echo "http://${host}:${MOCK_PORT}${MOCK_PATH}"
      return 0
    fi
  done

  echo "http://host.docker.internal:${MOCK_PORT}${MOCK_PATH}"
}

pass=0
fail=0

echo "QA webhook-e2e (mock probe ${MOCK_PROBE_HOST}:${MOCK_PORT}${MOCK_PATH})"
echo

echo "0. Mock server health"
health_code="$(curl -sS -o /dev/null -w "%{http_code}" "http://${MOCK_PROBE_HOST}:${MOCK_PORT}/health" 2>/dev/null || echo "000")"
if [[ "$health_code" == "200" ]]; then
  echo "  ok  mock health (HTTP ${health_code})"
  pass=$((pass + 1))
else
  echo "  FAIL  mock not reachable at http://${MOCK_PROBE_HOST}:${MOCK_PORT}/health (HTTP ${health_code})" >&2
  echo "        Start it with: node scripts/ci-webhook-mock.mjs" >&2
  fail=$((fail + 1))
  exit 1
fi

if ! npx wp-env status >/dev/null 2>&1; then
  echo "  FAIL  wp-env is not running (npm run wp-env start)" >&2
  exit 1
fi

WEBHOOK_URL="$(rfq_webhook_target_url)"
echo "   target ${WEBHOOK_URL}"

echo "1. Direct notify_receipt dispatch (wp-cli → mock on host)"
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

request_count=0
requests_json='{"count":0,"requests":[]}'
for _ in $(seq 1 15); do
  requests_json="$(curl -sS "http://${MOCK_PROBE_HOST}:${MOCK_PORT}/requests")"
  request_count="$(echo "$requests_json" | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo (int)($j["count"] ?? 0);')"
  if [[ "$request_count" -ge 1 ]]; then
    break
  fi
  sleep 1
done

if [[ "$request_count" -ge 1 ]]; then
  echo "  ok  mock received webhook POST (${request_count} total)"
  pass=$((pass + 1))
else
  echo "  FAIL  mock received no webhook requests (target ${WEBHOOK_URL})" >&2
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
