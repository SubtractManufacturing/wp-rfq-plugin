# Example issue body for M1 Phase 0 — copy/adapt when creating issues

## Scope

Implement **Phase 0** from `Planning/IMPLEMENTATION.md`:

- Step 0.1 — directory layout (`rfq-intake/`, `frontend/`, `tests/`)
- Step 0.2 — bootstrap `rfq-intake/rfq-intake.php` with constants and hooks
- Step 0.3 — `frontend/vite.config.ts` → build to `rfq-intake/build/`
- Step 0.4 — Tailwind CSS setup in `frontend/`

## Read first

- `Planning/IMPLEMENTATION.md` §Phase 0
- `Planning/PRD.md` §2 (architecture overview)
- `CONTEXT.md`

## Acceptance criteria

- [ ] Directory layout matches IMPLEMENTATION.md §Step 0.1
- [ ] Plugin bootstrap file loads without fatal errors
- [ ] Vite config outputs to `rfq-intake/build/`
- [ ] Tailwind configured with `content: ['./src/**/*.{ts,tsx}']`
- [ ] No test infrastructure yet (M1 adds PHPUnit — Phase 0 explicitly skips it)

## Out of scope

- Phase 1+ (database, REST, S3)
- `Planning/FUTURE.md` items
- ERP M6

## Milestone

M1 (partial — scaffold only; REST comes in separate issues)
