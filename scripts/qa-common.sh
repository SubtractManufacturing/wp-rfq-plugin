#!/usr/bin/env bash
# Shared helpers for HTTP QA scripts against wp-env (localhost:8888).

qa_clear_session_rate_limits() {
  npx wp-env run cli --env-cwd=wp-content/rfq-plugin-root wp db query \
    "DELETE FROM wp_options WHERE option_name LIKE '_transient_rfq_sessions_%' OR option_name LIKE '_transient_timeout_rfq_sessions_%';" \
    >/dev/null 2>&1 || true
}
