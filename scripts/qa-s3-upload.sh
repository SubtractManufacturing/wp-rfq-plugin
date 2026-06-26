#!/usr/bin/env bash
# E2E S3 upload QA using committed config/PlaceHolder.step against wp-env dev site.
# Requires: wp-env running, S3 configured (npm run dev:restore-s3).
#
# Flow: session → contact → presigned PUT → HeadObject verify → delete security edges → server cleanup.
#
# Usage:
#   npm run qa:s3-upload
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

FIXTURE="${ROOT}/config/PlaceHolder.step"
FIXTURE_NAME="PlaceHolder.step"
PART_ID="aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"

QA_PASS=0
QA_FAIL=0

# shellcheck source=scripts/qa-common.sh
source "$ROOT/scripts/qa-common.sh"

if ! npx wp-env status >/dev/null 2>&1; then
  echo "QA s3-upload failed: wp-env is not running (npm run wp-env start)." >&2
  exit 1
fi

if [[ ! -f "$FIXTURE" ]]; then
  echo "QA s3-upload failed: missing fixture ${FIXTURE}" >&2
  exit 1
fi

echo "QA s3-upload (HTTP via ${BASE_URL}, fixture config/${FIXTURE_NAME})"
echo

echo "0. Restore dev S3 settings + health"
bash "$ROOT/scripts/restore-dev-s3-from-env.sh" >/dev/null

health="$(curl -sS "${REST}/health" -w "\n__HTTP__:%{http_code}")"
qa_assert_status "health ok" "200" "$health"
if [[ "$(qa_json_field "$(qa_body_only "$health")" status)" != "ok" ]]; then
  echo "  FAIL  health body status is not ok — check config/dev.env.local" >&2
  QA_FAIL=$((QA_FAIL + 1))
fi

echo "1. Create session + warm-lead contact"
create="$(qa_api_post "/sessions")"
qa_assert_status "create session" "201" "$create"
CREATE_BODY="$(qa_body_only "$create")"
SID="$(qa_json_field "$CREATE_BODY" session_id)"
TOKEN="$(qa_json_field "$CREATE_BODY" token)"

if [[ -z "$SID" || -z "$TOKEN" ]]; then
  echo "  FAIL  missing session_id or token" >&2
  exit 1
fi

auth=(-H "Authorization: Bearer ${TOKEN}" -H "Content-Type: application/json")

contact="$(qa_api_patch "/sessions/${SID}/contact" "${auth[@]}" \
  -d '{"first_name":"Jane","last_name":"Smith","email":"jane@example.com"}')"
qa_assert_status "patch contact" "200" "$contact"

echo "2. Request presigned upload URL"
upload="$(qa_api_post "/sessions/${SID}/upload-urls" "${auth[@]}" \
  -d "{\"part_id\":\"${PART_ID}\",\"file_type\":\"part\",\"filename\":\"${FIXTURE_NAME}\",\"content_type\":\"application/octet-stream\"}")"
qa_assert_status "upload-urls part file" "200" "$upload"

UPLOAD_BODY="$(qa_body_only "$upload")"
UPLOAD_URL="$(qa_json_field "$UPLOAD_BODY" upload_url)"
FILE_KEY="$(qa_json_field "$UPLOAD_BODY" file_key)"

if [[ -z "$UPLOAD_URL" || -z "$FILE_KEY" ]]; then
  echo "  FAIL  upload-urls response missing upload_url or file_key" >&2
  QA_FAIL=$((QA_FAIL + 1))
elif [[ "$FILE_KEY" =~ ^intake/${SID}/parts/[0-9a-f-]+_${FIXTURE_NAME}$ ]]; then
  echo "  ok  file_key scoped under intake/${SID}/parts/"
  QA_PASS=$((QA_PASS + 1))
else
  echo "  FAIL  unexpected file_key: ${FILE_KEY}" >&2
  QA_FAIL=$((QA_FAIL + 1))
fi

if [[ "$(qa_json_field "$UPLOAD_BODY" delete_url)" != "" ]]; then
  echo "  FAIL  upload-urls must not expose delete_url to clients" >&2
  QA_FAIL=$((QA_FAIL + 1))
else
  echo "  ok  response has no delete_url field"
  QA_PASS=$((QA_PASS + 1))
fi

echo "3. PUT fixture bytes to presigned URL"
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

echo "4. Verify object exists (server HeadObject)"
if qa_s3_object_action head "$FILE_KEY" >/dev/null; then
  echo "  ok  HeadObject confirms upload at ${FILE_KEY}"
  QA_PASS=$((QA_PASS + 1))
else
  echo "  FAIL  HeadObject did not find ${FILE_KEY}" >&2
  QA_FAIL=$((QA_FAIL + 1))
fi

echo "5. Delete security edges (customers must not remove intake objects)"
qa_assert_status "no REST delete route for upload-urls" "404" "$(qa_api_delete "/sessions/${SID}/upload-urls" "${auth[@]}")"
qa_assert_status "no REST delete route for draft" "404" "$(qa_api_delete "/sessions/${SID}/draft" "${auth[@]}")"

presigned_delete_code="$(curl -sS -o /dev/null -w "%{http_code}" -X DELETE "${UPLOAD_URL}")"
if [[ "$presigned_delete_code" =~ ^(403|405)$ ]]; then
  echo "  ok  DELETE on presigned PUT URL rejected (HTTP ${presigned_delete_code})"
  QA_PASS=$((QA_PASS + 1))
else
  echo "  FAIL  DELETE on presigned PUT URL returned HTTP ${presigned_delete_code} (expected 403 or 405)" >&2
  QA_FAIL=$((QA_FAIL + 1))
fi

jwt_delete_code="$(curl -sS -o /dev/null -w "%{http_code}" -X DELETE "${UPLOAD_URL}" \
  -H "Authorization: Bearer ${TOKEN}")"
if [[ "$jwt_delete_code" =~ ^(400|403|405)$ ]]; then
  echo "  ok  DELETE with session JWT on S3 URL rejected (HTTP ${jwt_delete_code})"
  QA_PASS=$((QA_PASS + 1))
else
  echo "  FAIL  DELETE with session JWT returned HTTP ${jwt_delete_code} (expected 400, 403, or 405)" >&2
  QA_FAIL=$((QA_FAIL + 1))
fi

if qa_s3_object_action head "$FILE_KEY" >/dev/null; then
  echo "  ok  object still present after rejected delete attempts"
  QA_PASS=$((QA_PASS + 1))
else
  echo "  FAIL  object missing after delete edge probes" >&2
  QA_FAIL=$((QA_FAIL + 1))
fi

echo "6. Server-side cleanup (QA harness only)"
if qa_s3_object_action delete "$FILE_KEY" >/dev/null; then
  echo "  ok  server deleteObject removed test artifact"
  QA_PASS=$((QA_PASS + 1))
else
  echo "  FAIL  server deleteObject failed for ${FILE_KEY}" >&2
  QA_FAIL=$((QA_FAIL + 1))
fi

if qa_s3_object_action head "$FILE_KEY" >/dev/null; then
  echo "  FAIL  object still exists after server cleanup" >&2
  QA_FAIL=$((QA_FAIL + 1))
else
  echo "  ok  HeadObject confirms cleanup"
  QA_PASS=$((QA_PASS + 1))
fi

echo
if [[ "$QA_FAIL" -eq 0 ]]; then
  echo "QA s3-upload passed (${QA_PASS} checks)."
  exit 0
fi

echo "QA s3-upload failed (${QA_FAIL} check(s), ${QA_PASS} passed)." >&2
exit 1
