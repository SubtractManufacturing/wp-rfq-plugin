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
  maxParts: 1,
  materials: [],
};

function contactResponse() {
  return new Response(JSON.stringify({ first_name: "Jane" }));
}

describe("contact and upload flow", () => {
  // @covers AC-WP-003
  // @covers AC-WP-016
  it("saves contact before uploads and allows a failed upload to retry", async () => {
    const user = userEvent.setup();
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(new Response(JSON.stringify({ status: "ok" })))
      .mockResolvedValueOnce(new Response(JSON.stringify({ session_id: "session-1", token: "jwt" })))
      .mockResolvedValueOnce(contactResponse())
      .mockResolvedValueOnce(new Response(JSON.stringify({ upload_url: "https://s3.test/part", file_key: "intake/session-1/parts/file_part.step" })))
      .mockRejectedValueOnce(new Error("S3 down"))
      .mockResolvedValueOnce(new Response(JSON.stringify({ upload_url: "https://s3.test/part2", file_key: "intake/session-1/parts/file_part.step" })))
      .mockResolvedValueOnce(new Response("", { status: 200 }));

    render(<App config={config} fetchImpl={fetchMock} />);

    await screen.findByRole("heading", { name: /contact information/i });
    await user.type(screen.getByLabelText(/first name/i), "Jane");
    await user.type(screen.getByLabelText(/last name/i), "Smith");
    await user.type(screen.getByLabelText(/email/i), "jane@example.com");
    await user.click(screen.getByRole("button", { name: /continue to uploads/i }));

    expect(await screen.findByRole("heading", { name: /part uploads/i })).toBeInTheDocument();
    expect(screen.getAllByText(/large-rfq@example.test/i).length).toBeGreaterThan(0);
    expect(screen.getByRole("button", { name: /add part/i })).toBeDisabled();

    const file = new File(["cad"], "part.step", { type: "application/octet-stream" });
    await user.upload(screen.getByLabelText(/^part file$/i), file);

    expect(await screen.findByText(/upload failed/i)).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /retry upload/i }));

    await waitFor(() => expect(screen.getByText(/uploaded:/i)).toBeInTheDocument());
  });

  it("does not advance on email blur alone", async () => {
    const user = userEvent.setup();
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(new Response(JSON.stringify({ status: "ok" })))
      .mockResolvedValueOnce(new Response(JSON.stringify({ session_id: "session-1", token: "jwt" })))
      .mockResolvedValueOnce(contactResponse());

    render(<App config={config} fetchImpl={fetchMock} />);

    await screen.findByRole("heading", { name: /contact information/i });
    await user.type(screen.getByLabelText(/first name/i), "Jane");
    await user.type(screen.getByLabelText(/last name/i), "Smith");
    await user.type(screen.getByLabelText(/email/i), "jane@example.com");
    await user.tab();

    await waitFor(() => {
      expect(fetchMock.mock.calls.some(([url]) => String(url).includes("/contact"))).toBe(true);
    });
    expect(screen.getByRole("heading", { name: /contact information/i })).toBeInTheDocument();
  });
});
