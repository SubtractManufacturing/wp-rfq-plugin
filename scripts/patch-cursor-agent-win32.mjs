/**
 * Patches Cursor CLI launcher scripts on Windows so version folders like
 * `2026.06.16-20-30-07-a07d3ac` are recognized (upstream regex only matched
 * `YYYY.MM.DD-commit`). Re-run after Cursor CLI updates if `agent` breaks again.
 */
import {
  readFileSync,
  writeFileSync,
  existsSync,
  copyFileSync,
  readdirSync,
} from "node:fs";
import { join } from "node:path";
import { homedir, platform } from "node:os";

if (platform() !== "win32") {
  process.exit(0);
}

const agentDir = join(
  process.env.LOCALAPPDATA ?? join(homedir(), "AppData", "Local"),
  "cursor-agent",
);

const OLD_PATTERN =
  /'\^\\d\{4\}\\\.\\d\{1,2\}\\\.\\d\{1,2\}-\[a-f0-9\]\+\$'/;
const FIXED_PATTERN = "'^\\d{4}\\.\\d{1,2}\\.\\d{1,2}-.+$'";

function launcherSourceFromVersions() {
  const versionsDir = join(agentDir, "versions");
  if (!existsSync(versionsDir)) return null;

  for (const entry of readdirSync(versionsDir, { withFileTypes: true })) {
    if (!entry.isDirectory()) continue;
    const source = join(versionsDir, entry.name, "cursor-agent.ps1");
    if (existsSync(source)) return source;
  }
  return null;
}

/** Undo a prior bad patch that duplicated the launcher tail. */
function restoreCorruptedLaunchers(source) {
  for (const file of ["agent.ps1", "cursor-agent.ps1"]) {
    const target = join(agentDir, file);
    if (!existsSync(target)) continue;
    const content = readFileSync(target, "utf8");
    const sortCount = content.match(/Sort-Object \{ Parse-VersionString/g)?.length ?? 0;
    if (sortCount > 1) {
      copyFileSync(source, target);
      console.warn(`patch-cursor-agent-win32: restored corrupted ${file}`);
    }
  }
}

function patchLauncher(path) {
  if (!existsSync(path)) return false;

  let content = readFileSync(path, "utf8");
  if (content.includes(FIXED_PATTERN)) return false;
  if (!OLD_PATTERN.test(content)) return false;

  content = content.replace(OLD_PATTERN, () => FIXED_PATTERN);
  writeFileSync(path, content);
  return true;
}

const source = launcherSourceFromVersions();
if (source) restoreCorruptedLaunchers(source);

let patched = 0;
for (const file of ["agent.ps1", "cursor-agent.ps1"]) {
  if (patchLauncher(join(agentDir, file))) patched++;
}

if (patched > 0) {
  console.log(`patch-cursor-agent-win32: patched ${patched} Cursor CLI launcher(s)`);
}
