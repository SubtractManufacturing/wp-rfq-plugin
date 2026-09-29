import { render, screen, within } from "@testing-library/react";
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
  materials: [],
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
});
