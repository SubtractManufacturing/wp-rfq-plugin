/**
 * Sandcastle artifact cleanup — ephemeral prompt files and stale worktrees/logs.
 *
 * Sandcastle preserves worktrees when `git status --porcelain` is non-empty.
 * Our prompts write open-sandcastle-issues.json and review-diff.patch, which
 * keeps worktrees (and their node_modules) on disk after close().
 *
 * Usage:
 *   npm run sandcastle:cleanup              # remove safe stale worktrees + old logs
 *   npm run sandcastle:cleanup -- --dry-run
 *   npm run sandcastle:cleanup -- --all     # remove every sandcastle worktree
 */

import { execFile } from "node:child_process";
import { fileURLToPath } from "node:url";
import { readdir, stat, unlink } from "node:fs/promises";
import { join } from "node:path";
import { promisify } from "node:util";
import type { CloseResult, Sandbox } from "@ai-hero/sandcastle";
import { formatGhError, ghJson } from "./gh.js";

const execFileAsync = promisify(execFile);

/** Files created by implement/review prompt shell expressions — safe to delete. */
export const EPHEMERAL_ARTIFACTS = [
  "open-sandcastle-issues.json",
  "review-diff.patch",
] as const;

const WORKTREE_DIR_PREFIX = "sandcastle-sequential-reviewer-";
const DEFAULT_LOG_DAYS = 14;
const DEFAULT_LOG_KEEP = 10;

function repoRoot(): string {
  return process.cwd();
}

async function git(args: string[], cwd = repoRoot()): Promise<string> {
  const { stdout } = await execFileAsync("git", args, {
    cwd,
    maxBuffer: 10 * 1024 * 1024,
  });
  return stdout.trim();
}

export async function stripEphemeralArtifacts(worktreePath: string): Promise<void> {
  for (const name of EPHEMERAL_ARTIFACTS) {
    try {
      await unlink(join(worktreePath, name));
    } catch {
      // missing is fine
    }
  }

  try {
    await git(
      ["clean", "-f", "--", ...EPHEMERAL_ARTIFACTS],
      worktreePath,
    );
  } catch {
    // git clean fails when paths are absent
  }

  try {
    await git(["checkout", "--", "package-lock.json"], worktreePath);
  } catch {
    // no lockfile or nothing to restore
  }
}

export async function worktreePorcelain(worktreePath: string): Promise<string> {
  return git(["status", "--porcelain"], worktreePath);
}

/** Close sandbox after removing prompt artifacts so worktree can be deleted. */
export async function closeSandboxClean(sandbox: Sandbox): Promise<CloseResult> {
  await stripEphemeralArtifacts(sandbox.worktreePath);
  const result = await sandbox.close();

  if (result.preservedWorktreePath) {
    const dirty = await worktreePorcelain(sandbox.worktreePath);
    if (dirty) {
      console.warn(
        `Worktree preserved at ${result.preservedWorktreePath} (uncommitted changes remain).`,
      );
      if (dirty.length < 500) {
        console.warn(dirty);
      }
    } else {
      await removeSandcastleWorktree(sandbox.worktreePath, sandbox.branch, {
        deleteBranch: false,
      });
    }
  }

  return result;
}

interface WorktreeEntry {
  path: string;
  branch: string;
}

async function listSandcastleWorktrees(): Promise<WorktreeEntry[]> {
  const lines = await git(["worktree", "list", "--porcelain"]);
  const entries: WorktreeEntry[] = [];
  let path = "";
  let branch = "";

  for (const line of lines.split("\n")) {
    if (line.startsWith("worktree ")) {
      path = line.slice("worktree ".length);
    } else if (line.startsWith("branch ")) {
      branch = line.slice("branch ".length);
      const normalized = path.replaceAll("\\", "/");
      if (normalized.includes(".sandcastle/worktrees/") && normalized.includes(WORKTREE_DIR_PREFIX)) {
        entries.push({ path, branch });
      }
      path = "";
      branch = "";
    }
  }

  return entries;
}

async function isBranchMergedInto(branch: string, base: string): Promise<boolean> {
  try {
    await git(["merge-base", "--is-ancestor", branch, base]);
    return true;
  } catch {
    return false;
  }
}

type OpenPrStatus = "open" | "none" | "unknown";

async function checkOpenPr(headBranch: string): Promise<OpenPrStatus> {
  try {
    const prs = await ghJson<unknown[]>([
      "pr",
      "list",
      "--head",
      headBranch,
      "--state",
      "open",
      "--json",
      "number",
    ]);
    return prs.length > 0 ? "open" : "none";
  } catch (error) {
    console.warn(
      `Could not check open PR for ${headBranch}: ${formatGhError(error)}`,
    );
    return "unknown";
  }
}

interface RemoveOptions {
  dryRun?: boolean;
  deleteBranch?: boolean;
}

