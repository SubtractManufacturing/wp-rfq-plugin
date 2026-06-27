# Pre-Frontend Backlog — Backend & CI Cleanup

**Generated:** 2026-06-26  
**Purpose:** Patch list for everything left behind from M1–M3 **before** investing in the React form.  
**Companion:** [`STATUS-2026-06-26.md`](STATUS-2026-06-26.md) for overall progress.

Use this doc to cut Sandcastle issues. Each item includes a **recommendation** so you can skip work that belongs with the frontend, pre-release, or ops.

---

## TL;DR — what to do before frontend

| Priority | Do now? | Items |
|----------|---------|-------|
| **P0 — Blockers / high risk** | ✅ Yes | S3 spike report (§5.1), scoped acceptance-coverage gate, lint CI |
| **P1 — PRD backend gaps** | ✅ Yes | Orphan manifest alert cron, unreceipted-prefix detection (logic + log, not delete) |
| **P2 — Nice hygiene** | ⚠️ Soon | Contract JSON schemas, `@covers` / Group alignment, 90-day draft retention cron |
| **Defer with frontend** | ❌ Not now | Airtable fallback, upload retry UX, submit retry UX, JWT refresh UX, `[rfq_form]` shortcode |
| **Defer to pre-release** | ❌ Not now | Full 28/28 AC gate, Playwright, Vitest, a11y, Supabase nightly E2E |
| **Ops / not plugin code** | 📋 Checklist | Private bucket policy, external monitoring alerts, ERP backlog alert |

**Estimated Sandcastle issues:** 4–6 small tickets (~1–3 hours each) closes P0–P1.

---

## Already done (do not re-build)

These are commonly confused with “missing” but **exist today**:

| Area | Status | Evidence |
|------|--------|----------|
| All 7 REST endpoints | ✅ | `class-rfq-rest-controller.php` |
| Submit idempotency (AC-WP-001) | ✅ | DB `status=submitted` short-circuit |
| S3 receipt exists, DB still `draft` recovery | ✅ | `resolve_existing_receipt()` + `backfill_submitted_index()` |
| Partial write **logging** (manifest without receipt) | ✅ | `error_log` in `ReceiptService` on failed receipt/index writes |
| Webhook non-blocking (AC-WP-019) | ✅ | `class-rfq-webhook.php` + CI `webhook-e2e` job |
| Rate limits (AC-WP-006) | ✅ | Session + upload-url caps |
| Secrets encrypted + write-only admin | ✅ | `class-rfq-secrets.php` + tests |
| PHPUnit backend coverage | ✅ | 73 unit + integration suites; `npm run test:pre-merge` |

---

## P0 — Fix before frontend

### 1. S3 PUT validation spike report (AC-WP-024)

**What:** Run and **document** real Supabase (or staging bucket) behavior for presigned PUT, CORS, Content-Type binding, exact-key enforcement, path-style endpoint.

**Why before frontend:** The React upload step depends on browser PUT + CORS working from the WP site origin. Unit tests and mock S3 do not prove provider behavior.

**Current state:**
- `npm run qa:s3-upload` exercises the happy path when `config/dev.env.local` or `RFQ_S3_*` env vars are set
- CI runs S3 E2E when secrets are configured
- `Planning/TESTING.md` §5.1 matrix is **all `_TBD_`**

**Recommendation:** ✅ **Do now** — run spike locally/staging, fill a new appendix file (do not edit TESTING.md matrix if you prefer: use `Planning/S3-SPIKE-REPORT.md`), note any presign/CORS fixes needed.

**Not plugin code** unless spike reveals a bug in `create_presigned_put()`.

**Suggested issue:**
```markdown
## Scope
Complete M2 S3 validation spike per TESTING.md §5.1 using npm run qa:s3-upload + manual CORS/Content-Type checks.
Document results in Planning/S3-SPIKE-REPORT.md. Fix presign/CORS only if spike fails.

## Out of scope
React form, Playwright, TESTING.md edits (optional cross-link only)
```

---

### 2. Scoped acceptance-coverage gate

**What:** `scripts/check-acceptance-coverage.php` + `npm run test:acceptance-coverage` that parses `Planning/TESTING.md` (or a small YAML/JSON manifest) and fails CI if **M1–M3 backend AC IDs** lack a mapped test.

**Why before frontend:** Prevents backend regressions while UI work starts. Full 28/28 gate is pre-release — enforcing frontend ACs now would always fail.

**Current state:** Script does not exist. Tests use `#[Group('AC-WP-*')]` attributes (not `@covers` docblocks) — the script should accept **either** Group or `@covers`.

**M1–M3 backend AC IDs to enforce now:**

