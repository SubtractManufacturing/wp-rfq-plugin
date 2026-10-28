import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useEffect } from "react";
import { describe, expect, it } from "vitest";
import { FormProvider, useForm } from "../state/FormContext";
import type { RfqFormConfig } from "../types/config";
import { emptyGlobal } from "../types/manifest";
import { StepGlobal } from "./StepGlobal";

const config: RfqFormConfig = {
  restBase: "https://example.test/wp-json/rfq/v1",
  nonce: "nonce",
  airtableEmbedUrl: "",
  internationalRfqEmail: "large-rfq@example.test",
  salesContactEmail: "sales@example.test",
  maxParts: 20,
  materials: [],
};

function Seed({ referenceNumber }: { referenceNumber: string | null }) {
  const { setGlobal } = useForm();
  useEffect(() => {
    setGlobal({ ...emptyGlobal, reference_number: referenceNumber });
  }, [referenceNumber, setGlobal]);
  return null;
}

describe("StepGlobal reference number", () => {
  it("hides the reference number behind a More details toggle placed after notes", async () => {
    const user = userEvent.setup();
    render(
      <FormProvider config={config}>
        <StepGlobal />
      </FormProvider>,
    );

    expect(screen.queryByLabelText(/purchase order/i)).not.toBeInTheDocument();
    expect(screen.queryByLabelText(/reference number/i)).not.toBeInTheDocument();

    const toggle = screen.getByRole("button", { name: "More details" });
    const notes = screen.getByLabelText("Additional notes");
    expect(toggle).toHaveAttribute("aria-expanded", "false");
    expect(notes.compareDocumentPosition(toggle) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();

    await user.click(toggle);
    expect(toggle).toHaveAttribute("aria-expanded", "true");
    const field = screen.getByLabelText(/reference number/i);
    expect(field).toBeInTheDocument();
    expect(screen.getByText(/quotes and other documents/i)).toBeInTheDocument();

    await user.type(field, "JOB-42");
    expect(field).toHaveValue("JOB-42");

    await user.click(toggle);
    expect(screen.queryByLabelText(/reference number/i)).not.toBeInTheDocument();
  });

  it("starts expanded when a reference number is already set", () => {
    render(
      <FormProvider config={config}>
        <Seed referenceNumber="JOB-42" />
        <StepGlobal />
      </FormProvider>,
    );

    expect(screen.getByRole("button", { name: "More details" })).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByLabelText(/reference number/i)).toHaveValue("JOB-42");
  });
});
