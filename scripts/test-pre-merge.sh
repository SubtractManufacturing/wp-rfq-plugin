#!/usr/bin/env bash
# M1 pre-merge gate: unit + integration (+ optional REST smoke).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

SMOKE=1
if [[ "${1:-}" == "--no-smoke" ]]; then
  SMOKE=0
fi

echo "==> PHP unit tests"
composer test

echo
echo "==> Acceptance coverage (M1–M3 backend AC IDs)"
npm run test:acceptance-coverage

echo
echo "==> PHP integration tests (wp-env tests-cli)"
if ! npx wp-env status >/dev/null 2>&1; then
  echo "    wp-env not running — starting..."
  npx wp-env start
fi
npm run test:integration

if [[ "$SMOKE" -eq 1 ]]; then
  echo
  echo "==> REST smoke (host → localhost:8888)"
  if ! curl -sS -o /dev/null "http://localhost:8888/?rest_route=/rfq/v1/health" 2>/dev/null \
    && ! curl -sS -o /dev/null "http://localhost:8888/wp-json/rfq/v1/health" 2>/dev/null; then
    echo "    wp-env site not reachable — starting..."
    npx wp-env start
  fi
  bash "$ROOT/scripts/smoke-rest.sh"
fi

bash "$ROOT/scripts/restore-dev-s3-from-env.sh"

rfq_s3_e2e_configured() {
  [[ -f "$ROOT/config/dev.env.local" ]] && return 0
  [[ -n "${RFQ_S3_ENDPOINT:-}" && -n "${RFQ_S3_BUCKET:-}" && -n "${RFQ_S3_ACCESS_KEY_ID:-}" && -n "${RFQ_S3_REGION:-}" && -n "${RFQ_S3_SECRET_KEY:-}" ]]
}

if rfq_s3_e2e_configured; then
  echo
  echo "==> S3 upload E2E (dev.env.local or RFQ_S3_* env vars present)"
  bash "$ROOT/scripts/qa-s3-upload.sh"
else
  echo
  echo "==> S3 upload E2E skipped (no config/dev.env.local or RFQ_S3_* env vars)"
fi

echo
echo "Pre-merge checks passed."
