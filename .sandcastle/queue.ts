import { execSync } from "node:child_process";

/** Open GitHub issues labeled `Sandcastle` — the implementer's work queue. */
export function countOpenSandcastleIssues(): number {
  const out = execSync(
    "gh issue list --state open --label Sandcastle --limit 100 --json number",
    { encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] },
  );
  const issues = JSON.parse(out) as unknown[];
  return issues.length;
}
