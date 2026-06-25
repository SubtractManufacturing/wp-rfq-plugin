/**
 * Patches @ai-hero/sandcastle Docker provider to pass security-opt flags Codex
 * needs inside containers (user namespaces / app-server). Re-run after npm install.
 */
import { readFileSync, writeFileSync, existsSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const target = join(
  dirname(fileURLToPath(import.meta.url)),
  "..",
  "node_modules",
  "@ai-hero",
  "sandcastle",
  "dist",
  "chunk-NSFQW6ML.js",
);

const marker = '"--security-opt", "seccomp=unconfined"';
const replacement = `    ...cpusFlags,
    "--security-opt", "seccomp=unconfined",
    "--security-opt", "apparmor=unconfined",
    imageName`;

if (!existsSync(target)) {
  console.warn("patch-sandcastle-docker-security: sandcastle not installed, skipping");
  process.exit(0);
}

let content = readFileSync(target, "utf8");
if (content.includes(marker)) {
  process.exit(0);
}

const needle = `    ...cpusFlags,
    imageName`;

if (!content.includes(needle)) {
  console.error(
    "patch-sandcastle-docker-security: patch target missing — update script for your @ai-hero/sandcastle version",
  );
  process.exit(1);
}

writeFileSync(target, content.replace(needle, replacement));
console.log("patch-sandcastle-docker-security: applied Docker security-opt patch for Codex");
