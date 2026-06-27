import { useEffect } from "react";
import { apiFetch } from "../api/client";
import { buildDraftPayload } from "../lib/draft";
import { useForm } from "../state/FormContext";

const AUTOSAVE_DELAY_MS = 30_000;

export function useAutosave({ fetchImpl = fetch }: { fetchImpl?: typeof fetch } = {}) {
  const {
    contact,
    global,
    parts,
    sessionId,
    setDraftStatus,
    step,
    token,
    tokenWarning,
  } = useForm();

  useEffect(() => {
    if (tokenWarning) {
      return undefined;
    }

    const timer = window.setTimeout(() => {
      void saveDraft();
    }, AUTOSAVE_DELAY_MS);

    return () => window.clearTimeout(timer);
  }, [contact, global, parts, step, tokenWarning]);

  async function saveDraft() {
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
}
