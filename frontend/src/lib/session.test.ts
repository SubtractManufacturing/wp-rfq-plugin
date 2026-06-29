import { describe, expect, it, vi } from "vitest";
import { ensureSession } from "./session";

describe("ensureSession", () => {
  it("returns an existing session without calling the API", async () => {
    const fetchMock = vi.fn();
    const setSession = vi.fn();

    await expect(
      ensureSession({
        restBase: "https://example.test/wp-json/rfq/v1",
        fetchImpl: fetchMock,
        sessionId: "session-1",
        token: "jwt",
        setSession,
      }),
    ).resolves.toEqual({ sessionId: "session-1", token: "jwt" });

    expect(fetchMock).not.toHaveBeenCalled();
    expect(setSession).not.toHaveBeenCalled();
  });

  it("creates a session when one does not exist", async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ session_id: "session-2", token: "jwt-2" })),
    );
    const setSession = vi.fn();

    await expect(
      ensureSession({
        restBase: "https://example.test/wp-json/rfq/v1",
        fetchImpl: fetchMock,
        sessionId: null,
        token: null,
        setSession,
      }),
    ).resolves.toEqual({ sessionId: "session-2", token: "jwt-2" });

    expect(fetchMock).toHaveBeenCalledWith(
      "https://example.test/wp-json/rfq/v1/sessions",
      expect.objectContaining({ method: "POST" }),
    );
    expect(setSession).toHaveBeenCalledWith("session-2", "jwt-2");
  });

  it("propagates session creation failures", async () => {
    const fetchMock = vi.fn().mockRejectedValue(new Error("rate limited"));

    await expect(
      ensureSession({
        restBase: "https://example.test/wp-json/rfq/v1",
        fetchImpl: fetchMock,
        sessionId: null,
        token: null,
        setSession: vi.fn(),
      }),
    ).rejects.toThrow("rate limited");
  });
});