| ID | Already mapped? |
|----|-----------------|
| AC-WP-001 | ✅ SubmitTest |
| AC-WP-005 | ✅ SubmitTest, WebhookSubmitTest |
| AC-WP-006 | ✅ RestEndpointsTest, RateLimiterTest, JwtTest |
| AC-WP-010 | ✅ AdminSettingsTest |
| AC-WP-011 | ✅ SecretsTest, AdminSettingsTest |
| AC-WP-012 | ✅ RestEndpointsTest |
| AC-WP-016 | ✅ ManifestValidatorTest, SubmitTest (backend half) |
| AC-WP-017 | ✅ ManifestValidatorTest, PostalCodeTest, SubmitTest |
| AC-WP-018 | ✅ SubmitTest |
| AC-WP-019 | ✅ WebhookTest, WebhookSubmitTest |
| AC-WP-020 | ✅ SecretsTest, AdminSettingsTest |
| AC-WP-021 | ✅ WebhookTest, WebhookSubmitTest |
| AC-WP-022 | ✅ SubmitTest |
| AC-WP-023 | ✅ ActivatorTest |
| AC-WP-024 | ⚠️ Partial — spike report still open |
| AC-WP-025 | ❌ No cron yet |
| AC-WP-026 | ❌ No detection yet |
| AC-WP-028 | ✅ IntakeListTest, MaterialCatalogTest |

**Recommendation:** ✅ **Do now** — gate **only** the IDs above (16 + spike). Expand to M4–M5 IDs when frontend lands.

**Suggested issue:**
```markdown
## Scope
Add scripts/check-acceptance-coverage.php + npm run test:acceptance-coverage.
Enforce M1–M3 backend AC-WP-* IDs via PHPUnit #[Group] or @covers.
Add acceptance-coverage job to .github/workflows/test.yml (backend scope only).

## Out of scope
AC-WP-002–004, 007–009, 013–015, 027 (frontend)
```

---

### 3. Lint CI job

**What:** Static analysis in CI per TESTING.md M1 gate.

**Current state:**
- `composer.json` has PHPUnit only — no `phpcs`, `phpstan` scripts or dev deps
- No ESLint/tsc for `frontend/` (placeholder only)
- `.github/workflows/test.yml` runs `php-unit`, `pre-merge`, `webhook-e2e` only

**Recommendation:** ✅ **Do now** for PHP at minimum. Adds safety before frontend PRs touch both trees.

**Minimum viable:**
- PHPCS with WordPress Coding Standards on `rfq-intake/`
- PHPStan level 5–6 on `rfq-intake/includes/`
- Optional: `cd frontend && npm run lint && npm run typecheck` once frontend grows

**Suggested issue:**
```markdown
## Scope
Add PHPCS + PHPStan dev deps, composer lint script, lint job in test.yml.
Fix or baseline any violations in rfq-intake/.

## Out of scope
Frontend ESLint until real components exist
```

---

## P1 — PRD backend gaps (patch before staging sign-off)

### 4. Orphan manifest alert (AC-WP-025)

**What:** WP-Cron (daily is fine) scans for sessions where S3 has `meta/manifest.json` but no `meta/receipt.json` older than **15 minutes**. Log at `error_log` level and/or surface a dismissible admin notice.

**PRD refs:** §3.4 step 10 failure handling, §5.1 monitoring (Medium severity).

**Current state:** Partial write **logs at submit time** but nothing detects **stuck** partial states afterward.

**Recommendation:** ✅ **Do before production / staging sign-off.** Safe to do before frontend — small, isolated cron class. Not a blocker for local React dev if you accept manual log monitoring short-term.

**Implementation sketch:**
- `includes/class-rfq-orphan-monitor.php`
- Register on `plugins_loaded`; schedule `rfq_check_orphan_manifests` daily
- For each `draft` session (or LIST S3 `intake/*/meta/manifest.json`), HeadObject manifest + receipt keys
- If manifest exists, receipt missing, manifest LastModified > 15 min → log + `set_transient` for admin notice
- PHPUnit: unit test detection logic with mock S3 + fixed timestamps

**Out of scope for V1:** PagerDuty/Slack integration (log is enough per IMPLEMENTATION Phase 5.2).

---

### 5. Unreceipted intake prefix detection (AC-WP-026)

**What:** Logic to identify `intake/{session_id}/` prefixes **older than 30 days** with no `receipt.json` as cleanup **candidates**.

**PRD refs:** §3.3.3 lifecycle — PRD explicitly says deletion is a **weekly cron (ERP or ops)**, not necessarily the WP plugin.

**Current state:** Not implemented.

**Recommendation:** ⚠️ **Implement detection + logging now; defer automated deletion.**

Reasons:
- No customer traffic yet — 30-day orphans are not urgent
- Deletion requires LIST permissions and careful ops review
- Detection unblocks ops runbooks without risk

