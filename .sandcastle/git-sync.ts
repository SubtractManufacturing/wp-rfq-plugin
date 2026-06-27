/**
 * Keep Sandcastle worktrees and review diffs aligned with the latest remote main.
 *
 * Sandcastle's default base is host HEAD; if local main is behind origin/main,
 * worktrees fork stale code and PRs stack on the wrong base.
 */

import { execFile } from "node:child_process";
import { promisify } from "node:util";

const execFileAsync = promisify(execFile);

/** Git ref used when creating sandcastle worktree branches. Override via env. */
export function sandcastleBaseRef(): string {
  const explicit = process.env.SANDCASTLE_BASE_REF?.trim();
  return explicit && explicit.length > 0 ? explicit : "origin/main";
}

async function git(args: string[], cwd: string): Promise<string> {
  const { stdout } = await execFileAsync("git", args, {
    cwd,
    maxBuffer: 10 * 1024 * 1024,
  });
  return stdout.trim();
}

/**
 * Fetch remote main and move the local `main` ref to match (without checkout).
 * Safe when the host repo is on another branch — does not switch branches.
 */
export async function syncSandcastleBaseRef(cwd = process.cwd()): Promise<string> {
  const baseRef = sandcastleBaseRef();
  const remote = baseRef.startsWith("origin/") ? "origin" : "origin";
  const remoteBranch = baseRef.startsWith("origin/")
    ? baseRef.slice("origin/".length)
    : "main";

  console.log(`Syncing Sandcastle base ref (${baseRef})…`);
  await execFileAsync("git", ["fetch", remote, remoteBranch], { cwd });

  const resolved = await git(["rev-parse", baseRef], cwd);
  const short = resolved.slice(0, 8);

  if (remoteBranch === "main") {
    try {
      await execFileAsync(
        "git",
        ["show-ref", "--verify", "--quiet", "refs/heads/main"],
        { cwd },
      );
      await execFileAsync("git", ["branch", "-f", "main", resolved], { cwd });
    } catch {
      try {
        await execFileAsync("git", ["branch", "main", resolved], { cwd });
      } catch {
        // main may already exist at the right commit
      }
    }
  }

  console.log(`Sandcastle base: ${baseRef} (${short})`);
  return baseRef;
}
