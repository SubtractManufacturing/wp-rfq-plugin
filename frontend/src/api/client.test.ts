import { describe, expect, it, vi } from "vitest";
import { apiFetch } from "./client";

describe("apiFetch", () => {
  it("adds the bearer token and returns parsed JSON", async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ token: "next-token" }), {
        status: 200,
        headers: { "Content-Type": "application/json" },
      }),
    );

    const result = await apiFetch<{ token: string }>("/sessions/abc/refresh", {
      method: "POST",
      token: "current-token",
      fetchImpl: fetchMock,
      restBase: "https://example.test/wp-json/rfq/v1",
    });

    expect(result).toEqual({ token: "next-token" });
    expect(fetchMock).toHaveBeenCalledWith(
      "https://example.test/wp-json/rfq/v1/sessions/abc/refresh",
      expect.objectContaining({
        method: "POST",
        headers: expect.objectContaining({
          Authorization: "Bearer current-token",
        }),
      }),
    );
  });

  it("throws field-keyed WP REST errors", async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(
        JSON.stringify({
          code: "rfq_validation",
          message: "Invalid manifest",
          data: { status: 422, fields: { email: "Invalid email" } },
        }),
        { status: 422, headers: { "Content-Type": "application/json" } },
      ),
    );

    await expect(
      apiFetch("/sessions/abc/contact", {
        method: "PATCH",
        body: { email: "bad" },
        fetchImpl: fetchMock,
        restBase: "https://example.test/wp-json/rfq/v1",
      }),
    ).rejects.toMatchObject({
      code: "rfq_validation",
      message: "Invalid manifest",
      status: 422,
      fields: { email: "Invalid email" },
    });
  });
});
