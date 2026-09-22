# CI Fix Agent

Run when GitHub Actions fails on a Sandcastle PR.

## Failing PR

!`gh pr view {{PR_NUMBER}} --json number,title,body,headRefName,statusCheckRollup --jq '{number, title, headRefName, checks: [.statusCheckRollup[] | {name, state, conclusion}]}' 2>/dev/null || gh pr view --json number,title,body,headRefName,statusCheckRollup --jq '{number, title, headRefName, checks: [.statusCheckRollup[] | {name, state, conclusion}]}'`

## Recent CI logs (if available)

!`gh run list --branch $(gh pr view --json headRefName --jq .headRefName) --limit 3 --json databaseId,conclusion,name --jq '.[]'`

# Task

You are fixing CI failures on the PR above. Work on the PR head branch only.

1. Check out the PR branch if not already on it.
2. Read failing workflow logs: `gh run view <run-id> --log-failed`
3. Fix **only** what CI requires — no scope expansion, no refactors.
4. Run the same commands locally that CI runs (see `.github/workflows/` once it exists).
5. Commit with a Conventional Commit subject matching the actual fix; use
   `ci:` for workflow-only changes or an optional `ci` scope for product fixes.
6. Comment on the PR summarizing the fix.

If you cannot fix without human input (missing secrets, external service), comment on the PR and stop.

<promise>COMPLETE</promise>
