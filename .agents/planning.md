# Planning documentation conventions

Apply when editing files under `Planning/` or creating product/architecture specs.

## Current scope vs future ideas

**`Planning/PRD.md` and `Planning/ADD.md` describe only what we are building now.**

**`Planning/IMPLEMENTATION.md` is the developer execution guide** derived from the PRD. Update it when the PRD changes.

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
