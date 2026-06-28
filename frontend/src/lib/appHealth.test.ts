import { describe, expect, it, vi } from "vitest";
import { HEALTH_CHECK_TIMEOUT_MS, performHealthStartup, scheduleHealthCheckAbort } from "./appHealth";

describe("performHealthStartup", () => {
  it("returns ready when the health endpoint succeeds", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ status: "ok" })));
    const clearStartupTimer = vi.fn();

    await expect(
      performHealthStartup({
        restBase: "https://example.test/wp-json/rfq/v1",
        fetchImpl: fetchMock,
        signal: new AbortController().signal,
        clearStartupTimer,
      }),
    ).resolves.toBe("ready");

    expect(clearStartupTimer).toHaveBeenCalledTimes(1);
  });

  it("returns fallback when the health endpoint fails", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response("nope", { status: 503 }));
    const clearStartupTimer = vi.fn();

    await expect(
      performHealthStartup({
        restBase: "https://example.test/wp-json/rfq/v1",
        fetchImpl: fetchMock,
        signal: new AbortController().signal,
        clearStartupTimer,
      }),
    ).resolves.toBe("fallback");

    expect(clearStartupTimer).toHaveBeenCalledTimes(1);
  });

  it("aborts the health controller after the timeout elapses", async () => {
    vi.useFakeTimers();
    try {
      const controller = new AbortController();
      const abortSpy = vi.spyOn(controller, "abort");

      scheduleHealthCheckAbort(controller);
      await vi.advanceTimersByTimeAsync(HEALTH_CHECK_TIMEOUT_MS);

      expect(abortSpy).toHaveBeenCalledTimes(1);
      abortSpy.mockRestore();
    } finally {
      vi.useRealTimers();
    }
  });
});
