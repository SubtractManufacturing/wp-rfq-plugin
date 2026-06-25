# Context

## Open issues

!`gh issue list --state open --label Sandcastle --limit 100 --json number,title,body,labels,comments --jq '[.[] | {number, title, body, labels: [.labels[].name], comments: [.comments[].body]}]'`

The list above is the sole source of truth for available work. Do not query for unlabeled issues. If the list is empty, output the completion signal.

**The issue body is the scope contract for this run.** Implement only what the chosen issue describes. Do not pull in later phases from planning docs because they exist in the repo.

## Reference documentation (read selectively)

Read **only** the files and sections listed in the issue's **Read first** block, plus any IMPLEMENTATION.md phase/step the issue **Scope** names. Do not read the full PRD, ADD, or IMPLEMENTATION end-to-end unless the issue explicitly requires it.

| Doc | Use when |
|-----|----------|
| `Planning/IMPLEMENTATION.md` | Exact steps for the issue's cited phase/step only |
| `Planning/TESTING.md` | Issue lists `AC-WP-*` IDs or asks for tests |
| `Planning/PRD.md` | Issue says requirements are unclear |
| `Planning/ADD.md` | Issue touches architecture / "why" |
| `CONTEXT.md` | Domain terms needed for the issue |
| `AGENTS.md` | Commit/PR conventions |
| `.agents/planning.md` | Only if editing planning docs (rare) |

## Recent Sandcastle commits (last 10)

!`git log --oneline --grep="sandcastle:" -10`

# Task

You are an autonomous coding agent implementing RFQ Intake (V1) for the WordPress plugin repo.

## Priority order

1. **Issue scope** — the open issue's Scope + Acceptance criteria + milestone label define this run. Do not expand scope.
2. **Milestone order** — among open issues, prefer the lowest milestone (M1 before M2). Skip issues whose milestone is ahead of repo reality.
3. **One step per issue** — if an issue names multiple IMPLEMENTATION steps, implement only the first incomplete step and leave the rest for a follow-up issue.

Pick the highest-priority open `Sandcastle`-labeled issue. If an issue is too large or vague, comment asking for a split — do not guess extra work from IMPLEMENTATION.md.

## Workflow

1. **Explore** — read the issue body only, then the cited IMPLEMENTATION.md section and files the issue mentions.
2. **Plan** — stay within issue acceptance criteria; one commit's worth of change.
3. **Execute** — use RGR where tests exist: failing test → implementation → refactor. For greenfield steps without tests yet, follow IMPLEMENTATION.md exactly.
4. **Verify** — run applicable checks before committing:
   - Root: `npm run typecheck` (once configured), `npm run test` (once configured)
   - PHP (M1+): `composer test`, `composer test:integration`
   - Frontend (M4+): `npm run test --prefix frontend`
   Skip commands that do not exist yet; do not invent failing tooling.
5. **Commit** — one git commit. Message MUST:
   - Start with `sandcastle:` prefix
   - Reference the GitHub issue number and IMPLEMENTATION phase/step
   - List key decisions and files changed
6. **Open PR** — push the branch and open a pull request:
   `gh pr create --fill --label Sandcastle`
   Link the issue in the PR body with `Closes #N` only if the issue is fully complete.
7. **Close issue** — only if fully done: `gh issue close <ID> --comment "Completed by Sandcastle implementer."`

## Rules

- **One issue per iteration.** Do not batch multiple issues.
- **Out of scope:** anything in `Planning/FUTURE.md`; ERP Phase 7 / M6 (separate repo).
- **Stack:** PHP 8.3+ plugin (`rfq-intake/`), TypeScript + React + Tailwind (`frontend/`), REST namespace `rfq/v1`.
- Do not leave commented-out code or TODO comments in committed code.
- If blocked, comment on the issue with what is needed; do not close it.
- Do not edit `Planning/FUTURE.md` for new features — deferred ideas go there only when explicitly asked.

# Done

When all actionable issues are complete, you are blocked on all remaining ones, or the open-issues block is empty:

<promise>COMPLETE</promise>
