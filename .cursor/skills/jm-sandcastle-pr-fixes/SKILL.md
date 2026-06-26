---
name: jm-sandcastle-pr-fixes
description: >-
  Check out a GitHub PR, merge latest main, run Bugbot review, execute full
  repo QA, fix findings and failing tests, commit, and push. Use when the user
  invokes JM-Sandcastle-PR-Fixes, asks to babysit/fix/merge-ready a PR locally,
  or wants automated PR QA + Bugbot + push for wp-rfq-plugin Sandcastle branches.
---

# JM-Sandcastle-PR-Fixes

End-to-end PR hardening for **wp-rfq-plugin**: checkout → merge main → Bugbot → QA → fix → commit → push → report.

**Repo:** `SubtractManufacturing/wp-rfq-plugin` · **Base branch:** `main`

## Inputs (runtime)

The user must supply a PR target. Accept any of:

- PR number: `42`
- PR URL: `https://github.com/SubtractManufacturing/wp-rfq-plugin/pull/42`
- Branch name (if no open PR): `sandcastle/issue-42`

If missing, ask once: *"Which PR number, URL, or branch should I fix?"*

## Workflow

Copy and track:

```
PR Fix Progress:
- [ ] 1. Resolve and checkout PR branch
- [ ] 2. Merge latest main (resolve conflicts)
- [ ] 3. Bugbot review
- [ ] 4. Full QA suite
- [ ] 5. Fix Bugbot + QA failures
- [ ] 6. Re-run QA until green (or blocked)
- [ ] 7. Commit
- [ ] 8. Push to origin
- [ ] 9. Final report
```

---

### Step 1 — Checkout PR branch

Use `gh` from repo root:

```bash
# PR number → checkout head branch
gh pr checkout <number>

# Or resolve URL/branch manually, then:
git fetch origin
git checkout <branch>
```

Record: PR number, branch name, linked issue (from `gh pr view --json number,headRefName,body,title`).

**Blockers:** If checkout fails due to dirty working tree, stash only after explaining what will be stashed and the user confirms. Never force-checkout over uncommitted work without confirmation.

---

### Step 2 — Merge latest main

```bash
git fetch origin main
git merge origin/main
```

- On conflicts: resolve intelligently — preserve PR intent **and** main's correctness. Prefer minimal, correct merges over wholesale rewrites.
- If intent genuinely conflicts (same lines, incompatible behavior), **stop** and report; do not guess.
- After merge: `git status` must be clean or only show intended resolution edits.

---

### Step 3 — Bugbot review

Follow the **review-bugbot** skill exactly:

1. Ensure the PR branch is checked out (Step 1).
2. Launch one `bugbot` subagent (`readonly: true`, `run_in_background: false`).
3. Prompt shape:

```text
Full Repository Path: <absolute path to wp-rfq-plugin>
Diff: branch changes
```

Use `Base Branch: main` only if the repo default differs.

4. Summarize findings in a table (Severity | Location | Finding).
5. **Triage each finding** before fixing — skip invalid or out-of-scope Bugbot reports; note disagreements in the final report.

Do **not** fix yet; collect findings for Step 5.

---

### Step 4 — Full QA suite

Run automated checks in order. **Always re-read** [qa-reference.md](qa-reference.md) and `package.json` `scripts` for newly added commands before running — that file is the living QA index.

#### 4a. Primary gate (always run)

```bash
npm run test:pre-merge
```

This runs: PHP unit → wp-env integration → REST smoke → dev S3 restore → optional S3 E2E (when `config/dev.env.local` exists).

If wp-env is not running, the script starts it; allow time for startup.

#### 4b. Milestone-scoped extras

After reading `Planning/TESTING.md` §4, run any CI-tier jobs that exist **for the current milestone** and are not already covered by `test-pre-merge`:

| When present | Command |
|--------------|---------|
| Lint scripts in `package.json` / `composer.json` | Run each |
| `npm run test:acceptance-coverage` | Run if script exists |
| `npm run test` (Vitest) | M4–M5 |
| `npm run test:e2e` | M4–M5 |
| Frontend typecheck | `npm run typecheck` or frontend `tsc` |

Discover commands dynamically:

```bash
node -e "console.log(Object.entries(require('./package.json').scripts).map(([k,v])=>k+': '+v).join('\n'))"
composer run-script --list 2>/dev/null || true
ls .github/workflows/*.yml 2>/dev/null
```

