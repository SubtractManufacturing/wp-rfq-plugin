import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { describe, expect, it } from "vitest";
import { dropdownMaterials, searchMaterials } from "../lib/materials";
import type { MaterialOption } from "../types/config";
import { Combobox } from "./Combobox";

const materials: MaterialOption[] = [
  { id: "1018-steel", label: "1018 Steel", aliases: ["1018"], show_in_dropdown: true },
  { id: "6061-aluminum", label: "6061 Aluminum", aliases: ["6061"], show_in_dropdown: true },
  { id: "inconel", label: "Inconel 718", aliases: ["718"], show_in_dropdown: false },
];

function Harness({ initial = "" }: { initial?: string }) {
  const [value, setValue] = useState(initial);
  return (
    <>
      <label htmlFor="material">Material</label>
      <Combobox
        defaultOptions={dropdownMaterials(materials)}
        id="material"
        onChange={setValue}
        search={(query) => searchMaterials(query, materials)}
        value={value}
      />
      <p data-testid="value">{value}</p>
    </>
  );
}

describe("Combobox", () => {
  it("opens the common options from the chevron and fills the field when one is chosen", async () => {
    const user = userEvent.setup();
    render(<Harness />);

    expect(screen.queryByRole("listbox")).not.toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /show common options/i }));

    const options = screen.getAllByRole("option").map((option) => option.textContent);
    expect(options).toEqual(["1018 Steel", "6061 Aluminum"]);

    await user.click(screen.getByRole("option", { name: "6061 Aluminum" }));
    expect(screen.getByRole("combobox", { name: "Material" })).toHaveValue("6061 Aluminum");
    expect(screen.queryByRole("listbox")).not.toBeInTheDocument();
  });

  it("shows the common options again after a value is chosen, not just the match", async () => {
    const user = userEvent.setup();
    render(<Harness initial="1018 Steel" />);

    await user.click(screen.getByRole("button", { name: /show common options/i }));
    expect(screen.getAllByRole("option")).toHaveLength(2);
    expect(screen.getByRole("option", { name: "1018 Steel" })).toHaveAttribute("aria-selected", "true");
  });

  it("filters by label and alias while typing, including entries not in the common list", async () => {
    const user = userEvent.setup();
    render(<Harness />);

    await user.type(screen.getByRole("combobox", { name: "Material" }), "718");
    expect(screen.getAllByRole("option").map((option) => option.textContent)).toEqual(["Inconel 718"]);
  });

  it("keeps whatever the customer typed when nothing matches", async () => {
    const user = userEvent.setup();
    render(<Harness />);

    await user.type(screen.getByRole("combobox", { name: "Material" }), "Customer-supplied brass");
    expect(screen.queryByRole("listbox")).not.toBeInTheDocument();
    expect(screen.getByTestId("value")).toHaveTextContent("Customer-supplied brass");
  });

  it("supports keyboard selection and Escape", async () => {
    const user = userEvent.setup();
    render(<Harness />);

    const input = screen.getByRole("combobox", { name: "Material" });
    await user.click(input);
    await user.keyboard("{ArrowDown}{ArrowDown}{Enter}");
    expect(input).toHaveValue("6061 Aluminum");

    await user.keyboard("{ArrowDown}");
    expect(screen.getByRole("listbox")).toBeInTheDocument();
    await user.keyboard("{Escape}");
    expect(screen.queryByRole("listbox")).not.toBeInTheDocument();
    expect(input).toHaveValue("6061 Aluminum");
  });

  it("closes when focus leaves the control", async () => {
    const user = userEvent.setup();
    render(
      <>
        <Harness />
        <button type="button">Elsewhere</button>
      </>,
    );

    await user.click(screen.getByRole("button", { name: /show common options/i }));
    expect(screen.getByRole("listbox")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Elsewhere" }));
    expect(screen.queryByRole("listbox")).not.toBeInTheDocument();
  });
});
