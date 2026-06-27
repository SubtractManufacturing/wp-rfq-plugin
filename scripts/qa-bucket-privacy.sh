#!/usr/bin/env bash
# Ops QA: verify an S3 object URL is not publicly readable (expect 401/403).
#
# Usage:
#   bash scripts/qa-bucket-privacy.sh "https://bucket.example/intake/session/parts/file.step"
#   npm run qa:bucket-privacy -- "https://..."
set -euo pipefail

OBJECT_URL="${1:-${RFQ_BUCKET_OBJECT_URL:-}}"

if [[ -z "$OBJECT_URL" ]]; then
  echo "qa-bucket-privacy failed: pass object URL as arg or set RFQ_BUCKET_OBJECT_URL." >&2
  exit 2
fi

code="$(curl -sS -o /dev/null -w "%{http_code}" "$OBJECT_URL")"

if [[ "$code" == "403" || "$code" == "401" ]]; then
  echo "qa-bucket-privacy passed: anonymous GET returned HTTP ${code}."
  exit 0
fi

echo "qa-bucket-privacy failed: anonymous GET returned HTTP ${code} (expected 401 or 403)." >&2
exit 1
