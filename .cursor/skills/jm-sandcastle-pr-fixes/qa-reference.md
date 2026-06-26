# QA Reference — wp-rfq-plugin

Living index of runnable QA. **When adding a new test script or CI job, add a row here** in the same PR.

Documented for agents in: `AGENTS.md` § Testing & QA, `Planning/TESTING.md` §7, `.cursor/rules/agent-core.mdc`.

Canonical strategy: `Planning/TESTING.md`  
Milestone gates: `Planning/TESTING.md` §4  
Manual staging: `Planning/TESTING.md` §11

---

## Pre-merge gate (always run)

| Command | What it runs | Requires |
|---------|--------------|----------|
| `npm run test:pre-merge` | Full M1 gate — see breakdown below | PHP, Composer, Node, Docker (wp-env) |
| `npm run test:pre-merge:automated` | Same without REST smoke | Same |

**`scripts/test-pre-merge.sh` breakdown:**

1. `composer test` — PHP unit (`phpunit.unit.xml.dist`)
2. `npm run test:integration` — wp-env `tests-cli` integration suite
3. `scripts/smoke-rest.sh` — host HTTP probe + `scripts/smoke-rest.php` via tests-cli
4. `scripts/restore-dev-s3-from-env.sh` — restore S3 options from env
5. `scripts/qa-s3-upload.sh` — only if `config/dev.env.local` exists

---

## Individual commands

| Command | Purpose | Milestone |
|---------|---------|-----------|
| `composer test` | PHP unit tests | M1 |
| `npm run test:integration` | PHP integration via wp-env | M1 |
| `npm run test:smoke` | REST smoke only | M1 |
| `npm run wp-env start` | Start local WordPress | M1 |
| `npm run dev:restore-s3` | Restore S3 admin settings from env | M2+ |
| `npm run qa:upload-urls` | Upload-url REST QA | M2+ |
| `npm run qa:s3-upload` | Full S3 PUT E2E + edge cases | M2+ |

---

## Planned commands (run when scaffolded)

From `Planning/TESTING.md` §9 — run if present in `package.json`:

| Command | Purpose | Milestone |
|---------|---------|-----------|
| `npm run test:acceptance-coverage` | AC-WP-* mapping gate | M1+ |
| `npm run test` (Vitest) | React unit tests | M4–M5 |
| `npm run test:e2e` | Playwright E2E (mocked S3) | M4–M5 |
| `npm run test:e2e:localstack` | LocalStack E2E | Pre-release |
| `npm run test:e2e:supabase` | Supabase E2E | Pre-release |

Also check `composer.json` for `lint`, `phpcs`, `phpstan` scripts when added.

---

## Environment prerequisites

| Prerequisite | Check |
|--------------|-------|
| Docker running | `docker info` |
| wp-env up | `npx wp-env status` |
| S3 dev credentials | `config/dev.env.local` (gitignored) |
| Upload fixture | `config/PlaceHolder.step` |

---

## CI parity

When `.github/workflows/test.yml` exists, mirror PR-tier jobs locally per `Planning/TESTING.md` §4:

| Milestone | Expected CI jobs |
|-----------|------------------|
| M1 | lint, php-unit, php-integration, contract, acceptance-coverage |
| M2 | M1 + upload-url integration, S3 unit/mock, S3 spike |
| M3 | M2 + submit integration, receipt/idempotency/webhook |
| M4–M5 | M3 + frontend-unit, e2e-mocked, a11y |

List actual jobs:

```bash
ls .github/workflows/
```

---

## Manual QA (not merge gates)

Always suggest relevant items from `Planning/TESTING.md` §11 in the final report:

- Plugin staging smoke (admin config, `[rfq_form]`, S3 objects, receipt, health failure → Airtable)
- S3 PUT validation spot-check (§5.1 matrix) when presign/upload code changes
- Browser UI walkthrough when frontend ships (M4+)

ERP staging (§11) is **ERP repo** — do not run from this plugin repo.
