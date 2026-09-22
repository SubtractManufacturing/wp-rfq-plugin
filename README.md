# wp-rfq-plugin

Plugin to handle RFQ ingest for WP sites with Subtract Custom Software.

**Stack:** WordPress plugin (PHP 8.3+) + customer-facing form (**TypeScript**, React, Vite, **Tailwind CSS**). See `Planning/IMPLEMENTATION.md` for build setup. Testing: see `Planning/TESTING.md`.

## Autonomous agents (Sandcastle)

This repo is configured for AFK coding with [Sandcastle](https://github.com/mattpocock/sandcastle):

- **Implementer:** Cursor Composer (`composer-2.5`) — executes scoped issues
- **Reviewer:** Codex (`gpt-5.5`) — quality gate
- **CI fixer:** Cursor Composer (`composer-2.5`)

```powershell
npm install
Copy-Item .sandcastle\.env.example .sandcastle\.env   # add keys
npm run labels:create
npm run sandcastle:build-image
npm run sandcastle                                      # implement → review loop
```

Label GitHub issues with **`Sandcastle`** for the agent to pick up. Full guide: [`docs/agents/sandcastle.md`](docs/agents/sandcastle.md). Agent rules: [`AGENTS.md`](AGENTS.md).

## Local development

Build the frontend bundle before loading a page with `[rfq_form]`; WordPress only enqueues `rfq-intake/build/rfq-form.js` and `rfq-intake/build/rfq-form.css` when those artifacts exist.

```bash
composer install
npm install
npm --prefix frontend ci
npm --prefix frontend run build
npm run wp-env start
```

Add `[rfq_form]` to a WordPress page in wp-env. For a focused shortcode smoke check, run:

```bash
bash scripts/qa-shortcode.sh
```

## Plugin packages and releases

Build and inspect the same self-contained zip used by GitHub Releases:

```bash
npm run test:package
```

The artifact is written to `dist/rfq-intake-<version>.zip` and always
unpacks to `rfq-intake/`. It includes the compiled form and production
Composer packages, but excludes repository-only source and documentation.

Plugin Releases are published only by merging the Release Please pull request.
Conventional `fix:`, `feat:`, and breaking-change commits select patch, minor,
and major versions. Other commit types do not publish a release by themselves.
The merge creates the numeric git tag and published GitHub Release, then
attaches the installable zip. Repository Actions settings must grant workflows
read/write access and allow GitHub Actions to create pull requests.

## Documentation

| File | Purpose |
|------|---------|
| `Planning/PRD.md` | Current product requirements |
| `Planning/ADD.md` | Architecture decisions (why) |
| `Planning/IMPLEMENTATION.md` | Step-by-step build guide for developers |
| `Planning/TESTING.md` | Test strategy, acceptance-criterion registry, progressive CI gates |
| `Planning/FUTURE.md` | Deferred ideas — not current scope |
| `CONTEXT.md` | Domain glossary |
| `AGENTS.md` | Instructions for AI coding agents |
| `.agents/planning.md` | Conventions for agents editing planning docs |
| `docs/agents/sandcastle.md` | Sandcastle setup and runbook |
| `docs/agents/worktrees.md` | In-repo git worktrees (`.worktrees/`) |
