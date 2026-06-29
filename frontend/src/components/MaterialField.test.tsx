import { useState } from "react";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";
import { MaterialField } from "./MaterialField";

const materials = [
  { id: "1018-steel", label: "1018 Steel", aliases: ["1018"], show_in_dropdown: true },
  { id: "6061-aluminum", label: "6061 Aluminum", aliases: ["6061"], show_in_dropdown: true },
];

function MaterialFieldHarness() {
  const [value, setValue] = useState("");

  return (
    <MaterialField
      id="material-part-1"
      label="Material"
      materials={materials}
      onChange={setValue}
      value={value}
    />
  );
}

describe("MaterialField", () => {
  it("shows dropdown suggestions on focus and selects a material", async () => {
    const user = userEvent.setup();

    render(<MaterialFieldHarness />);

    const input = screen.getByRole("combobox", { name: "Material" });
    await user.click(input);

    expect(screen.getByRole("listbox")).toBeInTheDocument();
    expect(screen.getByRole("option", { name: "1018 Steel" })).toBeInTheDocument();

    await user.click(screen.getByRole("option", { name: "6061 Aluminum" }));

    expect(input).toHaveValue("6061 Aluminum");
    expect(screen.queryByRole("listbox")).not.toBeInTheDocument();
  });

  it("filters suggestions while typing and allows custom values", async () => {
    const user = userEvent.setup();

    render(<MaterialFieldHarness />);

    const input = screen.getByRole("combobox", { name: "Material" });
    await user.type(input, "Custom alloy");

    expect(input).toHaveValue("Custom alloy");
    expect(screen.queryByRole("option", { name: "1018 Steel" })).not.toBeInTheDocument();
  });
});
