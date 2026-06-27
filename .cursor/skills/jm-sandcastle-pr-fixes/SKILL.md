---
name: jm-sandcastle-pr-fixes
description: >-
  Merge main into a PR branch, run full QA until green, run Bugbot and fix valid
  findings, re-run QA, commit and push. Use when the user invokes
  JM-Sandcastle-PR-Fixes or asks to merge-ready a wp-rfq-plugin PR locally.
disable-model-invocation: true
---

# JM-Sandcastle-PR-Fixes

User supplies a PR number, URL, or branch. Base branch is `main` unless they say otherwise.

## Workflow

1. `gh pr checkout <n>` (or checkout the named branch).
2. `git fetch origin main && git merge origin/main` — resolve conflicts; stop if intent is ambiguous.
3. Run the full QA suite from [qa-reference.md](qa-reference.md); fix failures until everything is green. Do not weaken or skip tests.
4. Run the **review-bugbot** skill; triage and fix valid findings only.
5. Re-run the full QA suite; fix anything new until green again.
6. Commit (`sandcastle: review: …` per `AGENTS.md`), push to origin. Never commit secrets.

## Report

Reply with: merge/conflict status, QA commands run and pass/fail, Bugbot fixes vs dismissed, commit SHA.

Also list **QA the agent could not run** that you should do — skipped CLI commands (and why), manual UI checks, staging-only steps, or anything from `Planning/TESTING.md` §11 and [qa-reference.md](qa-reference.md) that automation did not cover for this PR.
