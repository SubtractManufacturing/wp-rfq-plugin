# wp-rfq-plugin
Plugin to handle RFQ ingest for WP sites with Subtract Custom Software.

**Stack:** WordPress plugin (PHP 8.3+) + customer-facing form (**TypeScript**, React, Vite, **Tailwind CSS**). See `Planning/IMPLEMENTATION.md` for build setup. Testing: see `Planning/TESTING.md`.

## Current status

M1 foundation is now scaffolded:

- `rfq-intake/` plugin bootstrap and REST foundation
- `frontend/` TypeScript, Vite, and Tailwind scaffold
- `tests/` PHPUnit scaffold for M1 activation, health, session, and JWT coverage

## Local commands

```bash
composer install
composer test
composer test:integration
composer test:acceptance-coverage
cd frontend && npm install && npm run typecheck && npm run build
```

## Documentation

| File | Purpose |
|------|---------|
| `Planning/PRD.md` | Current product requirements |
| `Planning/ADD.md` | Architecture decisions (why) |
| `Planning/IMPLEMENTATION.md` | Step-by-step build guide for developers |
| `Planning/TESTING.md` | Test strategy, acceptance-criterion registry, progressive CI gates |
| `Planning/FUTURE.md` | Deferred ideas — not current scope |
| `CONTEXT.md` | Domain glossary |
| `.agents/planning.md` | Conventions for agents editing planning docs |

