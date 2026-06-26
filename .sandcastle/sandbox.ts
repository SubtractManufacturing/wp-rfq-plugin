import { docker } from "@ai-hero/sandcastle/sandboxes/docker";
import { noSandbox } from "@ai-hero/sandcastle/sandboxes/no-sandbox";
import { homedir } from "node:os";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

const repoRoot = join(dirname(fileURLToPath(import.meta.url)), "..");

export type SandboxMode = "host" | "docker";

/** Default host — Cursor implement + Codex review on Windows (Git Bash `sh`). Use Docker via `SANDCASTLE_SANDBOX=docker`. */
export function resolveSandboxMode(): SandboxMode {
  const explicit = process.env.SANDCASTLE_SANDBOX?.toLowerCase();
  if (explicit === "host" || explicit === "docker") return explicit;
  return "host";
}

const codexHomeMount = {
  hostPath: join(homedir(), ".codex"),
  sandboxPath: "/home/agent/.codex",
};

/** Disables "Made with Cursor" commit trailers and PR footers in Docker sandboxes. */
const cursorCliConfigMount = {
  hostPath: join(repoRoot, ".sandcastle", "cursor-cli-config.json"),
  sandboxPath: "/home/agent/.cursor/cli-config.json",
};

export function sandboxProvider(mode: SandboxMode) {
  if (mode === "host") {
    return noSandbox();
  }

  return docker({
    mounts: [codexHomeMount, cursorCliConfigMount],
    env: {
      CURSOR_CONFIG_DIR: "/home/agent/.cursor",
    },
  });
}
