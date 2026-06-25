import { existsSync, mkdirSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { homedir, platform } from "node:os";

const GIT_SH_PATHS = [
  "C:\\Program Files\\Git\\usr\\bin",
  "C:\\Program Files (x86)\\Git\\usr\\bin",
];

function toGitBashPath(winPath: string): string {
  const normalized = winPath.replace(/\\/g, "/");
  if (/^[A-Za-z]:/.test(normalized)) {
    return `/${normalized[0].toLowerCase()}${normalized.slice(2)}`;
  }
  return normalized;
}

function cursorAgentCmdPath(): string | null {
  const candidates = [
    join(process.env.LOCALAPPDATA ?? "", "cursor-agent", "agent.cmd"),
    join(homedir(), "AppData", "Local", "cursor-agent", "agent.cmd"),
  ];

  for (const candidate of candidates) {
    if (existsSync(candidate)) return candidate;
  }
  return null;
}

/** Sandcastle noSandbox invokes `sh`; on Windows that comes from Git for Windows. */
export function ensureWindowsSh(): boolean {
  if (platform() !== "win32") return true;

  for (const dir of GIT_SH_PATHS) {
    if (existsSync(join(dir, "sh.exe"))) {
      if (!process.env.PATH?.toLowerCase().includes(dir.toLowerCase())) {
        process.env.PATH = `${dir};${process.env.PATH ?? ""}`;
      }
      return true;
    }
  }
  return false;
}

/**
 * Sandcastle runs `agent` through Git Bash `sh`, which cannot resolve `agent.cmd`
 * on PATH the way PowerShell/cmd do. Write a small shim and prepend it to PATH.
 */
export function ensureWindowsCursorAgent(repoRoot = process.cwd()): boolean {
  if (platform() !== "win32") return true;

  const agentCmd = cursorAgentCmdPath();
  if (!agentCmd) return false;

  const binDir = join(repoRoot, ".sandcastle", "bin");
  mkdirSync(binDir, { recursive: true });

  const shimPath = join(binDir, "agent");
  const gitPath = toGitBashPath(agentCmd);
  writeFileSync(shimPath, `#!/bin/sh\nexec "${gitPath}" "$@"\n`, "utf8");

  if (!process.env.PATH?.toLowerCase().includes(binDir.toLowerCase())) {
    process.env.PATH = `${binDir};${process.env.PATH ?? ""}`;
  }
  return true;
}

export function printCodexWindowsHelp(): void {
  console.error(`
Sandcastle host mode on Windows needs a Unix shell (Sandcastle runs \`sh\`).

Pick one:

  1. Install Git for Windows (adds sh), then: npm run sandcastle
     https://git-scm.com/download/win

  2. Use Docker sandbox: npm run sandcastle:docker

  3. Use WSL — codex login inside WSL, then:
     npm run sandcastle:wsl

Codex subscription inside Docker is not supported (Codex app-server EPERM).
`);
}

export function printCursorAgentHelp(): void {
  console.error(`
Sandcastle could not run the Cursor \`agent\` CLI from Git Bash.

Pick one:

  1. Install or repair Cursor CLI, then re-run sandcastle:
     irm 'https://cursor.com/install?win32=true' | iex
     agent --version

  2. If \`agent --version\` fails with "No version directories found", run:
     npm run presandcastle
     (patches the Windows launcher regex — see docs/agents/sandcastle.md)

Expected install location:
  %LOCALAPPDATA%\\cursor-agent\\agent.cmd
`);
}