**Minimum:** Same cron class as #4 or a shared `class-rfq-intake-maintenance.php` with:
- `find_unreceipted_prefix_candidates()` → returns session IDs + ages
- Log summary weekly
- Optional: WP-CLI command `wp rfq list-unreceipted-prefixes` for ops

**Do not** auto-delete from WP plugin in V1 unless product explicitly wants it — PRD allows ERP/ops ownership.

---

### 6. 90-day draft session retention (PRD §5.3)

**What:** Archive or delete `rfq_sessions` rows where `status = 'draft'` and `created_at` older than 90 days.

**Current state:** Constant `RFQ_DRAFT_SESSION_RETENTION_DAYS` is defined in `rfq-intake.php` but **never referenced**.

**Recommendation:** ⚠️ **P2 — patch soon, not blocking frontend dev.**

Same maintenance cron can DELETE or soft-archive draft rows. Does not affect S3 (orphan S3 cleanup is separate, #5).

**Suggested issue:** Combine with #4–#5 as one “intake maintenance cron” ticket.

---

## P2 — Hygiene (good before frontend, not urgent)

### 7. Contract JSON schemas + CI job

**What:** `tests/contract/schemas/` with JSON Schema for session create, health, contact, upload-urls, draft, submit responses. CI validates integration test fixtures against schemas.

**Current state:** Empty `.gitkeep` only.

**Recommendation:** ⚠️ **Nice to have** — stabilizes API contract while frontend types are written. Can parallel with first frontend PR if time-constrained.

**If skipped:** Generate TypeScript types manually from PRD §3.2.3 manifest shape + integration test examples.

---

### 8. Standardize AC test tags

**What:** Align on `#[Group('AC-WP-xxx')]` **or** `@covers AC-WP-xxx` docblocks so acceptance-coverage script is unambiguous.

**Current state:** Groups only.

**Recommendation:** ⚠️ **Do when building #2** — pick Groups (already in use) and document in script README.

---

### 9. Standalone receipt number unit tests

**What:** `ReceiptNumberTest.php` for format + daily rollover (called out in TESTING.md §10).

**Current state:** Covered inside `SubmitTest` integration tests.

**Recommendation:** ❌ **Optional** — integration coverage is sufficient unless you want faster isolated unit tests.

---

## Defer — belongs with frontend (M4–M5)

Do **not** patch these in a “backend cleanup” sprint; they require React or the shortcode embed.

| Item | AC ID | Why defer |
|------|-------|-----------|
| `[rfq_form]` shortcode + asset enqueue + `rfqFormConfig` | AC-WP-009 | Phase 5.1 — first task **when** frontend starts |
| Health fail → Airtable iframe (3s timeout) | AC-WP-002 | React mount logic |
| Per-file upload error + retry | AC-WP-003 | StepUploads UI |
| Submit fail → retry without re-upload | AC-WP-004 | StepReview UI |
| TypeScript-only / Tailwind-only enforcement | AC-WP-007, 008 | Real components + ESLint rules |
| Full happy path E2E | AC-WP-013 | Playwright |
| Success screen | AC-WP-014 | SuccessView |
| Page refresh → new session | AC-WP-015 | FormContext |
| >20 parts UI disable | AC-WP-016 | StepUploads (backend already rejects) |
| JWT T-10min silent refresh | — | FormContext |
| Autosave debounce + “Draft not saved” | — | useAutosave hook |
| Accessibility smoke | AC-WP-027 | Playwright + axe |

**Fallback scenarios (PRD §3.6):** Backend already supports the hard parts (idempotent submit, receipt durability, contact/draft persistence). Customer-visible fallback UX is **frontend work**.

---

## Defer — pre-release or ERP repo

| Item | When | Notes |
|------|------|-------|
| Full 28/28 AC-WP-* coverage gate | Pre-release | Expand acceptance-coverage script per milestone |
| Playwright E2E (mocked S3) | M4–M5 | `tests/e2e/` is empty |
| Vitest + MSW | M4–M5 | No frontend components |
| Supabase/LocalStack nightly E2E | Pre-release | TESTING.md main/nightly tier |
| PHP 80% line coverage floor on `includes/` | M3 gate (soft) | Not enforced in CI today |
| ERP import worker + poll | M6 / ERP repo | AC-ERP-* |
| ERP webhook receiver | ERP repo | AC-ERP-002 |
| Import backlog alert (>20 receipts / 1 hr) | ERP ops | PRD §5.1 — not WP plugin |

---

## Ops / config — not plugin enforcement

### Private bucket (PRD §3.3.0, §4.8, ADD §21)

**What PRD requires:** Bucket (or `intake/` prefix) denies anonymous GET/LIST. Unguessable keys alone are insufficient.

**Recommendation:** ❌ **Do not build “private bucket enforcement” into PHP.** This is **infrastructure config** verified at staging/prod deploy.

**Do instead:**
- Add manual checklist item to staging sign-off (already in TESTING.md §11)
- Optional **QA script** (not plugin): `scripts/qa-bucket-privacy.sh` — curl unauthenticated GET against a known object URL → expect 403
- Register script in `qa-reference.md` when added

Health check today verifies **configured credentials can reach S3** — it does not and should not probe public access policy.

---

### Monitoring alerts (PRD §5.1)

| Alert | Owner | Plugin code? |
|-------|-------|--------------|
| Health degraded >60s | External uptime (Pingdom, etc.) | No — consumes `GET /health` |
| Orphan manifests | WP plugin cron (#4 above) | Yes |
| Import backlog | ERP | No |
| Session creation spike (>50 / 5 min) | Optional plugin counter + log | Nice-to-have; defer |

---

## CI gap matrix

What TESTING.md §4 says vs what runs today:

| CI job | TESTING.md tier | Runs today? | Pre-frontend action |
|--------|-----------------|-------------|---------------------|
| `php-unit` | M1 | ✅ | Keep |
| `php-integration` / pre-merge | M1 | ✅ via pre-merge | Keep |
| REST smoke | M1 | ✅ via pre-merge | Keep |
| S3 upload E2E | M2 | ✅ when secrets set | Complete spike doc |
| `webhook-e2e` | M3 | ✅ | Keep |
| `lint` (PHPCS, PHPStan) | M1 | ❌ | **Add (P0)** |
| `contract` | M1 | ❌ | Optional (P2) |
| `acceptance-coverage` | M1+ | ❌ | **Add scoped (P0)** |
| `frontend-unit` | M4–M5 | ❌ | Defer |
| `e2e-mocked` | M4–M5 | ❌ | Defer |
| `a11y` | M5 | ❌ | Defer |
| Frontend `npm run build` in CI | M4+ | ❌ | Defer until shortcode lands |

**Local pre-merge today:** `npm run test:pre-merge` = unit + integration + smoke + optional S3 E2E. Good enough for backend; add acceptance-coverage + lint to that script once they exist.

---

## Suggested Sandcastle issue queue (ordered)

Cut these as separate GitHub issues before frontend:

| # | Title | Priority | Est. |
|---|-------|----------|------|
| 1 | S3 PUT validation spike + `Planning/S3-SPIKE-REPORT.md` | P0 | 1–2 hr |
| 2 | Scoped acceptance-coverage script + CI job (M1–M3 ACs) | P0 | 2–3 hr |
| 3 | PHPCS + PHPStan lint job | P0 | 2–4 hr |
| 4 | Intake maintenance cron: orphan manifest alert (AC-WP-025) | P1 | 2–3 hr |
| 5 | Unreceipted prefix detection + 90-day draft cleanup (AC-WP-026, §5.3) | P1–P2 | 2–3 hr |
| 6 | Contract JSON schemas (optional) | P2 | 2–3 hr |
| 7 | QA script: bucket privacy spot-check (optional) | P2 | 1 hr |

**Then start frontend** with Phase 5.1 shortcode issue — that is the bridge into M4.

---

## Quick answers to “do we need X?”

| Question | Answer |
|----------|--------|
| Acceptance/coverage gates before frontend? | **Yes, scoped to M1–M3 backend ACs only.** Not full 28/28. |
| Private bucket config enforcement in plugin? | **No.** Ops checklist + optional QA curl script. |
| Unreceipted cleanup before frontend? | **Detection + logging yes; auto-delete no.** Low urgency pre-traffic. |
| Partial write alert before frontend? | **Yes for staging/prod** (cron #4). Submit-time logging already exists. |
| Fallback scenarios before frontend? | **No.** Backend ready; UX is React. |
| Contract schemas before frontend? | **Nice, not required.** |
| Lint CI before frontend? | **Yes.** |
| S3 spike report before frontend? | **Yes.** Validates browser upload assumptions. |
| `[rfq_form]` shortcode in this sprint? | **No — first frontend issue**, after P0 cleanup. |

---

## Files referenced

| Doc | Role |
|-----|------|
| `Planning/PRD.md` | Requirements source |
| `Planning/TESTING.md` | AC registry, CI tiers, S3 §5.1 matrix |
| `Planning/IMPLEMENTATION.md` | Phase 5.2 cron, build order |
| `Planning/STATUS-2026-06-26.md` | Overall completion snapshot |
| `.cursor/skills/jm-sandcastle-pr-fixes/qa-reference.md` | Runnable QA index — update when adding scripts |

No changes were made to PRD, ADD, IMPLEMENTATION, or TESTING.
