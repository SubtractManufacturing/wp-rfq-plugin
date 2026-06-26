#!/usr/bin/env bash
# Shared helpers for HTTP QA scripts against wp-env dev site.
set -euo pipefail

: "${BASE_URL:=${WP_BASE_URL:-http://localhost:8888}}"
: "${REST:=${BASE_URL}/?rest_route=/rfq/v1}"

qa_clear_session_rate_limits() {
  npx wp-env run cli --env-cwd=wp-content/rfq-plugin-root wp db query \
    "DELETE FROM wp_options WHERE option_name LIKE '_transient_rfq_sessions_%' OR option_name LIKE '_transient_timeout_rfq_sessions_%';" \
    >/dev/null 2>&1 || true
}

qa_json_field() {
  local json="$1"
  local field="$2"
  echo "$json" | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j[$argv[1]] ?? "";' "$field"
}

qa_http_code() {
  echo "$1" | sed -n 's/^__HTTP__://p'
}

qa_body_only() {
  echo "$1" | sed '/^__HTTP__:/d'
}

qa_assert_status() {
  local label="$1"
  local expected="$2"
  local response="$3"
  local actual
  actual="$(qa_http_code "$response")"

  if [[ "$actual" == "$expected" ]]; then
    echo "  ok  ${label} (HTTP ${actual})"
    QA_PASS=$((QA_PASS + 1))
  else
    echo "  FAIL  ${label} (expected HTTP ${expected}, got ${actual})" >&2
    qa_body_only "$response" >&2
    QA_FAIL=$((QA_FAIL + 1))
  fi
}

qa_api_post() {
  local path="$1"
  shift
  curl -sS -X POST "${REST}${path}" "$@" -w "\n__HTTP__:%{http_code}"
}

qa_api_patch() {
  local path="$1"
  shift
  curl -sS -X PATCH "${REST}${path}" "$@" -w "\n__HTTP__:%{http_code}"
}

qa_api_delete() {
  local path="$1"
  shift
  curl -sS -X DELETE "${REST}${path}" "$@" -w "\n__HTTP__:%{http_code}"
}

qa_api_put() {
  local path="$1"
  shift
  curl -sS -X PUT "${REST}${path}" "$@" -w "\n__HTTP__:%{http_code}"
}

qa_s3_object_action() {
  local action="$1"
  local key="$2"
  npx wp-env run cli --env-cwd=wp-content/rfq-plugin-root \
    env RFQ_QA_S3_ACTION="$action" RFQ_QA_S3_KEY="$key" \
    wp eval-file scripts/qa-s3-object.php 2>/dev/null | tr -d '\r'
}
