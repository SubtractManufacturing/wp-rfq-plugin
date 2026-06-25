import { existsSync } from "node:fs";
import { join } from "node:path";
import { platform } from "node:os";

const GIT_SH_PATHS = [
  "C:\\Program Files\\Git\\usr\\bin",
  "C:\\Program Files (x86)\\Git\\usr\\bin",
];

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
