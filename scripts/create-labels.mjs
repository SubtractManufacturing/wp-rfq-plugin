/**
 * Create GitHub labels for Sandcastle agent workflow.
 * Run: npm run labels:create
 */

import { execSync } from "node:child_process";

const labels = [
  { name: "Sandcastle", color: "1d76db", description: "Pick up by Sandcastle autonomous agent" },
  { name: "M1", color: "0e8a16", description: "Milestone 1 — Phase 0–2, REST foundation" },
  { name: "M2", color: "5319e7", description: "Milestone 2 — S3 integration" },
  { name: "M3", color: "fbca04", description: "Milestone 3 — Submit pipeline" },
  { name: "M4", color: "d93f0b", description: "Milestone 4 — React Steps 1–2" },
  { name: "M5", color: "b60205", description: "Milestone 5 — Full form E2E" },
  { name: "agent-blocked", color: "ededed", description: "Agent could not complete — needs human input" },
];

for (const label of labels) {
  try {
    execSync(
      `gh label create "${label.name}" --color "${label.color}" --description "${label.description}" --force`,
      { stdio: "inherit" },
    );
  } catch {
    console.error(`Failed to create label: ${label.name}`);
    process.exitCode = 1;
  }
}

console.log("Done.");
