# QA Reference — wp-rfq-plugin

Living index of runnable QA. **When adding a new test script or CI job, add a row here** in the same PR.

Documented for agents in: `AGENTS.md` § Testing & QA, `Planning/TESTING.md` §7, `.cursor/rules/agent-core.mdc`.

Canonical strategy: `Planning/TESTING.md`  
Milestone gates: `Planning/TESTING.md` §4  
Manual staging: `Planning/TESTING.md` §11

## Full suite (JM-Sandcastle-PR-Fixes)

Run **all** of these that exist for the current milestone — start with the pre-merge gate, then any extras not already covered:

1. `npm run test:pre-merge` — always
2. Every lint/test script in `package.json` and `composer.json` not run by pre-merge
3. `npm run qa:upload-urls` when `config/dev.env.local` exists
4. `npm run qa:s3-upload` when `config/dev.env.local` exists (also run by pre-merge when configured)
5. Mirror PR-tier CI jobs from `.github/workflows/` per `Planning/TESTING.md` §4
6. CI `webhook-e2e` job — always runs (mock ERP, no secrets required)

Re-run the same set after Bugbot fixes. Loop fix → re-run until green or blocked (max 3 cycles).

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
5. `scripts/qa-s3-upload.sh` — when `config/dev.env.local` or all `RFQ_S3_*` env vars are set

**GitHub Actions secrets for S3 E2E** (optional — skipped when unset):

| Preferred secret | Legacy alias |
|------------------|--------------|
| `RFQ_S3_ENDPOINT` | `S3_ENDPOINT` |
| `RFQ_S3_BUCKET` | `S3_BUCKET_NAME` |
| `RFQ_S3_ACCESS_KEY_ID` | `S3_KEY_ID` |
| `RFQ_S3_REGION` | `S3_REGION` |
| `RFQ_S3_SECRET_KEY` | `S3_SECRET_KEY` |

Fork PRs from outside collaborators do not receive repository secrets.

---

## Individual commands

| Command | Purpose | Milestone |
|---------|---------|-----------|
| `composer test` | PHP unit tests | M1 |
| `composer lint` | PHPCS + PHPStan on `rfq-intake/` | M1 |
| `npm run test:integration` | PHP integration via wp-env | M1 |
| `npm run test:smoke` | REST smoke only | M1 |
| `npm run test:acceptance-coverage` | AC-WP-* mapping gate (M1–M3 backend scope) | M1+ |
| `npm run wp-env start` | Start local WordPress | M1 |
| `npm run dev:restore-s3` | Restore S3 admin settings from env | M2+ |
| `npm run qa:upload-urls` | Upload-url REST QA | M2+ |
| `npm run qa:s3-upload` | Presigned PUT E2E with `config/PlaceHolder.step` | M2+ |
| `npm run qa:s3-spike` | TESTING.md §5.1 supplementary S3 checks | M2+ |
| `npm run qa:bucket-privacy` | Anonymous GET object URL → expect 401/403 (ops) | M2+ |
| `npm run test:contract` | REST contract JSON schema fixtures | M1+ |
| `npm run qa:staging-m3` | TESTING.md §11 backend staging checks (submit + health) | M3 |
| `npm run qa:webhook-e2e` | HTTP webhook E2E against `ci-webhook-mock.mjs` | M3 |
| `npm run webhook:mock` | Start local ERP webhook mock receiver | M3 |
| `bash scripts/qa-shortcode.sh` | `[rfq_form]` mount + scoped asset smoke against wp-env | Phase 5 |
| `npm run test:frontend` | Frontend lint + Vitest unit/component tests | M4–M5 |
| `npm run dev:ui` | Vite dev server (`frontend/npm run dev`) with MSW mocks and preloaded fixture data | M4–M5 |
| `npm run test:e2e` | Playwright mocked RFQ form happy path and fallback checks | M4–M5 |
| `npm run test:a11y` | Playwright + axe accessibility smoke | M5 |

---

## Planned commands (run when scaffolded)

From `Planning/TESTING.md` §9 — run if present in `package.json`:

| Command | Purpose | Milestone |
|---------|---------|-----------|
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

---

## CI parity

When `.github/workflows/test.yml` exists, mirror PR-tier jobs locally per `Planning/TESTING.md` §4:

| Milestone | Expected CI jobs |
|-----------|------------------|
| M1 | lint, php-unit, php-integration, contract, acceptance-coverage |
| M2 | M1 + upload-url integration, S3 unit/mock, S3 spike |
| M3 | M2 + submit integration, receipt/idempotency/webhook, webhook-e2e (mock ERP) |
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
