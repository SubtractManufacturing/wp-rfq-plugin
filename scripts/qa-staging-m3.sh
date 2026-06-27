#!/usr/bin/env bash
# M3 staging supplement (backend) — verifiable items from Planning/TESTING.md §11 without React UI.
# Requires: wp-env running, config/dev.env.local, config/PlaceHolder.step
#
# Usage:
#   npm run qa:staging-m3
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

FIXTURE="${ROOT}/config/PlaceHolder.step"
FIXTURE_NAME="PlaceHolder.step"
PART_ID="bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb"

QA_PASS=0
QA_FAIL=0

# shellcheck source=scripts/qa-common.sh
source "$ROOT/scripts/qa-common.sh"

if [[ ! -f "$ROOT/config/dev.env.local" ]]; then
  echo "QA staging-m3 failed: missing config/dev.env.local" >&2
  exit 1
fi

if ! npx wp-env status >/dev/null 2>&1; then
  echo "QA staging-m3 failed: wp-env is not running (npm run wp-env start)." >&2
  exit 1
fi

if [[ ! -f "$FIXTURE" ]]; then
  echo "QA staging-m3 failed: missing fixture ${FIXTURE}" >&2
  exit 1
fi

echo "QA staging-m3 (HTTP via ${BASE_URL}, TESTING.md §11 backend checks)"
echo

echo "0. Restore dev S3 settings + health"
bash "$ROOT/scripts/restore-dev-s3-from-env.sh" >/dev/null
npx wp-env run cli --env-cwd=wp-content/rfq-plugin-root wp eval 'RFQ_Activator::activate();' >/dev/null
qa_clear_session_rate_limits

health="$(curl -sS "${REST}/health" -w "\n__HTTP__:%{http_code}")"
qa_assert_status "health ok with configured S3" "200" "$health"
if [[ "$(qa_json_field "$(qa_body_only "$health")" status)" != "ok" ]]; then
  echo "  FAIL  health body status is not ok" >&2
  QA_FAIL=$((QA_FAIL + 1))
fi

echo "1. Session + contact + part upload (PlaceHolder.step)"
create="$(qa_api_post "/sessions")"
qa_assert_status "create session" "201" "$create"
CREATE_BODY="$(qa_body_only "$create")"
SID="$(qa_json_field "$CREATE_BODY" session_id)"
TOKEN="$(qa_json_field "$CREATE_BODY" token)"
auth=(-H "Authorization: Bearer ${TOKEN}" -H "Content-Type: application/json")

contact="$(qa_api_patch "/sessions/${SID}/contact" "${auth[@]}" \
  -d '{"first_name":"Jane","last_name":"Smith","email":"jane@example.com","company":"Acme Corp"}')"
qa_assert_status "patch contact" "200" "$contact"

upload="$(qa_api_post "/sessions/${SID}/upload-urls" "${auth[@]}" \
  -d "{\"part_id\":\"${PART_ID}\",\"file_type\":\"part\",\"filename\":\"${FIXTURE_NAME}\",\"content_type\":\"application/octet-stream\"}")"
qa_assert_status "upload-urls part file" "200" "$upload"

UPLOAD_BODY="$(qa_body_only "$upload")"
UPLOAD_URL="$(qa_json_field "$UPLOAD_BODY" upload_url)"
FILE_KEY="$(qa_json_field "$UPLOAD_BODY" file_key)"

put_code="$(curl -sS -o /dev/null -w "%{http_code}" -X PUT "${UPLOAD_URL}" \
  -H "Content-Type: application/octet-stream" \
  --data-binary "@${FIXTURE}")"

if [[ "$put_code" == "200" ]]; then
  echo "  ok  presigned PUT returned HTTP ${put_code}"
  QA_PASS=$((QA_PASS + 1))
else
  echo "  FAIL  presigned PUT returned HTTP ${put_code}" >&2
  QA_FAIL=$((QA_FAIL + 1))
fi

if [[ "$(qa_s3_object_action head "$FILE_KEY")" == "exists" ]]; then
  echo "  ok  part object exists in S3 (${FILE_KEY})"
  QA_PASS=$((QA_PASS + 1))
else
  echo "  FAIL  part object missing in S3" >&2
  QA_FAIL=$((QA_FAIL + 1))
fi

echo "2. Submit manifest + receipt durability"
TODAY="$(date -u +%Y-%m-%d)"
manifest="$(cat <<EOF
{
  "session_id": "${SID}",
  "contact": {
    "first_name": "Jane",
    "last_name": "Smith",
    "email": "jane@example.com",
    "company": "Acme Corp",
    "phone": null,
    "phone_country_code": null,
    "job_title": null
  },
  "parts": [
    {
      "part_id": "${PART_ID}",
      "part_file_key": "${FILE_KEY}",
      "drawing_file_keys": [],
      "material": "Aluminum 6061",
      "tolerance": "standard",
      "tolerance_detail": null,
      "quantity": 10,
      "target_unit_price": 12.5,
      "notes": null
    }
  ],
  "global": {
    "required_delivery_date": "${TODAY}",
    "lead_time_preference": "standard",
    "shipping_destination": { "postal_code": "90210" },
    "po_number": null,
    "nda_required": false,
    "notes": null
  }
}
EOF
)"

