import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useEffect } from "react";
import { describe, expect, it } from "vitest";
import { FormProvider, useForm } from "../state/FormContext";
import type { RfqFormConfig } from "../types/config";
import type { PartRow } from "../types/manifest";
import { StepPartMeta } from "./StepPartMeta";

const config: RfqFormConfig = {
  restBase: "https://example.test/wp-json/rfq/v1",
  nonce: "nonce",
  airtableEmbedUrl: "",
  internationalRfqEmail: "large-rfq@example.test",
  salesContactEmail: "sales@example.test",
  maxParts: 20,
  materials: [
    {
      id: "6061-t6-aluminum",
      label: "6061-T6 Aluminum",
      aliases: ["6061"],
      show_in_dropdown: true,
      group: "Metal",
      type: "Aluminum",
      guidance: "The general-purpose default.",
      finishes: ["As-machined", "Anodize Type II"],
    },
  ],
};

function part(id: string, filename: string | null): PartRow {
  return {
    part_id: id,
    partFile: filename
      ? { file_key: `k/${filename}`, filename, content_type: "application/octet-stream", status: "confirmed", progress: 100 }
      : null,
    drawings: [],
    material: "",
    tolerance: "standard",
    tolerance_detail: null,
    threads_features: null,
    quantity: 1,
    target_unit_price: null,
    notes: null,
  };
}

function Seed({ rows }: { rows: PartRow[] }) {
  const { setParts } = useForm();
  useEffect(() => {
    setParts(rows);
  }, [rows, setParts]);
  return null;
}

