import { apiFetch } from "../api/client";
import { buildDraftPayload } from "./draft";
import type { ContactState, GlobalState, PartRow } from "../types/manifest";

export async function persistSessionDraft({
  sessionId,
  token,
  contact,
  parts,
  global,
  fetchImpl = fetch,
  setDraftStatus,
}: {
  sessionId: string | null;
  token: string | null;
  contact: ContactState;
  parts: PartRow[];
  global: GlobalState;
  fetchImpl?: typeof fetch;
  setDraftStatus: (status: "saving" | "saved" | "error") => void;
}): Promise<void> {
  if (!sessionId || !token) {
    return;
  }

  setDraftStatus("saving");
  try {
    await apiFetch(`/sessions/${sessionId}/draft`, {
      method: "PUT",
      token,
      fetchImpl,
      body: buildDraftPayload(sessionId, contact, parts, global),
    });
    setDraftStatus("saved");
  } catch {
    try {
      await apiFetch(`/sessions/${sessionId}/draft`, {
        method: "PUT",
        token,
        fetchImpl,
        body: buildDraftPayload(sessionId, contact, parts, global),
      });
      setDraftStatus("saved");
    } catch {
      setDraftStatus("error");
    }
  }
}
