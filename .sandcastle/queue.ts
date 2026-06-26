import { formatGhError, ghJsonSync } from "./gh.js";

/** Open GitHub issues labeled `Sandcastle` — the implementer's work queue. */
export function countOpenSandcastleIssues(): number | null {
  try {
    const issues = ghJsonSync<unknown[]>([
      "issue",
      "list",
      "--state",
      "open",
      "--label",
      "Sandcastle",
      "--limit",
      "100",
      "--json",
      "number",
    ]);
    return issues.length;
  } catch (error) {
    console.error(`Failed to list Sandcastle issues: ${formatGhError(error)}`);
    return null;
  }
}
