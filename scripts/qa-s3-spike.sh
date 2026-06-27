#!/usr/bin/env bash
# M2 S3 validation spike — supplements qa-s3-upload.sh for TESTING.md §5.1 matrix.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PART_ID="aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"
FIXTURE="${ROOT}/config/PlaceHolder.step"

# shellcheck source=scripts/qa-common.sh
source "$ROOT/scripts/qa-common.sh"

if ! npx wp-env status >/dev/null 2>&1; then
  echo "S3 spike failed: wp-env is not running." >&2
  exit 1
fi

bash "$ROOT/scripts/restore-dev-s3-from-env.sh" >/dev/null
qa_clear_session_rate_limits

new_session() {
  local create body sid token contact auth
  create="$(qa_api_post "/sessions")"
  body="$(qa_body_only "$create")"
  sid="$(qa_json_field "$body" session_id)"
  token="$(qa_json_field "$body" token)"
  if [[ -z "$sid" || -z "$token" ]]; then
    echo "Failed to create session" >&2
    exit 1
  fi
  SESSION_ID="$sid"
  SESSION_TOKEN="$token"
  auth=(-H "Authorization: Bearer ${SESSION_TOKEN}" -H "Content-Type: application/json")
  contact="$(qa_api_patch "/sessions/${SESSION_ID}/contact" "${auth[@]}" \
    -d '{"first_name":"Spike","last_name":"Test","email":"spike@example.com"}')"
  if [[ "$(qa_http_code "$contact")" != "200" ]]; then
    echo "Failed to patch contact for session ${SESSION_ID}" >&2
    exit 1
  fi
}

request_upload() {
  local file_type="$1"
  local filename="$2"
  local content_type="$3"
  local auth=(-H "Authorization: Bearer ${SESSION_TOKEN}" -H "Content-Type: application/json")
  qa_api_post "/sessions/${SESSION_ID}/upload-urls" "${auth[@]}" \
    -d "{\"part_id\":\"${PART_ID}\",\"file_type\":\"${file_type}\",\"filename\":\"${filename}\",\"content_type\":\"${content_type}\"}"
}

echo "S3 spike — Supabase-compatible bucket (dev.env.local)"
echo

echo "1. Drawing MIME (application/pdf)"
new_session
upload="$(request_upload drawing test.pdf application/pdf)"
echo "  upload-urls HTTP: $(qa_http_code "$upload")"
UPLOAD_URL="$(qa_json_field "$(qa_body_only "$upload")" upload_url)"
FILE_KEY="$(qa_json_field "$(qa_body_only "$upload")" file_key)"
printf '%%PDF-1.4\n' > /tmp/rfq-spike.pdf
put_code="$(curl -sS -o /dev/null -w "%{http_code}" -X PUT "${UPLOAD_URL}" \
  -H "Content-Type: application/pdf" --data-binary @/tmp/rfq-spike.pdf)"
echo "  PUT pdf HTTP: ${put_code}"
echo "  HeadObject: $(qa_s3_object_action head "$FILE_KEY")"
qa_s3_object_action delete "$FILE_KEY" >/dev/null || true

echo
echo "2. Wrong Content-Type (presign bound to application/octet-stream)"
new_session
upload="$(request_upload part wrong.step application/octet-stream)"
UPLOAD_URL="$(qa_json_field "$(qa_body_only "$upload")" upload_url)"
FILE_KEY="$(qa_json_field "$(qa_body_only "$upload")" file_key)"
wrong_ct="$(curl -sS -o /dev/null -w "%{http_code}" -X PUT "${UPLOAD_URL}" \
  -H "Content-Type: text/plain" --data-binary "test")"
echo "  PUT text/plain HTTP: ${wrong_ct} (403/400 expected if Content-Type enforced)"
qa_s3_object_action delete "$FILE_KEY" >/dev/null 2>/dev/null || true

echo
echo "3. Oversized file (submit-time enforcement; presign does not cap PUT size)"
new_session
upload="$(request_upload part big.step application/octet-stream)"
UPLOAD_URL="$(qa_json_field "$(qa_body_only "$upload")" upload_url)"
FILE_KEY="$(qa_json_field "$(qa_body_only "$upload")" file_key)"
big_put="$(dd if=/dev/zero bs=1048576 count=1 2>/dev/null | curl -sS -o /dev/null -w "%{http_code}" -X PUT "${UPLOAD_URL}" \
  -H "Content-Type: application/octet-stream" --data-binary @-)"
echo "  PUT 1 MiB HTTP: ${big_put} (oversize blocked at submit via HeadObject, not presign)"
qa_s3_object_action delete "$FILE_KEY" >/dev/null || true

echo
echo "4. OPTIONS preflight (Origin: http://localhost:8888)"
new_session
upload="$(request_upload part cors.step application/octet-stream)"
UPLOAD_URL="$(qa_json_field "$(qa_body_only "$upload")" upload_url)"
opts_code="$(curl -sS -o /dev/null -w "%{http_code}" -X OPTIONS "${UPLOAD_URL}" \
  -H "Origin: http://localhost:8888" \
  -H "Access-Control-Request-Method: PUT" \
  -H "Access-Control-Request-Headers: content-type")"
echo "  OPTIONS HTTP: ${opts_code}"
curl -sSI -X OPTIONS "${UPLOAD_URL}" \
  -H "Origin: http://localhost:8888" \
  -H "Access-Control-Request-Method: PUT" \
  -H "Access-Control-Request-Headers: content-type" 2>/dev/null | grep -i '^access-control' || echo "  (no Access-Control-* response headers)"
qa_s3_object_action delete "$(qa_json_field "$(qa_body_only "$upload")" file_key)" >/dev/null 2>/dev/null || true

echo
echo "5. Exact-key binding (tampered presigned URL)"
new_session
upload="$(request_upload part exact.step application/octet-stream)"
UPLOAD_URL="$(qa_json_field "$(qa_body_only "$upload")" upload_url)"
tampered="${UPLOAD_URL}tampered"
ek="$(curl -sS -o /dev/null -w "%{http_code}" -X PUT "${tampered}" \
  -H "Content-Type: application/octet-stream" --data-binary "x")"
echo "  Tampered URL PUT HTTP: ${ek}"

echo
echo "6. Path-style endpoint"
echo "  RFQ_S3_Client uses use_path_style_endpoint=true (required for Supabase S3 gateway)"

echo
echo "7. Browser PUT from form origin (curl with Origin header)"
new_session
upload="$(request_upload part origin.step application/octet-stream)"
UPLOAD_URL="$(qa_json_field "$(qa_body_only "$upload")" upload_url)"
FILE_KEY="$(qa_json_field "$(qa_body_only "$upload")" file_key)"
origin_put="$(curl -sS -o /dev/null -w "%{http_code}" -X PUT "${UPLOAD_URL}" \
  -H "Content-Type: application/octet-stream" \
  -H "Origin: http://localhost:8888" \
  --data-binary @"${FIXTURE}")"
echo "  PUT with Origin http://localhost:8888 HTTP: ${origin_put}"
qa_s3_object_action delete "$FILE_KEY" >/dev/null || true

echo
echo "Spike checks complete. See Planning/S3-SPIKE-REPORT.md for pass/fail summary."
