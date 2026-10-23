# Local dev: keep the wp-env site current

**Applies to every AI agent (Cursor, Codex, Claude Code, etc.). This is the single source of truth; other agent config files only point here.**

The developer previews the plugin at <http://localhost:8888/> through `wp-env`. WordPress serves the **compiled** bundle in `rfq-intake/build/` (`rfq-form.js`, `rfq-form.css`, `assets/`), not `frontend/src`. A source edit is invisible on the site until the frontend is rebuilt. The build output is gitignored, so a stale bundle fails silently.

## Rule

After you change frontend code, check whether wp-env is running. If it is, rebuild before you tell the user the work is done, so they can refresh and see it.

## 1. Did you change something that needs a build?

| Changed | Action |
|---|---|
| Anything under `frontend/` (`src/`, `vite.config.ts`, `index.html`, Tailwind/CSS config) | Rebuild (below) |
| `frontend/package.json` dependencies | `npm --prefix frontend install`, then rebuild |
| PHP under `rfq-intake/` | Nothing. The plugin directory is bind-mounted into the container, so PHP changes are live |
| Docs, tests only, planning files | Nothing |

## 2. Is wp-env running?

wp-env uses port 8888 by default (`.wp-env.json` does not override it). Check Docker, not HTTP: the first request to a cold WordPress can time out and give a false negative.

```powershell
docker ps --filter "publish=8888" --format "{{.Names}}"
```

- Prints a container name (ending in `-wordpress-1`): running.
- Prints nothing, or `docker` is not available or errors: treat as **not running**. Skip the build and do not start wp-env yourself unless the user asks.

Optional check that the running site serves *this* checkout (matters if you work in a git worktree):

```powershell
docker inspect <container-name> --format '{{range .Mounts}}{{println .Source}}{{end}}'
```

The list must include the absolute path of this repo's `rfq-intake` directory. wp-env mounts only the checkout it was started from.

## 3. Rebuild

From the repo root of the checkout that wp-env is mounted from:

```powershell
npm --prefix frontend run build
```

This runs `tsc && vite build` and writes to `rfq-intake/build/`. Takes roughly 10-20 seconds.

- If the build fails, fix it or report the error. Never say the change is visible when the build failed.
- After a successful build, tell the user to **hard-refresh** (Ctrl+Shift+R). The script URL is cached by file timestamp, and a plain refresh can serve the old bundle.
- If wp-env is not running, say in your summary that you did not rebuild and that `npm --prefix frontend run build` is needed to preview.

## Gotchas

- **The build deletes `rfq-intake/build/.gitkeep`** (`emptyOutDir: true`). It will show as ` D` in `git status`. Never stage or commit that deletion, and do not "fix" it.
- **Worktrees:** a build inside `.worktrees/<name>/` is not what localhost:8888 serves unless wp-env was started from that worktree. Only rebuild when the mount check above matches, or ask the user.
- **Autonomous runs (Sandcastle implementer/reviewer/CI fixer):** do not run this flow. Those sandboxes have no wp-env for the user to look at, and the build output is not committed.
- **`npm run dev` (Vite) is not a substitute.** The plugin loads `rfq-intake/build/`, not the Vite dev server.
- **Other agents working in parallel:** a build replaces the whole `rfq-intake/build/` directory. It is safe, but a build started mid-edit by another agent can briefly serve a half-finished bundle. Rebuild once at the end of your work, not after every edit.
