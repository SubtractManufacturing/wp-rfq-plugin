#!/usr/bin/env bash
# Manual QA for PATCH /contact — runs against wp-env dev site (localhost:8888).
set -euo pipefail

# shellcheck source=qa-common.sh
source "$(dirname "$0")/qa-common.sh"

BASE_URL="${WP_BASE_URL:-http://localhost:8888}"
REST="${BASE_URL}/?rest_route=/rfq/v1"

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
  curl -sS -X POST "${REST}/sessions" "$@" -w "\n__HTTP__:%{http_code}"
}

api_patch() {
  local sid="$1"
  shift
  curl -sS -X PATCH "${REST}/sessions/${sid}/contact" "$@" -w "\n__HTTP__:%{http_code}"
}

echo "QA PATCH /contact (HTTP via ${BASE_URL})"
echo

qa_clear_session_rate_limits

echo "1. Create session"
create="$(api_post)"
assert_status "create session" "201" "$create"
CREATE_BODY="$(body_only "$create")"
SID="$(json_field "$CREATE_BODY" session_id)"
TOKEN="$(json_field "$CREATE_BODY" token)"

if [[ -z "$SID" || -z "$TOKEN" ]]; then
  echo "  FAIL  missing session_id or token" >&2
  exit 1
fi

auth=(-H "Authorization: Bearer ${TOKEN}" -H "Content-Type: application/json")

echo "2. Happy path — full contact payload"
contact="$(api_patch "$SID" "${auth[@]}" \
  -d '{"first_name":" Jane ","last_name":"Smith","email":"jane@example.com","company":"Acme Corp","phone":"5555550100","job_title":null}')"
assert_status "patch contact" "200" "$contact"
CONTACT_BODY="$(body_only "$contact")"
if [[ "$(json_field "$CONTACT_BODY" first_name)" == "Jane" \
  && "$(json_field "$CONTACT_BODY" email)" == "jane@example.com" \
  && "$(json_field "$CONTACT_BODY" phone_country_code)" == "1" ]]; then
  echo "  ok  response normalized (trim, default country code)"
  pass=$((pass + 1))
else
  echo "  FAIL  unexpected contact response body" >&2
  body_only "$contact" >&2
  fail=$((fail + 1))
fi

echo "3. Blank optionals normalize to null"
blank="$(api_patch "$SID" "${auth[@]}" \
  -d '{"first_name":"Jane","last_name":"Smith","email":"jane@example.com","company":"   ","phone":"","job_title":""}')"
assert_status "blank optionals" "200" "$blank"
BLANK_BODY="$(body_only "$blank")"
if [[ "$(json_field "$BLANK_BODY" company)" == "" \
  && "$(json_field "$BLANK_BODY" phone)" == "" \
  && "$(json_field "$BLANK_BODY" phone_country_code)" == "" ]]; then
  echo "  ok  blank optionals returned as null/empty in JSON"
  pass=$((pass + 1))
else
  echo "  FAIL  blank optionals not normalized" >&2
  fail=$((fail + 1))
fi

echo "4. Validation (expect 400)"
assert_status "missing required fields" "400" "$(api_patch "$SID" "${auth[@]}" \
  -d '{"first_name":"","last_name":"Smith","email":"invalid"}')"
assert_status "invalid phone country code" "400" "$(api_patch "$SID" "${auth[@]}" \
  -d '{"first_name":"Jane","last_name":"Smith","email":"jane@example.com","phone":"5555550100","phone_country_code":"44"}')"
assert_status "non-json body" "400" "$(api_patch "$SID" -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" -d 'not-json')"

echo "5. Auth and session state"
assert_status "invalid jwt" "401" "$(api_patch "$SID" -H "Authorization: Bearer bad-token" \
  -H "Content-Type: application/json" \
  -d '{"first_name":"Jane","last_name":"Smith","email":"jane@example.com"}')"

OTHER="$(api_post)"
OTHER_SID="$(json_field "$(body_only "$OTHER")" session_id)"
assert_status "jwt for different session" "401" "$(api_patch "$OTHER_SID" "${auth[@]}" \
  -d '{"first_name":"Jane","last_name":"Smith","email":"jane@example.com"}')"

echo "6. Submitted session rejected"
npx wp-env run cli --env-cwd=wp-content/rfq-plugin-root wp db query \
  "UPDATE wp_rfq_sessions SET status='submitted' WHERE session_id='${SID}';" >/dev/null
assert_status "submitted session" "403" "$(api_patch "$SID" "${auth[@]}" \
  -d '{"first_name":"Jane","last_name":"Smith","email":"jane@example.com"}')"

echo
if [[ "$fail" -eq 0 ]]; then
  echo "QA contact passed (${pass} checks)."
  exit 0
fi

echo "QA contact failed (${fail} check(s), ${pass} passed)." >&2
exit 1
