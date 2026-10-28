import { act, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";
import { FormProvider, useForm } from "../state/FormContext";
import type { RfqFormConfig } from "../types/config";
import { useStepHistory } from "./useStepHistory";

const config: RfqFormConfig = {
  restBase: "https://example.test/wp-json/rfq/v1",
  nonce: "nonce",
  airtableEmbedUrl: "",
  internationalRfqEmail: "large-rfq@example.test",
  salesContactEmail: "sales@example.test",
  maxParts: 20,
  materials: [],
};

function Harness() {
  const { step, setStep, setContactSaved } = useForm();
  useStepHistory();
  return (
    <>
      <p data-testid="step">{step}</p>
      <button
        onClick={() => {
          setContactSaved(true);
          setStep("uploads");
        }}
        type="button"
      >
        Go uploads
      </button>
      <button onClick={() => setStep("review")} type="button">
        Go review
      </button>
    </>
  );
}

function renderHarness() {
  return render(
    <FormProvider config={config}>
      <Harness />
    </FormProvider>,
  );
}

describe("useStepHistory", () => {
  it("replaces the current history entry on mount instead of adding one", () => {
    const lengthBefore = window.history.length;

    renderHarness();

    expect(window.history.length).toBe(lengthBefore);
    expect(window.history.state).toEqual({ rfqStep: "contact" });
  });

  // @covers AC-WP-036
  it("moves between steps with the browser Back and Forward buttons", async () => {
    const user = userEvent.setup();
    renderHarness();

    await user.click(screen.getByRole("button", { name: /go uploads/i }));
    expect(window.history.state).toEqual({ rfqStep: "uploads" });

    act(() => window.history.back());
    await waitFor(() => expect(screen.getByTestId("step")).toHaveTextContent("contact"));

    act(() => window.history.forward());
    await waitFor(() => expect(screen.getByTestId("step")).toHaveTextContent("uploads"));
  });

  it("stays put when Back would land on a step that is no longer reachable", async () => {
    const user = userEvent.setup();
    renderHarness();

    // Review needs an uploaded part, which this form never has.
    await user.click(screen.getByRole("button", { name: /go review/i }));
    await user.click(screen.getByRole("button", { name: /go uploads/i }));

    act(() => window.history.back());
    await waitFor(() => expect(window.history.state).toEqual({ rfqStep: "review" }));
    expect(screen.getByTestId("step")).toHaveTextContent("uploads");
  });
});
