import { apiFetch } from "../api/client";

/** The health check does a live S3 round trip, which can take several seconds on a cold start. */
export const HEALTH_CHECK_TIMEOUT_MS = 10_000;

export function scheduleHealthCheckAbort(controller: AbortController): () => void {
  const timer = window.setTimeout(() => controller.abort(), HEALTH_CHECK_TIMEOUT_MS);
  return () => {
    window.clearTimeout(timer);
  };
}

export async function performHealthStartup({
  restBase,
  fetchImpl,
  signal,
  clearStartupTimer,
}: {
  restBase: string;
  fetchImpl: typeof fetch;
  signal: AbortSignal;
  clearStartupTimer: () => void;
}): Promise<"ready" | "fallback"> {
  try {
    await apiFetch<{ status: string }>("/health", {
      method: "GET",
      restBase,
      fetchImpl,
      signal,
    });
    return "ready";
  } catch {
    return "fallback";
  } finally {
    clearStartupTimer();
  }
}
