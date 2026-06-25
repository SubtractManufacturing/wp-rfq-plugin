# RFQ Intake — Coding Standards

Enforced during Sandcastle review. See also `AGENTS.md` and `Planning/ADD.md`.

## Repository layout

```
rfq-intake/          # WordPress plugin (deployable)
frontend/            # TypeScript + React source → builds to rfq-intake/build/
tests/               # PHPUnit, contract schemas, Playwright (M1+)
Planning/            # Product docs — do not expand scope into FUTURE.md items
```

## PHP (WordPress plugin)

- PHP 8.3+, WordPress 6.4+
- Namespace: `RFQ\` with PSR-4 autoload via `composer.json` (M1+)
- REST namespace: `rfq/v1`
- Constants in bootstrap: `RFQ_MAX_PARTS` (20), `RFQ_MAX_UPLOAD_URLS_PER_SESSION` (200), `RFQ_SESSION_RATE_LIMIT` (10/hour/IP)
- Secrets: AES-256-GCM via `RFQ_Secrets`; never log plaintext credentials
- Use `$wpdb->prepare()` for SQL; `dbDelta()` for schema migrations
- Session `s3_prefix = 'intake/{session_id}/'` set on create, not schema default

## Frontend (React form)

- **Language:** TypeScript only — `.ts`/`.tsx` source, no plain `.js`/`.jsx`
- **Styling:** Tailwind CSS utility classes only — no CSS modules, styled-components, or per-component stylesheets
- **Build:** Vite → single `rfq-form.js` + `rfq-form.css` in `rfq-intake/build/`
- **Root element:** `#rfq-form-root`; scope Tailwind if theme bleed is a problem

## Testing

- Tag tests with `@covers AC-WP-xxx` per `Planning/TESTING.md`
- Progressive CI gates: do not skip M1 PHPUnit scaffold when implementing M1+ features
- Prefer integration tests for REST endpoints; unit tests for pure logic (postal validation, manifest, rate limit)

## Git & PRs

- Commit prefix: `sandcastle:` for autonomous agent commits
- One issue per PR when possible
- PR must reference issue and IMPLEMENTATION.md phase/step

## Out of scope (this repo)

- ERP import worker (Phase 7 / M6) — separate Remix repo
- Draft resumption, customer accounts, items in `Planning/FUTURE.md`
