# Planning documentation conventions

Apply when editing files under `Planning/` or creating product/architecture specs.

## Current scope vs future ideas

**`Planning/PRD.md` and `Planning/ADD.md` describe only what we are building now.**

**`Planning/IMPLEMENTATION.md` is the developer execution guide** derived from the PRD. Update it when the PRD changes.

**`Planning/TESTING.md` is the verification strategy** — acceptance-criterion registry, progressive CI gates, tooling, S3 validation. Update it when PRD §7 IDs or IMPLEMENTATION §7 checkboxes change.

- Do not include V2, "future", "later", "may add", "revisit after V1", or roadmap language in the PRD or ADD.
- Do not add "Out of Scope (V2)" sections to those files.
- When someone proposes a deferred feature, write it to **`Planning/FUTURE.md`** instead.

## FUTURE.md

- One bullet or short section per idea.
- No acceptance criteria or implementation detail unless needed to remember the idea.
- Remove an entry when the feature is promoted into the PRD (it is then current scope, not future).

## Domain glossary

- **`CONTEXT.md`** (repo root) holds canonical domain terms only — no implementation.
- Update `CONTEXT.md` when grilling or design sessions resolve terminology conflicts.

## Architecture decisions

- **`Planning/ADD.md`** explains *why* for decisions already reflected in the PRD.
- For new hard-to-reverse trade-offs, add a section to ADD (current scope only) or a numbered ADR under `docs/adr/` if the repo adopts that pattern.

## Frontend stack (PRD / IMPLEMENTATION)

When editing product or implementation docs, keep these consistent:

- **Language:** TypeScript (`.ts`/`.tsx`) for the React form — not plain JavaScript source files.
- **Styling:** Tailwind CSS for all form UI — not CSS modules, styled-components, or hand-written per-component stylesheets.
- **Build:** Vite outputs a JS + CSS bundle into the plugin `build/` directory for WordPress to enqueue.
- Say **TypeScript** (or "TS/React") in prose; **JavaScript** refers only to the compiled browser bundle or third-party scripts (e.g. XSS), not the authoring language.

## Testing documentation

| File | Owns |
|------|------|
| `Planning/PRD.md` §5.4, §7 | Product requirement that acceptance criteria require automated verification; stable `AC-WP-*` / `AC-ERP-*` IDs |
| `Planning/ADD.md` §23 | *Why* the testing architecture exists (S3 interface, mock + Supabase spike, progressive CI) |
| `Planning/TESTING.md` | *How* to test: pyramid, registry, gates, S3/CORS coverage, tooling, manual staging |
| `Planning/IMPLEMENTATION.md` | *When* tests and CI jobs activate per milestone M1–M5 |
| `Planning/FUTURE.md` | Deferred test enhancements (mutation, visual regression, load, required Codecov, required WP/PHP PR matrix) |

- Do not put CI job details or test class lists in the PRD.
- Do not put detailed test cases in ADD — one architecture section (§23) is enough.
- Planning docs describe **planned** test/CI artifacts (`composer.json`, `.wp-env.json`, workflows). Those files are created during build milestones M1+, not during planning-only doc updates.
- When promoting a deferred test item from FUTURE.md into V1 scope, move it into TESTING.md and remove it from FUTURE.md.

## Documentation index

| File | Purpose |
|------|---------|
| `Planning/PRD.md` | Current product requirements |
| `Planning/ADD.md` | Architecture decisions (why) |
| `Planning/IMPLEMENTATION.md` | Step-by-step build guide |
| `Planning/TESTING.md` | Test strategy, acceptance registry, CI gates |
| `Planning/FUTURE.md` | Deferred ideas |
| `CONTEXT.md` | Domain glossary |
