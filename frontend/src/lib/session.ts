import { apiFetch } from "../api/client";
import type { SessionResponse } from "../types/api";

export async function ensureSession({
  restBase,
  fetchImpl = fetch,
  sessionId,
  token,
  setSession,
}: {
  restBase: string;
  fetchImpl?: typeof fetch;
  sessionId: string | null;
  token: string | null;
  setSession: (sessionId: string, token: string) => void;
}): Promise<{ sessionId: string; token: string }> {
  if (sessionId && token) {
    return { sessionId, token };
  }

  const session = await apiFetch<SessionResponse>("/sessions", {
    method: "POST",
    restBase,
    fetchImpl,
  });

  setSession(session.session_id, session.token);

  return { sessionId: session.session_id, token: session.token };
}
