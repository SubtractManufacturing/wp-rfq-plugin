import { useEffect } from "react";
import { apiFetch } from "../api/client";
import { getJwtExpiration } from "../lib/jwt";
import type { SessionResponse } from "../types/api";

const REFRESH_BUFFER_MS = 10 * 60 * 1000;

export function useJwtRefresh({
  token,
  sessionId,
  onToken,
  onWarning,
}: {
  token: string | null;
  sessionId: string | null;
  onToken: (token: string) => void;
  onWarning: (warning: boolean) => void;
}) {
  useEffect(() => {
    if (!token || !sessionId) {
      return undefined;
    }

    const exp = getJwtExpiration(token);
    if (!exp) {
      return undefined;
    }

    const delay = Math.max(exp * 1000 - Date.now() - REFRESH_BUFFER_MS, 0);
    const timer = window.setTimeout(() => {
      void apiFetch<SessionResponse>(`/sessions/${sessionId}/refresh`, {
        method: "POST",
        token,
      })
        .then((response) => {
          onToken(response.token);
          onWarning(false);
        })
        .catch(() => onWarning(true));
    }, delay);

    return () => window.clearTimeout(timer);
  }, [onToken, onWarning, sessionId, token]);
}
