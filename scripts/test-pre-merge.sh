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

echo
echo "Pre-merge checks passed."

bash "$ROOT/scripts/restore-dev-s3-from-env.sh"
