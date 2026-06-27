import { render, screen, waitFor } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { App } from "./App";
import type { RfqFormConfig } from "./types/config";

const config: RfqFormConfig = {
  restBase: "https://example.test/wp-json/rfq/v1",
  nonce: "nonce",
  airtableEmbedUrl: "https://airtable.com/embed/app",
  internationalRfqEmail: "rfq@example.test",
  salesContactEmail: "sales@example.test",
  maxParts: 20,
  materials: [],
};

describe("App startup", () => {
  // @covers AC-WP-009
  it("creates a session after a healthy backend and renders the stepper", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(new Response(JSON.stringify({ status: "ok" })))
      .mockResolvedValueOnce(
        new Response(JSON.stringify({ session_id: "session-1", token: "jwt" })),
      );

    render(<App config={config} fetchImpl={fetchMock} />);

    expect(await screen.findByRole("heading", { name: /contact information/i })).toBeInTheDocument();
    expect(screen.getByText(/step 1 of 5/i)).toBeInTheDocument();
    expect(fetchMock).toHaveBeenNthCalledWith(
      1,
      "https://example.test/wp-json/rfq/v1/health",
      expect.objectContaining({ method: "GET" }),
    );
    expect(fetchMock).toHaveBeenNthCalledWith(
      2,
      "https://example.test/wp-json/rfq/v1/sessions",
      expect.objectContaining({ method: "POST" }),
    );
  });

  // @covers AC-WP-002
  it("renders the Airtable fallback when health fails", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response("nope", { status: 503 }));

    render(<App config={config} fetchImpl={fetchMock} />);

    await waitFor(() => {
      expect(screen.getByTitle("RFQ fallback form")).toHaveAttribute(
        "src",
        "https://airtable.com/embed/app",
      );
    });
  });
});
