#!/usr/bin/env bash
# Manual QA for the [rfq_form] shortcode embed and scoped asset loading.
# Requires: wp-env running.
set -euo pipefail

BASE_URL="${WP_BASE_URL:-http://localhost:8888}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
RUN_ID="$(date +%s)"
RFQ_PAGE_ID=""
PLAIN_PAGE_ID=""

cleanup() {
  if [[ -n "$RFQ_PAGE_ID" ]]; then
    npx wp-env run cli --env-cwd=wp-content/rfq-plugin-root wp post delete "$RFQ_PAGE_ID" --force >/dev/null 2>&1 || true
  fi

  if [[ -n "$PLAIN_PAGE_ID" ]]; then
    npx wp-env run cli --env-cwd=wp-content/rfq-plugin-root wp post delete "$PLAIN_PAGE_ID" --force >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

require_wp_env() {
  if ! npx wp-env status >/dev/null 2>&1; then
    echo "FAIL  wp-env is not running. Start it with: npm run wp-env start" >&2
    exit 1
  fi
}

fetch_page() {
  local page_id="$1"
  curl -fsS "${BASE_URL}/?page_id=${page_id}"
}

assert_contains() {
  local label="$1"
  local haystack="$2"
  local needle="$3"

  if grep -Fq "$needle" <<<"$haystack"; then
    echo "  ok  ${label}"
  else
    echo "  FAIL  ${label} — missing ${needle}" >&2
    exit 1
  fi
}

assert_not_contains() {
  local label="$1"
  local haystack="$2"
  local needle="$3"

  if grep -Fq "$needle" <<<"$haystack"; then
    echo "  FAIL  ${label} — unexpected ${needle}" >&2
    exit 1
  else
    echo "  ok  ${label}"
  fi
}

echo "QA shortcode embed (HTTP via ${BASE_URL})"
echo

require_wp_env

echo "1. Create shortcode and plain test pages"
RFQ_PAGE_ID="$(
  npx wp-env run cli --env-cwd=wp-content/rfq-plugin-root wp post create \
    --post_type=page \
    --post_title="RFQ Shortcode QA ${RUN_ID}" \
    --post_name="rfq-shortcode-qa-${RUN_ID}" \
    --post_content='[rfq_form]' \
    --post_status=publish \
    --porcelain | awk '/^[0-9]+$/ { id = $1 } END { print id }'
)"
PLAIN_PAGE_ID="$(
  npx wp-env run cli --env-cwd=wp-content/rfq-plugin-root wp post create \
    --post_type=page \
    --post_title="RFQ Plain QA ${RUN_ID}" \
    --post_name="rfq-plain-qa-${RUN_ID}" \
    --post_content='Plain page without shortcode.' \
    --post_status=publish \
    --porcelain | awk '/^[0-9]+$/ { id = $1 } END { print id }'
)"

if [[ -z "$RFQ_PAGE_ID" || -z "$PLAIN_PAGE_ID" ]]; then
  echo "FAIL  could not create QA pages with wp-env" >&2
  exit 1
fi

echo "  ok  shortcode page ${RFQ_PAGE_ID}"
echo "  ok  plain page ${PLAIN_PAGE_ID}"

echo
echo "2. Shortcode renders mount point"
rfq_html="$(fetch_page "$RFQ_PAGE_ID")"
assert_contains "mount point present" "$rfq_html" 'id="rfq-form-root"'

echo
echo "3. Build frontend bundle"
(cd "$ROOT/frontend" && npm ci && npm run build)
test -f "$ROOT/rfq-intake/build/rfq-form.js"
test -f "$ROOT/rfq-intake/build/rfq-form.css"
echo "  ok  rfq-form.js and rfq-form.css exist"

echo
echo "4. Built assets load only on shortcode page"
rfq_html="$(fetch_page "$RFQ_PAGE_ID")"
plain_html="$(fetch_page "$PLAIN_PAGE_ID")"

assert_contains "shortcode page loads rfq-form.js" "$rfq_html" 'rfq-form.js'
assert_contains "shortcode page loads rfq-form.css" "$rfq_html" 'rfq-form.css'
assert_not_contains "plain page skips rfq-form.js" "$plain_html" 'rfq-form.js'
assert_not_contains "plain page skips rfq-form.css" "$plain_html" 'rfq-form.css'

echo
echo "Shortcode QA passed."
