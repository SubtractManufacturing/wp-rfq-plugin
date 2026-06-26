// Sequential Reviewer — implement (Cursor Composer 2.5) → review (Codex gpt-5.5) per issue
//
// Usage:
//   npm run sandcastle                    # default: Composer implement + Codex review (host)
//   npm run sandcastle:docker             # same agents, Docker sandbox
//   npm run sandcastle:legacy             # old stack: Codex implement + Cursor review
//   npm run sandcastle:build-image        # first time / after Dockerfile changes (Docker path)
//
// Env:
//   SANDCASTLE_IMPLEMENT=cursor|codex     default cursor (composer-2.5)
//   SANDCASTLE_REVIEW=codex|cursor        default codex (gpt-5.5)
//   SANDCASTLE_SANDBOX=host|docker        default host

import * as sandcastle from "@ai-hero/sandcastle";
import {
  createImplementAgent,
  createReviewAgent,
  resolveImplementKind,
  resolveReviewKind,
} from "./agents.js";
import { resolveSandboxMode, sandboxProvider } from "./sandbox.js";
import { closeSandboxClean } from "./cleanup.js";
import { countOpenSandcastleIssues } from "./queue.js";
import {
  ensureWindowsSh,
  ensureWindowsCursorAgent,
  printCodexWindowsHelp,
  printCursorAgentHelp,
} from "./win32-sh.js";

const MAX_ITERATIONS = 10;
const MAX_CONSECUTIVE_IMPLEMENT_FAILURES = 3;

const implementKind = resolveImplementKind();
const reviewKind = resolveReviewKind();
const sandboxMode = resolveSandboxMode();

if (sandboxMode === "host") {
  const needsCursor = implementKind === "cursor" || reviewKind === "cursor";
  if (!ensureWindowsSh()) {
    printCodexWindowsHelp();
    process.exit(1);
  }
  if (needsCursor && !ensureWindowsCursorAgent()) {
    printCursorAgentHelp();
    process.exit(1);
  }
}
const IMPLEMENT_AGENT = createImplementAgent(implementKind);
const REVIEW_AGENT = createReviewAgent(reviewKind);

console.log(
  `Sandcastle: implement=${implementKind} sandbox=${sandboxMode} review=${reviewKind}\n`,
);

const hooks = {
  sandbox: {
    onSandboxReady: [{ command: "npm install", timeoutMs: 300_000 }],
  },
};

let consecutiveImplementFailures = 0;

function issueCountOrExit(): number {
  const count = countOpenSandcastleIssues();
  if (count === null) {
    console.error(
      "Cannot verify Sandcastle issue queue (gh unavailable). Exiting.",
    );
    process.exit(1);
  }
  return count;
}

for (let iteration = 1; iteration <= MAX_ITERATIONS; iteration++) {
  console.log(`\n=== Iteration ${iteration}/${MAX_ITERATIONS} ===\n`);

  if (issueCountOrExit() === 0) {
    console.log("No open Sandcastle-labeled issues. Nothing to do.");
    break;
  }

  const branch = `sandcastle/sequential-reviewer/${Date.now()}`;

  const agentSandbox = await sandcastle.createSandbox({
    branch,
    sandbox: sandboxProvider(sandboxMode),
    hooks,
  });

  try {
    const implement = await agentSandbox.run({
      name: "implementer",
      maxIterations: 1,
      agent: IMPLEMENT_AGENT,
      promptFile: "./.sandcastle/implement-prompt.md",
    });

    if (!implement.commits.length) {
      if (implement.completionSignal && issueCountOrExit() === 0) {
        console.log(
          "Implementer finished with no new commits and the queue is empty.",
        );
        break;
      }

      consecutiveImplementFailures++;
      console.log(
        `Implementation agent made no commits (${consecutiveImplementFailures}/${MAX_CONSECUTIVE_IMPLEMENT_FAILURES}).`,
      );
      console.log(
        `Check .sandcastle/logs/*-implementer.log — if the agent asked what to do, it misread the prompt.`,
      );
      if (consecutiveImplementFailures >= MAX_CONSECUTIVE_IMPLEMENT_FAILURES) {
        console.log("Stopping after repeated implement failures.");
        break;
      }
      console.log("Retrying next iteration…");
      continue;
    }

    consecutiveImplementFailures = 0;

    console.log(`\nImplementation complete on branch: ${branch}`);
    console.log(`Commits: ${implement.commits.length}`);

    await agentSandbox.run({
      name: "reviewer",
      maxIterations: 1,
      agent: REVIEW_AGENT,
      promptFile: "./.sandcastle/review-prompt.md",
      promptArgs: {
        BRANCH: branch,
      },
    });

    console.log("\nReview complete.");
  } finally {
    await closeSandboxClean(agentSandbox);
  }
}

console.log("\nAll done.");
