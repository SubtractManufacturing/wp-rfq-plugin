# TASK

Review the code changes on branch `{{BRANCH}}` for the RFQ Intake WordPress plugin. Verify correctness against the PRD and acceptance criteria, improve clarity where needed, and ensure tests cover new behaviour. Preserve functionality.

# CONTEXT

## Branch diff

The full diff is too large for the Cursor CLI command line on Windows. It is written to **`review-diff.patch`** in the repo root — read that file with your file tools.

!`git diff {{TARGET_BRANCH}}...{{BRANCH}} > review-diff.patch && wc -l review-diff.patch`

## Diff summary

!`git diff --stat {{TARGET_BRANCH}}...{{BRANCH}}`

## Commits on this branch

!`git log {{TARGET_BRANCH}}..{{BRANCH}} --oneline`

## Linked issue (if any)

!`gh pr list --head {{BRANCH}} --json number,body --jq '.[0].body // empty' 2>/dev/null || true`

# REVIEW CHECKLIST

1. **Scope** — Does the change match the linked GitHub issue and the referenced `Planning/IMPLEMENTATION.md` section? Nothing from `Planning/FUTURE.md`?

2. **Durability & security (PRD goals)** — No silent data loss paths; receipt/submit flows respect PRD §1.2 goals; secrets encrypted; no credential leaks; REST inputs validated.

3. **Acceptance criteria** — If the issue lists `AC-WP-*` IDs, confirm tests map to them per `Planning/TESTING.md` (docblock `@covers AC-WP-xxx`).

4. **Stack conventions**
   - PHP: PSR-4 `RFQ\` namespace, WordPress coding patterns, no ERP code in this repo
   - Frontend: TypeScript only (no `.js`/`.jsx` source), Tailwind only (no CSS modules)

5. **Code quality** — Clear naming, minimal nesting, no unnecessary abstractions, no nested ternaries.

6. **Tests** — New/changed behaviour has tests where the milestone expects them (see TESTING.md gates).

7. **Project standards** — Follow @.sandcastle/CODING_STANDARDS.md

# EXECUTION

If you find blocking issues (wrong behaviour, missing AC coverage, security problems):

1. Fix them directly on this branch
2. Run applicable tests
3. Commit with message prefix `sandcastle: review:` describing fixes
4. Comment on the PR summarizing what you changed

If the code is correct and clean, approve by doing nothing to the code.

Once complete:

<promise>COMPLETE</promise>
