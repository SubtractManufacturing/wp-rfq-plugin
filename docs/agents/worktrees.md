# Git worktrees

Feature checkouts live in `.worktrees/` inside this repo. Git ignores that directory, so a linked worktree stays in the project folder and is never committed.

Sandcastle uses a different directory, `.sandcastle/worktrees/`. Leave that path to `npm run sandcastle` and `npm run sandcastle:cleanup`.

## Add a worktree

From the main checkout, on `main`:

```powershell
git worktree add .worktrees/<name>
```

That creates a new branch named `<name>` from the current `HEAD` and checks it out at `.worktrees/<name>`.

To name the branch yourself:

```powershell
git worktree add -b <branch> .worktrees/<name>
```

To check out a branch that already exists, and is not checked out in another worktree:

```powershell
git worktree add .worktrees/<name> <branch>
```

Open `.worktrees/<name>` as its own editor window. The main checkout can stay on `main`.

## See and remove worktrees

```powershell
git worktree list
git worktree remove .worktrees/<name>
```

If the branch existed only for that worktree and is merged, delete it:

```powershell
git branch -d <branch>
```

If a worktree directory was deleted without `git worktree remove`, drop the stale registration:

```powershell
git worktree prune
```

## Rules

- Keep `.worktrees/` in `.gitignore`. The main checkout then ignores files that belong to other worktrees.
- A branch can be checked out in only one worktree at a time.
- Commit from the worktree that has the branch checked out. Push that branch; do not commit worktree files from the `main` checkout.
