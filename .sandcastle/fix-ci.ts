/**
 * One-shot CI fixer — run after a Sandcastle PR fails checks.
 *
 * Usage:
 *   npm run sandcastle:fix-ci -- 42        # fix PR #42
 *   npm run sandcastle:fix-ci                # fix current branch's PR
 */

import * as sandcastle from "@ai-hero/sandcastle";
import {
  createImplementAgent,
  resolveImplementKind,
} from "./agents.js";
import { resolveSandboxMode, sandboxProvider } from "./sandbox.js";
import {
  ensureWindowsSh,
  ensureWindowsCursorAgent,
  printCodexWindowsHelp,
  printCursorAgentHelp,
} from "./win32-sh.js";

const prArg = process.argv[2] ?? "current";
const implementKind = resolveImplementKind();
const sandboxMode = resolveSandboxMode();

if (sandboxMode === "host") {
  if (!ensureWindowsSh()) {
    printCodexWindowsHelp();
    process.exit(1);
  }
  if (implementKind === "cursor" && !ensureWindowsCursorAgent()) {
    printCursorAgentHelp();
    process.exit(1);
  }
}

const promptArgs: Record<string, string> = {
  PR_NUMBER: prArg === "current" ? "" : prArg,
};

await sandcastle.run({
  name: "ci-fixer",
  maxIterations: 1,
  agent: createImplementAgent(implementKind),
  sandbox: sandboxProvider(sandboxMode),
  branchStrategy: { type: "head" },
  promptFile: "./.sandcastle/fix-ci-prompt.md",
  promptArgs,
  hooks: {
    sandbox: { onSandboxReady: [{ command: "npm install", timeoutMs: 300_000 }] },
  },
});

console.log("CI fix run complete.");
