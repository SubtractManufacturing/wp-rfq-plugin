import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { describe, expect, it } from "vitest";
import type { MaterialOption } from "../types/config";
import type { PartRow } from "../types/manifest";
import { MaterialPicker } from "./MaterialPicker";

type MaterialPatch = Pick<PartRow, "material" | "finish" | "finish_color" | "finish_specification" | "plastic_color">;

const catalog: MaterialOption[] = [
  {
    id: "6061-t6-aluminum",
    label: "6061-T6 Aluminum",
    aliases: ["6061"],
    show_in_dropdown: true,
    group: "Metal",
    type: "Aluminum",
    guidance: "The general-purpose default.",
    finishes: ["As-machined", "Anodize Type II", "Other: specify"],
  },
  {
    id: "abs",
    label: "ABS",
    aliases: ["abs plastic"],
    show_in_dropdown: false,
    group: "Plastic",
    type: "ABS",
    guidance: "Low-cost and tough.",
    finishes: ["As-machined", "Other: specify"],
    colors: ["Black", "White"],
  },
];

function Harness({ materials = catalog, initial }: { materials?: MaterialOption[]; initial?: Partial<MaterialPatch> }) {
  const [value, setValue] = useState<MaterialPatch>({
    material: "",
    finish: null,
    finish_color: null,
    finish_specification: null,
    plastic_color: null,
    ...initial,
  });
  return (
    <>
      <MaterialPicker
        idPrefix="material-part-1"
        materials={materials}
        onChange={(patch) => setValue((current) => ({ ...current, ...patch }))}
        value={value}
      />
      <p data-testid="material">{value.material}</p>
      <p data-testid="finish">{value.finish ?? ""}</p>
      <p data-testid="color">{value.plastic_color ?? ""}</p>
    </>
  );
}

// @covers AC-WP-034
describe("MaterialPicker", () => {
  it("walks group, type, and alloy, then defaults the finish to As-machined", async () => {
    const user = userEvent.setup();
    render(<Harness />);

    expect(screen.queryByRole("button", { name: "Aluminum" })).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Metal" }));
    await user.click(screen.getByRole("button", { name: "Aluminum" }));
    await user.click(screen.getByRole("button", { name: "6061-T6 Aluminum" }));

    expect(screen.getByTestId("material")).toHaveTextContent("6061-T6 Aluminum");
    expect(screen.getByTestId("finish")).toHaveTextContent("As-machined");
    expect(screen.getByText("The general-purpose default.")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Anodize Type II" })).toHaveAttribute("aria-pressed", "false");
  });

  it("jumps straight to a material from search", async () => {
    const user = userEvent.setup();
    render(<Harness />);

    await user.type(screen.getByLabelText(/know the exact material/i), "6061");
    await user.click(screen.getByRole("button", { name: /6061-T6 Aluminum/ }));

    expect(screen.getByTestId("material")).toHaveTextContent("6061-T6 Aluminum");
    expect(screen.getByRole("button", { name: "Metal" })).toHaveAttribute("aria-pressed", "true");
  });

  it("offers stock colors only for plastics that have them, with No preference first", async () => {
    const user = userEvent.setup();
    render(<Harness />);

    await user.click(screen.getByRole("button", { name: "Plastic" }));
    await user.click(screen.getByRole("button", { name: "ABS" }));
    // Single-leaf type: the alloy step still confirms the pick.
    await user.click(within(screen.getByRole("group", { name: /Alloy \/ grade/ })).getByRole("button", { name: "ABS" }));

    const colorGroup = screen.getByRole("group", { name: /Plastic color/ });
    expect(within(colorGroup).getByRole("button", { name: "No preference" })).toHaveAttribute("aria-pressed", "true");
    await user.click(within(colorGroup).getByRole("button", { name: "Black" }));
    expect(screen.getByTestId("color")).toHaveTextContent("Black");
  });

  it("asks for a specification when the Other finish is chosen", async () => {
    const user = userEvent.setup();
    render(<Harness initial={{ material: "6061-T6 Aluminum", finish: "As-machined" }} />);

    await user.click(screen.getByRole("button", { name: "Other: specify" }));
    expect(screen.getByLabelText("Specify finish")).toBeInTheDocument();
  });

  it("keeps a free-text material through Other / custom", async () => {
    const user = userEvent.setup();
    render(<Harness />);

    await user.click(screen.getByRole("button", { name: "Other / custom" }));
    await user.type(screen.getByLabelText("Specify material"), "Customer-supplied brass");

    expect(screen.getByTestId("material")).toHaveTextContent("Customer-supplied brass");
    expect(screen.queryByText("Alloy / grade")).not.toBeInTheDocument();
  });

  it("falls back to a plain material input when the catalog is empty", async () => {
    const user = userEvent.setup();
    render(<Harness materials={[]} />);

    await user.type(screen.getByLabelText("Material"), "6061 Aluminum");
    expect(screen.getByTestId("material")).toHaveTextContent("6061 Aluminum");
  });
});
