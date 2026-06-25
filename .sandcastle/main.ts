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
import {
  ensureWindowsSh,
  ensureWindowsCursorAgent,
  printCodexWindowsHelp,
  printCursorAgentHelp,
} from "./win32-sh.js";

const MAX_ITERATIONS = 10;

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

for (let iteration = 1; iteration <= MAX_ITERATIONS; iteration++) {
  console.log(`\n=== Iteration ${iteration}/${MAX_ITERATIONS} ===\n`);

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
      console.log("Implementation agent made no commits. Stopping.");
      break;
    }

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
    await agentSandbox.close();
  }
}

console.log("\nAll done.");
