import { useCallback, useEffect, useRef } from "react";
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

  const previousStepRef = useRef<string | null>(null);

  const saveDraft = useCallback(async () => {
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
  }, [contact, fetchImpl, global, parts, sessionId, setDraftStatus, token]);

  useEffect(() => {
    if (tokenWarning) {
      return undefined;
    }

    if (previousStepRef.current !== null && previousStepRef.current !== step) {
      previousStepRef.current = step;
      void saveDraft();
      return undefined;
    }

    previousStepRef.current = step;

    const timer = window.setTimeout(() => {
      void saveDraft();
    }, AUTOSAVE_DELAY_MS);

    return () => window.clearTimeout(timer);
  }, [contact, global, parts, saveDraft, step, tokenWarning]);
}
