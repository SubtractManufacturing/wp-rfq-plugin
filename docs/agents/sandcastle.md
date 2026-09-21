# Sandcastle AFK Setup

Autonomous **implement → review → fix CI** using [Sandcastle](https://github.com/mattpocock/sandcastle).

**Default:** Cursor **Composer 2.5** implements (execution from scoped issues), Codex **gpt-5.5** reviews (quality gate). Both run on **host** sandbox.

Architecture and code snippets belong in GitHub issues / PRD — agents execute and audit, not redesign.

## Host mode on Windows

Requirements: **Git for Windows** (Sandcastle runs `sh` for prompt expansion). Sandcastle prepends `Git\usr\bin` to PATH automatically.

- Cursor implement + Codex review on host: **supported** (default)
- Codex **inside Docker** fails (app-server EPERM) — use host for Codex subscription

| Command | Use when |
|---------|----------|
| `npm run sandcastle` | Composer implement + Codex review (default, host) |
| `npm run sandcastle:docker` | Same agents, Docker sandbox |
| `npm run sandcastle:legacy` | Old stack: Codex implement + Cursor review |
| `npm run sandcastle:wsl` | Run from WSL (separate `codex login` in WSL) |

## Prerequisites

- Docker Desktop (only for `sandcastle:docker`)
- `gh auth login`, Node.js 22+
- **Cursor API key** — implementer + CI fixer (`CURSOR_API_KEY`)
- **Codex CLI + ChatGPT login** — reviewer (`~/.codex/auth.json` on same OS as Sandcastle)
- Cursor `agent` CLI on PATH (implementer)
- `.sandcastle/.env`: `CURSOR_API_KEY`, `GH_TOKEN` — omit `OPENAI_KEY` for Codex subscription

## Setup

```powershell
Copy-Item .sandcastle\.env.example .sandcastle\.env
npm install
npm run sandcastle:build-image   # only if using sandcastle:docker
npm run labels:create
```

`npm run sandcastle` runs `presandcastle` automatically (patches Sandcastle Docker image when using Docker).

## Env overrides

| Variable | Values | Default |
|----------|--------|---------|
| `SANDCASTLE_IMPLEMENT` | `cursor`, `codex` | `cursor` |
| `SANDCASTLE_IMPLEMENT_MODEL` | e.g. `composer-2.5` | `composer-2.5` |
| `SANDCASTLE_REVIEW` | `codex`, `cursor` | `codex` |
| `SANDCASTLE_REVIEW_MODEL` | e.g. `gpt-5.5` | `gpt-5.5` |
| `SANDCASTLE_SANDBOX` | `host`, `docker` | `host` |
| `SANDCASTLE_BASE_REF` | e.g. `origin/main` | `origin/main` (fetched before each run) |

## WSL setup

```bash
cd ~/projects/wp-rfq-plugin
codex login
gh auth login
npm install
cp .sandcastle/.env.example .sandcastle/.env
npm run sandcastle
```

Or from PowerShell: `npm run sandcastle:wsl`.

## Troubleshooting

| Problem | Fix |
|---------|-----|
| Codex `app-server` EPERM in Docker | Use host sandbox (default), not Docker, for Codex |
| `spawn sh ENOENT` on host | Install Git for Windows, or use `sandcastle:docker` |
| `agent: command not found` (host) | Re-run `npm run sandcastle` — prepends `.sandcastle/bin/agent` shim for Git Bash. If still failing, install CLI: `irm 'https://cursor.com/install?win32=true' \| iex` |
| `No version directories found` | Known Cursor CLI Windows bug — `npm run presandcastle` patches launcher regex; or reinstall CLI |
| Cursor auth fails | Set `CURSOR_API_KEY` |
| Codex review fails | Run `codex login` on the same OS as Sandcastle |
| `gpt-5.5` model not found | Test in PowerShell: `codex exec -m gpt-5.5 "say ok"` (prompt is the argument, not `-c`). If it fails, set `SANDCASTLE_REVIEW_MODEL` to a model your Codex CLI supports (e.g. `gpt-5.4`). |
| No commits | No open `Sandcastle`-labeled issues |
| Stuck CI | `npm run sandcastle:fix-ci -- <PR#>` (Composer 2.5); escalate manually if needed |
| Stale worktrees / logs | `npm run sandcastle:cleanup` (see `--dry-run`, `--all`) |
| Sandcastle exits immediately / cleanup keeps all worktrees | Run `gh auth status`; set `GH_TOKEN` in `.sandcastle/.env` |

Logs: `.sandcastle/logs/`

### Stale main / wrong PR base

Sandcastle forks each iteration from **`origin/main`** (not host `HEAD`). Before each run it runs `git fetch origin main` and updates the local `main` ref without checking it out.

If a PR was opened with the wrong base (e.g. stacked on another Sandcastle branch):

```powershell
gh pr edit <PR#> --base main
# or rebase the branch onto main and force-push if needed
```

Implementer prompts require `gh pr create --base main --head <sandbox-branch>` and forbid `sandcastle/implementer/issue-*` branch names.

### Worktree and log cleanup

Sandcastle worktrees live in `.sandcastle/worktrees/`. Manual feature worktrees live in `.worktrees/` — see [`worktrees.md`](worktrees.md).

Sandcastle calls `close()` after each iteration, but **preserves worktrees when git sees uncommitted changes**. Our prompts write ephemeral files (`open-sandcastle-issues.json`, `review-diff.patch`) that count as dirty, so worktrees (and their `node_modules`) can linger.

- **Prevention:** `main.ts` / `review-branch.ts` strip those files before `close()`.
- **Bulk cleanup:** `npm run sandcastle:cleanup` removes merged or clean sandcastle worktrees and prunes old logs (default: older than 14 days, keep 10 newest).
- **Preview:** `npm run sandcastle:cleanup -- --dry-run`
- **Nuke all sandcastle worktrees:** `npm run sandcastle:cleanup -- --all`
