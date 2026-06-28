# Pre-Release Backlog

**Purpose:** Track ops, CI, and polish items deferred after Phase 6 UI completion.  
**Companion:** [IMPLEMENTATION.md](IMPLEMENTATION.md) Phase 6, [TESTING.md](TESTING.md) pre-release gates.

These are **not** V1 form blockers but should be addressed before production deploy.

---

## Post–Phase 6 UI / pre-release

| Item | Owner | Notes |
|------|-------|-------|
| Session creation spike logging (>50 / 5 min) | Plugin | `error_log` + optional admin notice in `RFQ_Rate_Limiter` or maintenance cron |
| External health monitoring (>60s degraded) | Ops | Uptime checker on `GET /rfq/v1/health`; not plugin code |
| Production S3 CORS | Ops | Configure bucket for live WP origin; verify with `qa-s3-spike.sh` |
| Nightly real-S3 E2E | CI | Supabase/LocalStack job on `main` per [TESTING.md §4](TESTING.md) |
| Import backlog alert | ERP | PRD §5.1 |
| Optional international notice at form top | Frontend polish | PRD §3.2.2 Step 4 “optionally near top” |
| `AC-WP-015` Playwright refresh test | CI | New session on reload E2E |

---

## Suggested issue order

1. Production S3 CORS + bucket privacy verification (`qa-bucket-privacy.sh`)
2. Nightly real-S3 E2E workflow on `main`
3. Session spike logging in plugin
4. `AC-WP-015` Playwright refresh test
5. External monitoring (ops runbook, not code)
6. ERP import backlog alert (ERP repo)
