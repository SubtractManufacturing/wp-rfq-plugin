import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { FormProvider } from "../state/FormContext";
import type { RfqFormConfig } from "../types/config";
import { StepReview } from "./StepReview";

const config: RfqFormConfig = {
  restBase: "https://example.test/wp-json/rfq/v1",
  nonce: "nonce",
  airtableEmbedUrl: "https://airtable.com/embed/app",
  internationalRfqEmail: "rfq@example.test",
  salesContactEmail: "sales@example.test",
  maxParts: 20,
  materials: [],
};

function renderReview(fetchMock: typeof fetch) {
  return render(
    <FormProvider config={config} sessionId="session-1" token="jwt">
      <StepReview fetchImpl={fetchMock} />
    </FormProvider>,
  );
}

describe("StepReview submit feedback", () => {
  it("shows a spinner and ignores repeated clicks while the submit is in flight", async () => {
    const user = userEvent.setup();
    let resolveSubmit: (response: Response) => void = () => undefined;
    const fetchMock = vi.fn(
      () =>
        new Promise<Response>((resolve) => {
          resolveSubmit = resolve;
        }),
    );
    renderReview(fetchMock as unknown as typeof fetch);

    const submit = screen.getByRole("button", { name: /submit rfq/i });
    await user.click(submit);
    await user.click(submit);
    await user.click(submit);

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(screen.getByRole("button", { name: /submitting/i })).toHaveAttribute("aria-busy", "true");
    expect(screen.getByTestId("spinner")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /back/i })).toBeDisabled();

    resolveSubmit(new Response(JSON.stringify({ error: "boom" }), { status: 500 }));
    await waitFor(() => expect(screen.queryByTestId("spinner")).not.toBeInTheDocument());
  });

  it("lets the customer retry after a failed submit", async () => {
    const user = userEvent.setup();
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ message: "Server error" }), { status: 500 }));
    renderReview(fetchMock as unknown as typeof fetch);

    await user.click(screen.getByRole("button", { name: /submit rfq/i }));

    const retry = await screen.findByRole("button", { name: /retry submission/i });
    expect(retry).toHaveAttribute("aria-busy", "false");
    await user.click(retry);
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
  });
});
