#!/usr/bin/env node
// Cross-platform host wrapper for scripts/restore-dev-s3-from-env.php.
// Used by `npm run dev:restore-s3` and the wp-env `afterStart` lifecycle hook
// in .wp-env.json so dev S3 settings are restored on every `wp-env start`
// without depending on a host bash (Windows resolves `bash` to WSL, and CRLF
// checkouts break `set -euo pipefail`). Mirrors restore-dev-s3-from-env.sh.
import { spawnSync } from "node:child_process";
import { existsSync, rmSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const wpEnvBin = join(root, "node_modules", "@wordpress", "env", "bin", "wp-env");
const ciEnvFile = join(root, "config", ".ci-s3-env.tmp");

function runWpEnv(args, stdio = "inherit") {
  return spawnSync(process.execPath, [wpEnvBin, ...args], { cwd: root, stdio });
}

// CI: GitHub Actions supplies RFQ_S3_* as process env instead of an env file.
// The PHP script reads config/.ci-s3-env.tmp inside the container, so mirror
// the vars there for the duration of the run (same as the .sh version).
const ciKeys = ["RFQ_S3_ENDPOINT", "RFQ_S3_BUCKET", "RFQ_S3_ACCESS_KEY_ID", "RFQ_S3_REGION", "RFQ_S3_SECRET_KEY"];
const wroteCiFile = ciKeys.every((key) => (process.env[key] ?? "").trim() !== "");
if (wroteCiFile) {
  writeFileSync(ciEnvFile, ciKeys.map((key) => `${key}=${process.env[key]}`).join("\n") + "\n", { mode: 0o600 });
} else if (existsSync(ciEnvFile)) {
  rmSync(ciEnvFile);
}

try {
  const status = runWpEnv(["status"], "ignore");
  if (status.status !== 0) {
    console.log("Dev S3 restore skipped (wp-env is not running).");
    process.exit(0);
  }

  const result = runWpEnv(["run", "cli", "--env-cwd=wp-content/rfq-plugin-root", "wp", "eval-file", "scripts/restore-dev-s3-from-env.php"]);
  process.exit(result.status ?? 1);
} finally {
  if (wroteCiFile) {
    rmSync(ciEnvFile, { force: true });
  }
}
