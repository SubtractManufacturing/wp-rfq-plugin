#!/usr/bin/env bash
# Restore wp-env dev site (localhost:8888) S3 admin settings from a local env file.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if ! npx wp-env status >/dev/null 2>&1; then
  echo "Dev S3 restore skipped (wp-env is not running)."
  exit 0
fi

npx wp-env run cli --env-cwd=wp-content/rfq-plugin-root wp eval-file scripts/restore-dev-s3-from-env.php
