/**
 * Re-run the reviewer on an existing Sandcastle branch (e.g. after ENAMETOOLONG fix).
 *
 * Usage:
 *   npm run sandcastle:review -- sandcastle/sequential-reviewer/1782407450630
 */

import * as sandcastle from "@ai-hero/sandcastle";
import { createReviewAgent } from "./agents.js";
import { resolveSandboxMode, sandboxProvider } from "./sandbox.js";
import { closeSandboxClean } from "./cleanup.js";
import { ensureWindowsSh, printCodexWindowsHelp } from "./win32-sh.js";

const branch = process.argv[2];
if (!branch) {
  console.error("Usage: npm run sandcastle:review -- <branch-name>");
  process.exit(1);
}

const sandboxMode = resolveSandboxMode();
if (sandboxMode === "host" && !ensureWindowsSh()) {
  printCodexWindowsHelp();
  process.exit(1);
}

console.log(`Review-only on branch: ${branch} (sandbox=${sandboxMode})\n`);

const agentSandbox = await sandcastle.createSandbox({
  branch,
  sandbox: sandboxProvider(sandboxMode),
});

try {
  await agentSandbox.run({
    name: "reviewer",
    maxIterations: 1,
    agent: createReviewAgent(),
    promptFile: "./.sandcastle/review-prompt.md",
    promptArgs: { BRANCH: branch },
  });
  console.log("\nReview complete.");
} finally {
  await closeSandboxClean(agentSandbox);
}
