# Planning repo docs vs GitHub issues

Sandcastle agents see **both** planning files in the repo and open GitHub issues. Use this split for predictable runs.

## Roles

| Layer | Purpose | Agent behavior |
|-------|---------|----------------|
| **Planning/** | Human roadmap — PRD, architecture, full build order, AC registry | Reference only; never "implement the whole doc" |
| **GitHub issue** | **Scope contract for one Sandcastle iteration** | Sole driver of what gets built this run |
| **IMPLEMENTATION.md** | How to build each step (when an issue points at a step) | Read **only** the section named in the issue |

If the issue is vague, the agent will read too much and over-build (e.g. all of M1 from a "smoke test" title). **Put scope in the issue.**

## Issue sizing (one Sandcastle iteration ≈ one issue)

Each issue should be completable in **one commit + one PR** (~15–45 minutes agent time for scaffold steps; less for small fixes).

Good issue:

```markdown
## Scope
Phase 0 Step 0.1 only — directory layout per IMPLEMENTATION.md §Phase 0 Step 0.1.
Do NOT implement Step 0.2+ or any REST endpoints.

## Acceptance criteria
- [ ] Directories `rfq-intake/`, `frontend/`, `tests/` exist as specified
- [ ] No PHP bootstrap yet

## Read first
- Planning/IMPLEMENTATION.md — Phase 0, Step 0.1 only
- CONTEXT.md — skim plugin slug / REST namespace terms

## Out of scope
- Step 0.2 bootstrap, Vite, Tailwind, REST, tests, CI
```

Bad issue (causes overrun):

```markdown
Smoke test for Sandcastle.
```

## Milestone labels

Always set **Sandcastle** + **M1**–**M5**. The implementer picks the lowest milestone among open issues. Keep M1 issues granular until M1 gates pass (`POST /sessions`, `GET /health`).

## Suggested workflow

1. **You** maintain `Planning/IMPLEMENTATION.md` as the master checklist (human planning).
2. **Cut issues** one IMPLEMENTATION step (or small group) at a time using `.github/ISSUE_TEMPLATE/agent-task.yml`.
3. **Keep only 1–3 open `Sandcastle` issues** so priority is obvious.
4. **Close or remove `Sandcastle` label** when an issue is not ready — labeled open issues are the agent queue.
5. After merge, add the next issue; do not batch the whole phase into one ticket.

## Agent models (this repo)

| Role | Default | Override |
|------|---------|----------|
| Implementer | `composer-2.5` (Cursor), host | `SANDCASTLE_IMPLEMENT`, `SANDCASTLE_IMPLEMENT_MODEL` |
| Reviewer | `gpt-5.5` (Codex), host | `SANDCASTLE_REVIEW`, `SANDCASTLE_REVIEW_MODEL` |
| CI fixer | `composer-2.5` (Cursor), host | same as implementer |

**Rationale:** Issues and PRD carry architecture and code snippets; Composer executes. Codex reviews for scope drift, security, and test gaps. Use `npm run sandcastle:legacy` for the old Codex-implement / Cursor-review stack.

Example `.sandcastle/.env` (optional overrides):

```env
SANDCASTLE_REVIEW_MODEL=gpt-5.5
SANDCASTLE_SANDBOX=docker   # npm run sandcastle:docker
```

Default (no env vars needed):

```powershell
npm run sandcastle
```

## Smoke test issue

For pipeline verification only, use a **tiny** scoped issue (e.g. Step 0.1 only), not a title-only smoke test. Close it after the first green PR.
