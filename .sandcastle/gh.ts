import { execFile, execFileSync } from "node:child_process";
import { promisify } from "node:util";

const execFileAsync = promisify(execFile);

const GH_OPTS = {
  cwd: process.cwd(),
  maxBuffer: 1024 * 1024,
  encoding: "utf8" as const,
};

export class GhCommandError extends Error {
  readonly args: string[];
  readonly stderr: string;
  readonly exitCode: number | null;

  constructor(
    message: string,
    args: string[],
    stderr: string,
    exitCode: number | null,
  ) {
    super(message);
    this.name = "GhCommandError";
    this.args = args;
    this.stderr = stderr;
    this.exitCode = exitCode;
  }
}

function extractExecError(error: unknown): {
  stderr: string;
  exitCode: number | null;
  message: string;
} {
  if (error && typeof error === "object") {
    const e = error as {
      stderr?: string;
      code?: number | string;
      message?: string;
    };
    return {
      stderr: (e.stderr ?? "").trim(),
      exitCode: typeof e.code === "number" ? e.code : null,
      message: e.message ?? String(error),
    };
  }
  return { stderr: "", exitCode: null, message: String(error) };
}

export function formatGhError(error: unknown): string {
  if (error instanceof GhCommandError) {
    const parts = [`gh ${error.args.join(" ")} failed`];
    if (error.exitCode !== null) parts.push(` (exit ${error.exitCode})`);
    if (error.stderr) parts.push(`: ${error.stderr}`);
    return parts.join("");
  }

  const { stderr, exitCode, message } = extractExecError(error);
  if (stderr) return stderr;
  if (exitCode !== null) return `${message} (exit ${exitCode})`;
  return message;
}

function wrapGhError(args: string[], error: unknown): GhCommandError {
  const { stderr, exitCode, message } = extractExecError(error);
  return new GhCommandError(message, args, stderr, exitCode);
}

export function ghJsonSync<T>(args: string[]): T {
  try {
    const stdout = execFileSync("gh", args, GH_OPTS);
    return JSON.parse(String(stdout || "[]")) as T;
  } catch (error) {
    throw wrapGhError(args, error);
  }
}

export async function ghJson<T>(args: string[]): Promise<T> {
  try {
    const { stdout } = await execFileAsync("gh", args, GH_OPTS);
    return JSON.parse(stdout || "[]") as T;
  } catch (error) {
    throw wrapGhError(args, error);
  }
}