If GitHub Actions workflows exist, note which jobs CI will run; locally mirror the PR-tier jobs from `Planning/TESTING.md` §4.

#### 4c. Optional S3 QA (when `config/dev.env.local` present)

```bash
npm run qa:upload-urls
npm run qa:s3-upload
```

Skip with reason if credentials/fixtures missing.

#### Record for final report

For **each** command: name, pass/fail/skipped, key error output if failed, duration if notable.

---

### Step 5 — Fix findings

Address in priority order:

1. **Merge conflict residue** — syntax errors, duplicate imports, broken tests from merge.
2. **QA failures** — failing tests, lint, smoke, integration errors.
3. **Valid Bugbot findings** — by severity (Critical → Suggestion).

Rules:

- Fix only issues in **this PR's scope** or directly caused by merge/review fixes.
- Do **not** weaken CI, skip tests, or delete coverage to greenwash failures.
- Do **not** change unrelated code.
- Match existing conventions (read surrounding code first).
- Commit message prefix when fixing review/QA: `sandcastle: review: <summary>` or `sandcastle: ci: <summary>` per `AGENTS.md`.

If blocked (missing secrets, environment, ambiguous product decision): fix what you can, then report blockers clearly.

---

### Step 6 — Re-run QA

After fixes, re-run **all** checks that failed plus `npm run test:pre-merge` as a final gate.

Loop Step 5–6 until green or blocked. Cap at 3 fix cycles; if still failing, stop and report remaining failures.

---

### Step 7 — Commit

Only commit when this skill's workflow reaches this step (user invoked JM-Sandcastle-PR-Fixes).

```bash
git status
git diff
git log -3 --oneline
```

Stage relevant files only. Never commit secrets (`.env`, credentials).

```bash
git add <files>
git commit -m "$(cat <<'EOF'
sandcastle: review: <concise summary of fixes>

EOF
)"
```

Use `sandcastle: ci: …` if fixes are CI-only. Include `(#issue)` when PR links to an issue.

---

### Step 8 — Push

```bash
git push origin HEAD
```

If push rejected (non-fast-forward), `git pull --rebase origin <branch>` once, re-run QA if conflicts were resolved, then push again. Never force-push `main`. Force-push feature branch only if the user explicitly requested it.

---

### Step 9 — Final report

Reply in chat with this structure:

```markdown
## JM-Sandcastle-PR-Fixes — PR #<n> (<branch>)

### Merge
- Merged `origin/main`: yes / conflicts resolved / blocked

### Bugbot
- Findings: <count> (<n> fixed, <n> dismissed, <n> deferred)
- [Table or bullet list of material items]

### QA executed
| Check | Result | Notes |
|-------|--------|-------|
| npm run test:pre-merge | pass/fail/skip | … |
| … | … | … |

### Fixes applied
- …

### Commit & push
- `<sha>` — `<message>`
- Pushed to `origin/<branch>`: yes/no

### Manual QA for you
Checklist of steps **not** fully automatable locally — from [Planning/TESTING.md §11](Planning/TESTING.md) and PR-specific scope:

- [ ] …
```

Always include **Manual QA** items even when automation passed. Minimum set when M1–M3 plugin work is touched:

- [ ] wp-env admin: verify S3 settings saved and `GET /rfq/v1/health` reflects config
- [ ] REST smoke in browser or curl against `localhost:8888`
- [ ] If upload/submit changed: run `npm run qa:upload-urls` and `npm run qa:s3-upload` with real `config/dev.env.local`
- [ ] If admin UI changed: click through settings save/load in WP admin
- [ ] If form UI changed (M4+): load `[rfq_form]` page, walk steps in browser

Add PR-specific manual checks derived from the diff and linked issue acceptance criteria (`AC-WP-*` in `Planning/TESTING.md` §3).

---

## Source-of-truth docs

| Doc | Use |
|-----|-----|
| [qa-reference.md](qa-reference.md) | Runnable QA commands index (update when adding scripts) |
| `Planning/TESTING.md` | Acceptance criteria, CI tiers, manual staging §11 |
| `Planning/IMPLEMENTATION.md` §8 | Milestone test expectations |
| `package.json` / `composer.json` | Script discovery |
| `.github/workflows/` | CI job parity (when present) |
| `AGENTS.md` | Commit conventions, Sandcastle workflow |

## Anti-patterns

- Skipping merge with main before review/QA
- Fixing Bugbot findings without triage
- Pushing without re-running QA after fixes
- Committing `config/dev.env.local` or secrets
- Force-pushing without explicit user request
