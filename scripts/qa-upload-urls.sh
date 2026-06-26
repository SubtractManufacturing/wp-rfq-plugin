#!/usr/bin/env bash
# Manual QA for upload-urls — runs against wp-env dev site (localhost:8888).
# Requires: wp-env running, S3 configured (npm run dev:restore-s3).
# Contact must be saved before upload URLs (Phase 3.3 gate).
#
# Usage:
#   npm run qa:upload-urls
#   npm run qa:s3-upload                         # full E2E PUT + delete edge cases (config/PlaceHolder.step)
#   npm run qa:upload-urls -- --full             # include rate-limit probes (slow)
set -euo pipefail

BASE_URL="${WP_BASE_URL:-http://localhost:8888}"
REST="${BASE_URL}/?rest_route=/rfq/v1"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck source=qa-common.sh
source "$(dirname "$0")/qa-common.sh"

DO_PUT=""
DO_FULL=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --put)
      DO_PUT="${2:-}"
      shift 2
      ;;
    --full)
      DO_FULL=1
      shift
      ;;
    *)
      shift
      ;;
  esac
done

pass=0
fail=0

json_field() {
  local json="$1"
  local field="$2"
  echo "$json" | php -r '$j=json_decode(stream_get_contents(STDIN), true); echo $j[$argv[1]] ?? "";' "$field"
}

http_code() {
  echo "$1" | sed -n 's/^__HTTP__://p'
}

body_only() {
  echo "$1" | sed '/^__HTTP__:/d'
}

assert_status() {
  local label="$1"
  local expected="$2"
  local response="$3"
  local actual
  actual="$(http_code "$response")"

  if [[ "$actual" == "$expected" ]]; then
    echo "  ok  ${label} (HTTP ${actual})"
    pass=$((pass + 1))
  else
    echo "  FAIL  ${label} (expected HTTP ${expected}, got ${actual})" >&2
    body_only "$response" >&2
    fail=$((fail + 1))
  fi
}

api_post() {
  local path="$1"
  shift
  curl -sS -X POST "${REST}${path}" "$@" -w "\n__HTTP__:%{http_code}"
}

api_patch() {
  local path="$1"
  shift
  curl -sS -X PATCH "${REST}${path}" "$@" -w "\n__HTTP__:%{http_code}"
}

patch_contact() {
  local sid="$1"
  local token="$2"
  api_patch "/sessions/${sid}/contact" \
    -H "Authorization: Bearer ${token}" \
    -H "Content-Type: application/json" \
    -d '{"first_name":"Jane","last_name":"Smith","email":"jane@example.com"}'
}

echo "QA upload-urls (HTTP via ${BASE_URL})"
echo

qa_clear_session_rate_limits

echo "0. Health check"
health="$(curl -sS "${REST}/health" -w "\n__HTTP__:%{http_code}")"
assert_status "health ok" "200" "$health"
health_body="$(body_only "$health")"
if [[ "$(json_field "$health_body" status)" != "ok" ]]; then
  echo "  FAIL  health body status is not ok — run: npm run dev:restore-s3" >&2
  fail=$((fail + 1))
fi

echo "1. Create session"
create="$(api_post "/sessions")"
assert_status "create session" "201" "$create"
CREATE_BODY="$(body_only "$create")"
SID="$(json_field "$CREATE_BODY" session_id)"
TOKEN="$(json_field "$CREATE_BODY" token)"

if [[ -z "$SID" || -z "$TOKEN" ]]; then
  echo "  FAIL  missing session_id or token in create response" >&2
  exit 1
fi

auth=(-H "Authorization: Bearer ${TOKEN}" -H "Content-Type: application/json")

echo "2. Contact gate — upload-urls before PATCH /contact"
assert_status "upload-urls without contact" "403" "$(api_post "/sessions/${SID}/upload-urls" "${auth[@]}" \
  -d '{"part_id":"11111111-1111-4111-8111-111111111111","file_type":"part","filename":"bracket.step","content_type":"application/octet-stream"}')"

echo "3. PATCH /contact (required before uploads)"
assert_status "patch contact" "200" "$(patch_contact "$SID" "$TOKEN")"

echo "4. Happy path — part upload URL"
part="$(api_post "/sessions/${SID}/upload-urls" "${auth[@]}" \
  -d '{"part_id":"22222222-2222-4222-8222-222222222222","file_type":"part","filename":"bracket.step","content_type":"application/octet-stream"}')"
assert_status "part upload-url" "200" "$part"
PART_BODY="$(body_only "$part")"
PART_KEY="$(json_field "$PART_BODY" file_key)"
UPLOAD_URL="$(json_field "$PART_BODY" upload_url)"

