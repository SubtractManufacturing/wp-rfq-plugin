import { describe, expect, it } from "vitest";
import { searchMaterials } from "./materials";

describe("searchMaterials", () => {
  it("matches material labels and aliases", () => {
    const results = searchMaterials("1018", [
      { id: "1018-steel", label: "1018 Steel", aliases: ["mild"], show_in_dropdown: true },
    ]);

    expect(results).toEqual(["1018 Steel"]);
  });
});
