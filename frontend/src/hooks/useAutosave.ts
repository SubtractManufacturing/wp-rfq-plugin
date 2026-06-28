import { useCallback, useEffect, useRef } from "react";
import { persistSessionDraft } from "../lib/persistSessionDraft";
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
    await persistSessionDraft({
      sessionId,
      token,
      contact,
      parts,
      global,
      fetchImpl,
      setDraftStatus,
    });
  }, [contact, fetchImpl, global, parts, sessionId, setDraftStatus, token]);

  useEffect(() => {
    if (!sessionId || !token || tokenWarning) {
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
  }, [contact, global, parts, saveDraft, sessionId, step, token, tokenWarning]);
}