if [[ "$PART_KEY" == intake/${SID}/parts/* && "$PART_KEY" == *_bracket.step ]]; then
  echo "  ok  part file_key scoped under intake/${SID}/parts/"
  pass=$((pass + 1))
else
  echo "  FAIL  unexpected part file_key: ${PART_KEY}" >&2
  fail=$((fail + 1))
fi

echo "5. Happy path — drawing upload URL"
drawing="$(api_post "/sessions/${SID}/upload-urls" "${auth[@]}" \
  -d '{"part_id":"33333333-3333-4333-8333-333333333333","file_type":"drawing","filename":"drawing.pdf","content_type":"application/pdf"}')"
assert_status "drawing upload-url" "200" "$drawing"
DRAWING_KEY="$(json_field "$(body_only "$drawing")" file_key)"
if [[ "$DRAWING_KEY" == *"/drawings/"* ]]; then
  echo "  ok  drawing file_key under drawings/"
  pass=$((pass + 1))
else
  echo "  FAIL  drawing file_key missing /drawings/: ${DRAWING_KEY}" >&2
  fail=$((fail + 1))
fi

echo "6. Filename sanitization"
sanitize="$(api_post "/sessions/${SID}/upload-urls" "${auth[@]}" \
  -d '{"part_id":"44444444-4444-4444-8444-444444444444","file_type":"part","filename":"my bracket (rev 2).step","content_type":"application/octet-stream"}')"
assert_status "sanitized filename" "200" "$sanitize"
SAN_KEY="$(json_field "$(body_only "$sanitize")" file_key)"
if [[ "$SAN_KEY" == *_my_bracket__rev_2_.step ]]; then
  echo "  ok  sanitized basename in key"
  pass=$((pass + 1))
else
  echo "  FAIL  expected *_my_bracket__rev_2_.step, got ${SAN_KEY}" >&2
  fail=$((fail + 1))
fi

echo "7. Validation (expect 400)"
assert_status "invalid part_id" "400" "$(api_post "/sessions/${SID}/upload-urls" "${auth[@]}" \
  -d '{"part_id":"not-a-uuid","file_type":"part","filename":"a.step","content_type":"application/octet-stream"}')"
assert_status "invalid file_type" "400" "$(api_post "/sessions/${SID}/upload-urls" "${auth[@]}" \
  -d '{"part_id":"55555555-5555-4555-8555-555555555555","file_type":"blueprint","filename":"a.pdf","content_type":"application/pdf"}')"
assert_status "empty filename" "400" "$(api_post "/sessions/${SID}/upload-urls" "${auth[@]}" \
  -d '{"part_id":"55555555-5555-4555-8555-555555555555","file_type":"part","filename":"   ","content_type":"application/octet-stream"}')"
assert_status "part wrong content_type" "400" "$(api_post "/sessions/${SID}/upload-urls" "${auth[@]}" \
  -d '{"part_id":"55555555-5555-4555-8555-555555555555","file_type":"part","filename":"a.step","content_type":"text/plain"}')"
assert_status "drawing unsupported content_type" "400" "$(api_post "/sessions/${SID}/upload-urls" "${auth[@]}" \
  -d '{"part_id":"55555555-5555-4555-8555-555555555555","file_type":"drawing","filename":"a.gif","content_type":"image/gif"}')"
assert_status "non-json body" "400" "$(api_post "/sessions/${SID}/upload-urls" -H "Authorization: Bearer ${TOKEN}" -H "Content-Type: text/plain" -d 'not json')"

echo "8. Auth and session state"
assert_status "no bearer token" "401" "$(api_post "/sessions/${SID}/upload-urls" -H "Content-Type: application/json" \
  -d '{"part_id":"55555555-5555-4555-8555-555555555555","file_type":"part","filename":"a.step","content_type":"application/octet-stream"}')"
assert_status "invalid jwt" "401" "$(api_post "/sessions/${SID}/upload-urls" -H "Authorization: Bearer bad-token" -H "Content-Type: application/json" \
  -d '{"part_id":"55555555-5555-4555-8555-555555555555","file_type":"part","filename":"a.step","content_type":"application/octet-stream"}')"

OTHER="$(api_post "/sessions")"
OTHER_SID="$(json_field "$(body_only "$OTHER")" session_id)"
assert_status "jwt for different session" "401" "$(api_post "/sessions/${OTHER_SID}/upload-urls" "${auth[@]}" \
  -d '{"part_id":"55555555-5555-4555-8555-555555555555","file_type":"part","filename":"a.step","content_type":"application/octet-stream"}')"

GHOST="$(api_post "/sessions")"
GHOST_BODY="$(body_only "$GHOST")"
GHOST_SID="$(json_field "$GHOST_BODY" session_id)"
GHOST_TOKEN="$(json_field "$GHOST_BODY" token)"
npx wp-env run cli --env-cwd=wp-content/rfq-plugin-root wp db query \
  "DELETE FROM wp_rfq_sessions WHERE session_id='${GHOST_SID}';" >/dev/null
assert_status "deleted session not found" "404" "$(api_post "/sessions/${GHOST_SID}/upload-urls" \
  -H "Authorization: Bearer ${GHOST_TOKEN}" -H "Content-Type: application/json" \
  -d '{"part_id":"55555555-5555-4555-8555-555555555555","file_type":"part","filename":"a.step","content_type":"application/octet-stream"}')"

echo "9. Submitted session (403 via wp-cli DB update)"
npx wp-env run cli --env-cwd=wp-content/rfq-plugin-root wp db query \
  "UPDATE wp_rfq_sessions SET status='submitted' WHERE session_id='${SID}';" >/dev/null
assert_status "submitted session rejected" "403" "$(api_post "/sessions/${SID}/upload-urls" "${auth[@]}" \
  -d '{"part_id":"55555555-5555-4555-8555-555555555555","file_type":"part","filename":"a.step","content_type":"application/octet-stream"}')"

echo "10. Regression — refresh + draft + submit"
refresh="$(api_post "/sessions/${OTHER_SID}/refresh" -H "Authorization: Bearer $(json_field "$(body_only "$OTHER")" token)")"
assert_status "refresh still works" "200" "$refresh"
assert_status "put draft" "200" "$(curl -sS -X PUT "${REST}/sessions/${OTHER_SID}/draft" \
  -H "Authorization: Bearer $(json_field "$(body_only "$OTHER")" token)" \
  -H "Content-Type: application/json" \
  -d '{"global":{"shipping_destination":{"postal_code":"90210"}}}' \
  -w "\n__HTTP__:%{http_code}")"
assert_status "submit still 501" "501" "$(api_post "/sessions/${OTHER_SID}/submit" \
  -H "Authorization: Bearer $(json_field "$(body_only "$OTHER")" token)")"

if [[ -n "$DO_PUT" ]]; then
  echo "11. End-to-end S3 PUT (${DO_PUT})"
  if [[ ! -f "$DO_PUT" ]]; then
    echo "  FAIL  file not found: ${DO_PUT}" >&2
    fail=$((fail + 1))
  elif [[ -z "$UPLOAD_URL" ]]; then
    echo "  FAIL  no upload_url from step 2" >&2
    fail=$((fail + 1))
  else
    put_code="$(curl -sS -o /dev/null -w "%{http_code}" -X PUT "${UPLOAD_URL}" \
      -H "Content-Type: application/octet-stream" \
      --data-binary "@${DO_PUT}")"
    if [[ "$put_code" == "200" ]]; then
      echo "  ok  S3 PUT returned HTTP ${put_code} (file_key: ${PART_KEY})"
      pass=$((pass + 1))
    else
      echo "  FAIL  S3 PUT returned HTTP ${put_code}" >&2
      fail=$((fail + 1))
    fi
  fi
fi

if [[ "$DO_FULL" == "1" ]]; then
  echo "12. Rate limits (--full)"
  fresh="$(api_post "/sessions")"
  FULL_SID="$(json_field "$(body_only "$fresh")" session_id)"
  FULL_TOKEN="$(json_field "$(body_only "$fresh")" token)"
  assert_status "patch contact for rate-limit session" "200" "$(patch_contact "$FULL_SID" "$FULL_TOKEN")"
  full_auth=(-H "Authorization: Bearer ${FULL_TOKEN}" -H "Content-Type: application/json")

  allowed=0
  for _ in $(seq 1 200); do
    code="$(http_code "$(api_post "/sessions/${FULL_SID}/upload-urls" "${full_auth[@]}" \
      -d '{"part_id":"99999999-9999-4999-8999-999999999999","file_type":"part","filename":"x.step","content_type":"application/octet-stream"}')")"
    if [[ "$code" == "200" ]]; then
      allowed=$((allowed + 1))
    fi
  done

  if [[ "$allowed" == "200" ]]; then
    echo "  ok  200 upload URLs allowed per session"
    pass=$((pass + 1))
  else
    echo "  FAIL  expected 200 allowed upload URLs, got ${allowed}" >&2
    fail=$((fail + 1))
  fi

  overflow="$(api_post "/sessions/${FULL_SID}/upload-urls" "${full_auth[@]}" \
    -d '{"part_id":"aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa","file_type":"part","filename":"overflow.step","content_type":"application/octet-stream"}')"
  assert_status "201st upload-url rejected" "429" "$overflow"
fi

echo
if [[ "$fail" -eq 0 ]]; then
  echo "QA upload-urls passed (${pass} checks)."
  exit 0
fi

echo "QA upload-urls failed (${fail} check(s), ${pass} passed)." >&2
exit 1