submit="$(curl -sS -X POST "${REST}/sessions/${SID}/submit" "${auth[@]}" \
  -d "${manifest}" -w "\n__HTTP__:%{http_code}")"
qa_assert_status "submit returns receipt" "200" "$submit"

RECEIPT_NUMBER="$(qa_json_field "$(qa_body_only "$submit")" receipt_number)"
if [[ "$RECEIPT_NUMBER" =~ ^RFQ-[0-9]{8}-[0-9]{6}$ ]]; then
  echo "  ok  receipt_number format valid (${RECEIPT_NUMBER})"
  QA_PASS=$((QA_PASS + 1))
else
  echo "  FAIL  unexpected receipt_number: ${RECEIPT_NUMBER}" >&2
  QA_FAIL=$((QA_FAIL + 1))
fi

MANIFEST_KEY="intake/${SID}/meta/manifest.json"
RECEIPT_KEY="intake/${SID}/meta/receipt.json"

if [[ "$(qa_s3_object_action head "$MANIFEST_KEY")" == "exists" ]]; then
  echo "  ok  manifest.json written to S3"
  QA_PASS=$((QA_PASS + 1))
else
  echo "  FAIL  manifest.json missing in S3" >&2
  QA_FAIL=$((QA_FAIL + 1))
fi

if [[ "$(qa_s3_object_action head "$RECEIPT_KEY")" == "exists" ]]; then
  echo "  ok  receipt.json written to S3"
  QA_PASS=$((QA_PASS + 1))
else
  echo "  FAIL  receipt.json missing in S3" >&2
  QA_FAIL=$((QA_FAIL + 1))
fi

echo "3. Submit retry returns same receipt (AC-WP-001 spot-check)"
retry="$(curl -sS -X POST "${REST}/sessions/${SID}/submit" "${auth[@]}" \
  -d "${manifest}" -w "\n__HTTP__:%{http_code}")"
qa_assert_status "submit retry" "200" "$retry"

RETRY_RECEIPT="$(qa_json_field "$(qa_body_only "$retry")" receipt_number)"
if [[ "$RETRY_RECEIPT" == "$RECEIPT_NUMBER" ]]; then
  echo "  ok  retry returned same receipt_number"
  QA_PASS=$((QA_PASS + 1))
else
  echo "  FAIL  retry receipt mismatch (${RETRY_RECEIPT} vs ${RECEIPT_NUMBER})" >&2
  QA_FAIL=$((QA_FAIL + 1))
fi

echo "4. Health unavailable when S3 misconfigured (Airtable fallback precondition)"
npx wp-env run cli --env-cwd=wp-content/rfq-plugin-root wp option delete rfq_s3_secret_key >/dev/null 2>&1 || true
npx wp-env run cli --env-cwd=wp-content/rfq-plugin-root wp option delete rfq_s3_bucket >/dev/null 2>&1 || true

broken="$(curl -sS "${REST}/health" -w "\n__HTTP__:%{http_code}")"
qa_assert_status "health unavailable without S3 config" "503" "$broken"
if [[ "$(qa_json_field "$(qa_body_only "$broken")" status)" == "unavailable" ]]; then
  echo "  ok  health body status unavailable"
  QA_PASS=$((QA_PASS + 1))
else
  echo "  FAIL  health body status not unavailable" >&2
  QA_FAIL=$((QA_FAIL + 1))
fi

echo "5. Restore S3 settings after health probe"
bash "$ROOT/scripts/restore-dev-s3-from-env.sh" >/dev/null
restored="$(curl -sS "${REST}/health" -w "\n__HTTP__:%{http_code}")"
qa_assert_status "health ok after restore" "200" "$restored"

echo "6. Server-side cleanup (test artifacts only)"
qa_s3_object_action delete "$FILE_KEY" >/dev/null || true
qa_s3_object_action delete "$MANIFEST_KEY" >/dev/null || true
qa_s3_object_action delete "$RECEIPT_KEY" >/dev/null || true
npx wp-env run cli --env-cwd=wp-content/rfq-plugin-root wp db query \
  "DELETE FROM wp_rfq_sessions WHERE session_id='${SID}';" >/dev/null 2>&1 || true

echo
if [[ "$QA_FAIL" -eq 0 ]]; then
  echo "QA staging-m3 passed (${QA_PASS} checks)."
  exit 0
fi

echo "QA staging-m3 failed (${QA_FAIL} check(s), ${QA_PASS} passed)." >&2
exit 1
