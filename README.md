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
