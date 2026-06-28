import { renderHook, waitFor } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { useAppHealthStartup } from "./useAppHealthStartup";

describe("useAppHealthStartup", () => {
  it("returns ready after a successful health check", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ status: "ok" })));

    const { result } = renderHook(() =>
      useAppHealthStartup({
        restBase: "https://example.test/wp-json/rfq/v1",
        fetchImpl: fetchMock,
      }),
    );

    expect(result.current).toEqual({ status: "loading" });

    await waitFor(() => {
      expect(result.current).toEqual({ status: "ready" });
    });
  });

  it("returns fallback when the health check fails", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response("nope", { status: 503 }));

    const { result } = renderHook(() =>
      useAppHealthStartup({
        restBase: "https://example.test/wp-json/rfq/v1",
        fetchImpl: fetchMock,
      }),
    );

    await waitFor(() => {
      expect(result.current).toEqual({ status: "fallback" });
    });
  });

  it("cleans up timers and abort controllers on unmount", () => {
    const abortSpy = vi.spyOn(AbortController.prototype, "abort");
    const clearTimeoutSpy = vi.spyOn(window, "clearTimeout");
    const fetchMock = vi.fn(() => new Promise<Response>(() => undefined));

    const { unmount } = renderHook(() =>
      useAppHealthStartup({
        restBase: "https://example.test/wp-json/rfq/v1",
        fetchImpl: fetchMock,
      }),
    );

    unmount();

    expect(abortSpy).toHaveBeenCalled();
    expect(clearTimeoutSpy).toHaveBeenCalled();
    abortSpy.mockRestore();
    clearTimeoutSpy.mockRestore();
  });
});
