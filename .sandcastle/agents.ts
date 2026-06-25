import * as sandcastle from "@ai-hero/sandcastle";

export type ImplementKind = "codex" | "cursor";
export type ReviewKind = "codex" | "cursor";

/** Default: Cursor Composer 2.5 — scoped issues carry architecture; implement is execution. */
export function resolveImplementKind(): ImplementKind {
  const kind = (process.env.SANDCASTLE_IMPLEMENT ?? "cursor").toLowerCase();
  return kind === "codex" ? "codex" : "cursor";
}

/** Default: Codex gpt-5.5 — quality gate; catches scope drift, security, missing tests. */
export function resolveReviewKind(): ReviewKind {
  const kind = (process.env.SANDCASTLE_REVIEW ?? "codex").toLowerCase();
  return kind === "cursor" ? "cursor" : "codex";
}

export function createImplementAgent(kind?: ImplementKind) {
  const resolved = kind ?? resolveImplementKind();
  const model =
    process.env.SANDCASTLE_IMPLEMENT_MODEL ??
    (resolved === "codex" ? "gpt-5.4" : "composer-2.5");

  return resolved === "codex"
    ? sandcastle.codex(model)
    : sandcastle.cursor(model);
}

export function createReviewAgent(kind?: ReviewKind) {
  const resolved = kind ?? resolveReviewKind();
  const model =
    process.env.SANDCASTLE_REVIEW_MODEL ??
    (resolved === "codex" ? "gpt-5.5" : "composer-2.5");

  return resolved === "codex"
    ? sandcastle.codex(model)
    : sandcastle.cursor(model);
}