async function removeSandcastleWorktree(
  worktreePath: string,
  branchRef: string,
  options: RemoveOptions = {},
): Promise<void> {
  const branch = branchRef.replace(/^refs\/heads\//, "");
  const label = `${branch} (${worktreePath})`;

  if (options.dryRun) {
    console.log(`[dry-run] would remove worktree ${label}`);
    if (options.deleteBranch) {
      console.log(`[dry-run] would delete branch ${branch}`);
    }
    return;
  }

  await stripEphemeralArtifacts(worktreePath);

  try {
    await git(["worktree", "remove", "--force", worktreePath], repoRoot());
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    console.warn(`Failed to remove worktree ${label}: ${message}`);
    return;
  }

  if (options.deleteBranch) {
    try {
      await git(["branch", "-D", branch], repoRoot());
    } catch {
      // branch may be checked out elsewhere or already deleted
    }
  }

  console.log(`Removed worktree ${label}`);
}

async function cleanupLogs(options: {
  dryRun?: boolean;
  days?: number;
  keep?: number;
}): Promise<void> {
  const logsDir = join(repoRoot(), ".sandcastle", "logs");
  let files: { name: string; mtimeMs: number }[];

  try {
    const names = await readdir(logsDir);
    files = (
      await Promise.all(
        names.map(async (name) => {
          const filePath = join(logsDir, name);
          const info = await stat(filePath);
          if (!info.isFile()) return null;
          return { name, mtimeMs: info.mtimeMs };
        }),
      )
    ).filter((f): f is { name: string; mtimeMs: number } => f !== null);
  } catch {
    return;
  }

  if (files.length === 0) return;

  files.sort((a, b) => b.mtimeMs - a.mtimeMs);
  const keep = options.keep ?? DEFAULT_LOG_KEEP;
  const cutoffMs = Date.now() - (options.days ?? DEFAULT_LOG_DAYS) * 86_400_000;

  for (const [index, file] of files.entries()) {
    const tooOld = file.mtimeMs < cutoffMs;
    const beyondKeep = index >= keep;
    if (!tooOld && !beyondKeep) continue;

    const filePath = join(logsDir, file.name);
    if (options.dryRun) {
      console.log(`[dry-run] would delete log ${file.name}`);
      continue;
    }
    await unlink(filePath);
    console.log(`Deleted log ${file.name}`);
  }
}

interface CleanupCliOptions {
  dryRun: boolean;
  all: boolean;
  mergedOnly: boolean;
  deleteBranches: boolean;
  logDays: number;
  logKeep: number;
}

function parseArgs(argv: string[]): CleanupCliOptions {
  const opts: CleanupCliOptions = {
    dryRun: false,
    all: false,
    mergedOnly: true,
    deleteBranches: false,
    logDays: DEFAULT_LOG_DAYS,
    logKeep: DEFAULT_LOG_KEEP,
  };

  for (const arg of argv) {
    if (arg === "--dry-run") opts.dryRun = true;
    else if (arg === "--all") {
      opts.all = true;
      opts.mergedOnly = false;
    } else if (arg === "--delete-branches") opts.deleteBranches = true;
    else if (arg.startsWith("--logs-days=")) {
      opts.logDays = Number(arg.slice("--logs-days=".length));
    } else if (arg.startsWith("--logs-keep=")) {
      opts.logKeep = Number(arg.slice("--logs-keep=".length));
    } else if (arg === "--help" || arg === "-h") {
      printHelp();
      process.exit(0);
    } else {
      console.error(`Unknown option: ${arg}`);
      printHelp();
      process.exit(1);
    }
  }

  return opts;
}

function printHelp(): void {
  console.log(`Usage: npm run sandcastle:cleanup -- [options]

Options:
  --dry-run            Print actions without deleting
  --all                Remove every sandcastle worktree (default: merged/safe only)
  --delete-branches    Also delete local sandcastle/* branches after worktree remove
  --logs-days=N        Delete logs older than N days (default: ${DEFAULT_LOG_DAYS})
  --logs-keep=N        Always keep N newest log files (default: ${DEFAULT_LOG_KEEP})
`);
}

export async function runCleanupCli(argv: string[]): Promise<void> {
  const opts = parseArgs(argv);
  const worktrees = await listSandcastleWorktrees();

  if (worktrees.length === 0) {
    console.log("No sandcastle worktrees registered.");
  }

  for (const { path, branch } of worktrees) {
    const shortBranch = branch.replace(/^refs\/heads\//, "");

    if (opts.all) {
      await removeSandcastleWorktree(path, branch, {
        dryRun: opts.dryRun,
        deleteBranch: opts.deleteBranches,
      });
      continue;
    }

    await stripEphemeralArtifacts(path);
    const dirty = await worktreePorcelain(path);
    if (dirty && !opts.mergedOnly) {
      console.log(`Skipping dirty worktree ${shortBranch}`);
      continue;
    }

    const merged = await isBranchMergedInto(shortBranch, "main");
    const prStatus = await checkOpenPr(shortBranch);

    if (prStatus === "unknown") {
      console.log(
        `Keeping worktree ${shortBranch} (PR status unknown — gh error)`,
      );
      continue;
    }

    const openPr = prStatus === "open";
    const safeEmpty = !dirty;

    if (opts.all || merged || (safeEmpty && !openPr)) {
      await removeSandcastleWorktree(path, branch, {
        dryRun: opts.dryRun,
        deleteBranch: opts.deleteBranches && merged,
      });
    } else if (openPr) {
      console.log(`Keeping worktree ${shortBranch} (open PR)`);
    } else {
      console.log(`Keeping worktree ${shortBranch} (unmerged, dirty=${Boolean(dirty)})`);
    }
  }

  if (!opts.dryRun) {
    try {
      await git(["worktree", "prune"]);
    } catch {
      // best effort
    }
  } else {
    console.log("[dry-run] would run git worktree prune");
  }

  await cleanupLogs({
    dryRun: opts.dryRun,
    days: opts.logDays,
    keep: opts.logKeep,
  });
}

const isMain = process.argv[1] === fileURLToPath(import.meta.url);

if (isMain) {
  runCleanupCli(process.argv.slice(2)).catch((error) => {
    console.error(error);
    process.exit(1);
  });
}
