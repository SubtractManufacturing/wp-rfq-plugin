# AGENTS.md

Instructions for AI coding agents (Cursor, Codex via Sandcastle, and manual Composer sessions).

## Project

WordPress plugin **RFQ Intake** — custom multi-step RFQ form with durable S3-backed submission. Greenfield; planning docs exist, code is built milestone-by-milestone.

## Read first

1. `Planning/IMPLEMENTATION.md` — your task list
2. `Planning/PRD.md` — requirements
3. `Planning/TESTING.md` — acceptance criteria (`AC-WP-*`)
4. `CONTEXT.md` — domain terms

## Sandcastle autonomous workflow

Three roles, configured in `.sandcastle/main.ts`:

| Role | Agent | Model default | Sandbox |
|------|-------|---------------|---------|
| Implementer | Cursor CLI | `composer-2.5` | Host (Git Bash on Windows) |
| Reviewer | Codex CLI | `gpt-5.5` | Host |
| CI fixer | Cursor CLI | `composer-2.5` | Host |

Use `npm run sandcastle:docker` for Docker sandbox. Use `npm run sandcastle:legacy` for Codex implement + Cursor review.

```powershell
npm run sandcastle:build-image   # once, Docker must be running (docker mode only)
npm run sandcastle               # Composer implement → Codex review (host)
npm run sandcastle:docker        # same agents, Docker sandbox
npm run sandcastle:fix-ci -- 42  # fix CI on PR #42 (Composer 2.5)
```

Pick up issues labeled **`Sandcastle`**. Issue sizing and planning split: `docs/agents/issue-planning.md`. Sandcastle setup: `docs/agents/sandcastle.md`.

## Milestones (do not skip)

| Milestone | Phases | Key gate |
|-----------|--------|----------|
| M1 | 0–2 | REST sessions + health |
| M2 | 3 | S3 presigned upload |
| M3 | 4 | Submit + receipt durability |
| M4–M5 | 5–6 | React form E2E |
| M6 | 7 | **ERP repo — not this repo** |

## Commit conventions

- Sandcastle commits: `sandcastle: <summary> (#issue)`
- Review fixes: `sandcastle: review: <summary>`
- CI fixes: `sandcastle: ci: <summary>`

## Out of scope

Everything in `Planning/FUTURE.md` and ERP Phase 7 (M6).