describe("StepPartMeta", () => {
  it("titles each part card with its uploaded filename", () => {
    const rows = [part("1", "bracket-v3.step"), part("2", "housing.sldprt")];
    render(
      <FormProvider config={config}>
        <Seed rows={rows} />
        <StepPartMeta />
      </FormProvider>,
    );

    expect(screen.getByRole("heading", { name: "bracket-v3.step" })).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: "housing.sldprt" })).toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: /^part \d$/i })).not.toBeInTheDocument();

    const card = screen.getByRole("heading", { name: "housing.sldprt" }).parentElement as HTMLElement;
    expect(within(card).getByText("Part 2 of 2")).toBeInTheDocument();
  });

  it("falls back to a numbered title when a part has no file", () => {
    render(
      <FormProvider config={config}>
        <Seed rows={[part("1", null)]} />
        <StepPartMeta />
      </FormProvider>,
    );

    expect(screen.getByRole("heading", { name: "Part 1" })).toBeInTheDocument();
  });

  it("orders fields quantity first and notes last, without threads/features", () => {
    render(
      <FormProvider config={config}>
        <Seed rows={[part("1", "a.step")]} />
        <StepPartMeta />
      </FormProvider>,
    );

    const quantity = screen.getByLabelText("Quantity");
    const material = screen.getByLabelText(/know the exact material/i);
    const notes = screen.getByLabelText("Notes & Details");
    expect(quantity.compareDocumentPosition(material) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(material.compareDocumentPosition(notes) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(screen.queryByLabelText(/threads/i)).not.toBeInTheDocument();
  });

  it("labels the notes field 'Notes & Details' with a tooltip explaining what to include", async () => {
    const user = userEvent.setup();    render(
      <FormProvider config={config}>
        <Seed rows={[{ ...part("1", "a.step"), material: "6061-T6 Aluminum" }]} />
        <StepPartMeta />
      </FormProvider>,
    );

    expect(screen.queryByLabelText("Notes")).not.toBeInTheDocument();
    const notes = screen.getByLabelText("Notes & Details");

    const trigger = screen.getByRole("button", { name: /what to include in notes & details/i });
    const tooltip = screen.getByRole("tooltip");
    expect(trigger).toHaveAttribute("aria-describedby", tooltip.id);
    expect(notes).toHaveAttribute("aria-describedby", tooltip.id);
    expect(tooltip).toHaveTextContent(/anything not covered by material, finish, and global tolerance/i);
    expect(within(tooltip).getAllByRole("listitem").map((item) => item.textContent)).toEqual([
      "Threads or special features",
      "Masking or postprocessing requirements",
      "Inspection or certification needs",
      "Etc.",
    ]);

    // Keyboard users reach the trigger by tabbing from the label's field neighbours: it sits right before the textarea.
    await user.click(screen.getByLabelText(/global tolerance/i));
    await user.tab();
    expect(trigger).toHaveFocus();
    await user.tab();
    expect(notes).toHaveFocus();
  });

  it("hides target price behind a More details toggle placed after notes", async () => {
    const user = userEvent.setup();
    render(
      <FormProvider config={config}>
        <Seed rows={[part("1", "a.step")]} />
        <StepPartMeta />
      </FormProvider>,
    );

    expect(screen.queryByLabelText(/target unit price/i)).not.toBeInTheDocument();
    const toggle = screen.getByRole("button", { name: "More details" });
    expect(toggle).toHaveAttribute("aria-expanded", "false");
    expect(screen.getByLabelText("Notes & Details").compareDocumentPosition(toggle) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();

    await user.click(toggle);
    expect(toggle).toHaveAttribute("aria-expanded", "true");
    expect(screen.getByLabelText(/target unit price/i)).toBeInTheDocument();

    await user.click(toggle);
    expect(screen.queryByLabelText(/target unit price/i)).not.toBeInTheDocument();
  });

  it("shows Global Tolerance options with metal values in inches, then plastic values after picking a plastic", async () => {
    const user = userEvent.setup();
    const plasticConfig: RfqFormConfig = {
      ...config,
      materials: [
        ...config.materials,
        {
          id: "abs",
          label: "ABS",
          aliases: ["abs"],
          show_in_dropdown: false,
          group: "Plastic",
          type: "ABS",
          finishes: ["As-machined"],
          colors: ["Black"],
        },
      ],
    };
    render(
      <FormProvider config={plasticConfig}>
        <Seed rows={[part("1", "a.step")]} />
        <StepPartMeta />
      </FormProvider>,
    );

    // Hidden until a material is completely selected.
    expect(screen.queryByLabelText(/global tolerance/i)).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Metal" }));
    await user.click(screen.getByRole("button", { name: "Aluminum" }));
    await user.click(screen.getByRole("button", { name: "6061-T6 Aluminum" }));

    const tolerance = screen.getByLabelText(/global tolerance/i) as HTMLSelectElement;
    expect(Array.from(tolerance.options).map((option) => option.text)).toEqual([
      'Economy (±0.010")',
      'Standard (±0.005")',
      'Tight (±0.003")',
      "Custom",
    ]);
    expect(screen.queryByText(/Applies to the whole part/)).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Plastic" }));
    expect(screen.queryByLabelText(/global tolerance/i)).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "ABS" }));
    const absButtons = screen.getAllByRole("button", { name: "ABS" });
    await user.click(absButtons[absButtons.length - 1] as HTMLElement);

    const plasticTolerance = screen.getByLabelText(/global tolerance/i) as HTMLSelectElement;
    expect(Array.from(plasticTolerance.options).map((option) => option.text)).toEqual([
      'Economy (±0.020")',
      'Standard (±0.010")',
      'Tight (±0.005")',
      "Custom",
    ]);
  });

  it("gives custom materials a typed tolerance with an inches/millimeters selector", async () => {
    const user = userEvent.setup();
    render(
      <FormProvider config={config}>
        <Seed rows={[part("1", "a.step")]} />
        <StepPartMeta />
      </FormProvider>,
    );

    const continueButton = screen.getByRole("button", { name: "Continue to RFQ details" });
    await user.click(screen.getByRole("button", { name: "Other / custom" }));
    await user.type(screen.getByLabelText("Specify material"), "Customer-supplied brass");

    // No tier dropdown for custom materials; a typed amount with a unit choice instead.
    expect(screen.queryByLabelText(/global tolerance/i, { selector: "select" })).not.toBeInTheDocument();
    const valueInput = screen.getByLabelText(/global tolerance/i, { selector: "input" });
    expect(continueButton).toBeDisabled();

    await user.type(valueInput, "0.1");
    await user.selectOptions(screen.getByLabelText("Tolerance unit"), "mm");
    expect(continueButton).toBeEnabled();
  });

  it("enables Continue after a material is picked through the guided tree", async () => {
    const user = userEvent.setup();
    render(
      <FormProvider config={config}>
        <Seed rows={[part("1", "a.step")]} />
        <StepPartMeta />
      </FormProvider>,
    );

    const continueButton = screen.getByRole("button", { name: "Continue to RFQ details" });
    expect(continueButton).toBeDisabled();

    await user.click(screen.getByRole("button", { name: "Metal" }));
    await user.click(screen.getByRole("button", { name: "Aluminum" }));
    await user.click(screen.getByRole("button", { name: "6061-T6 Aluminum" }));

    expect(continueButton).toBeEnabled();
  });

  it("starts expanded when a target price is already set", () => {
    render(
      <FormProvider config={config}>
        <Seed rows={[{ ...part("1", "a.step"), target_unit_price: 12.5 }]} />
        <StepPartMeta />
      </FormProvider>,
    );

    expect(screen.getByLabelText(/target unit price/i)).toHaveValue(12.5);
  });

  // @covers AC-WP-036
  it("goes back to uploads even before the part details are complete", async () => {
    const user = userEvent.setup();

    function StepProbe() {
      const { step } = useForm();
      return <p data-testid="step">{step}</p>;
    }

    render(
      <FormProvider config={config}>
        <Seed rows={[part("1", "a.step")]} />
        <StepPartMeta />
        <StepProbe />
      </FormProvider>,
    );

    expect(screen.getByRole("button", { name: "Continue to RFQ details" })).toBeDisabled();
    await user.click(screen.getByRole("button", { name: "Back" }));
    expect(screen.getByTestId("step")).toHaveTextContent("uploads");
  });
});
