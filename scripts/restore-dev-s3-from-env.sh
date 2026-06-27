#!/usr/bin/env bash
# Restore wp-env dev site (localhost:8888) S3 admin settings from a local env file.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

CI_ENV_FILE="$ROOT/config/.ci-s3-env.tmp"

rfq_write_ci_s3_env_file() {
  if [[ -n "${RFQ_S3_ENDPOINT:-}" && -n "${RFQ_S3_BUCKET:-}" && -n "${RFQ_S3_ACCESS_KEY_ID:-}" && -n "${RFQ_S3_REGION:-}" && -n "${RFQ_S3_SECRET_KEY:-}" ]]; then
    umask 077
    cat > "$CI_ENV_FILE" <<EOF
RFQ_S3_ENDPOINT=${RFQ_S3_ENDPOINT}
RFQ_S3_BUCKET=${RFQ_S3_BUCKET}
RFQ_S3_ACCESS_KEY_ID=${RFQ_S3_ACCESS_KEY_ID}
RFQ_S3_REGION=${RFQ_S3_REGION}
RFQ_S3_SECRET_KEY=${RFQ_S3_SECRET_KEY}
EOF
    return 0
  fi

  rm -f "$CI_ENV_FILE"
  return 1
}

cleanup_ci_env_file() {
  rm -f "$CI_ENV_FILE"
}

rfq_write_ci_s3_env_file || true
trap cleanup_ci_env_file EXIT

if ! npx wp-env status >/dev/null 2>&1; then
  echo "Dev S3 restore skipped (wp-env is not running)."
  exit 0
fi

npx wp-env run cli --env-cwd=wp-content/rfq-plugin-root wp eval-file scripts/restore-dev-s3-from-env.php
