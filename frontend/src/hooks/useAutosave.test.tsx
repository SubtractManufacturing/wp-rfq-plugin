import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { App } from "../App";
import type { RfqFormConfig } from "../types/config";

const config: RfqFormConfig = {
  restBase: "https://example.test/wp-json/rfq/v1",
  nonce: "nonce",
  airtableEmbedUrl: "https://airtable.com/embed/app",
  internationalRfqEmail: "large-rfq@example.test",
  salesContactEmail: "sales@example.test",
  maxParts: 20,
  materials: [],
};

describe("useAutosave", () => {
  it("saves draft immediately when the step changes", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(new Response(JSON.stringify({ status: "ok" })))
      .mockResolvedValueOnce(new Response(JSON.stringify({ session_id: "session-1", token: "jwt" })))
      .mockResolvedValueOnce(new Response(JSON.stringify({ first_name: "Jane" })))
      .mockResolvedValue(new Response(JSON.stringify({ saved: true })));

    const user = userEvent.setup();
    render(<App config={config} fetchImpl={fetchMock} />);

    await screen.findByRole("heading", { name: /contact information/i });
    await user.type(screen.getByLabelText(/first name/i), "Jane");
    await user.type(screen.getByLabelText(/last name/i), "Smith");
    await user.type(screen.getByLabelText(/email/i), "jane@example.com");
    await user.click(screen.getByRole("button", { name: /continue to uploads/i }));

    await waitFor(() => {
      const draftCalls = fetchMock.mock.calls.filter(([url]) => String(url).includes("/draft"));
      expect(draftCalls.length).toBeGreaterThan(0);
    });
  });
});
