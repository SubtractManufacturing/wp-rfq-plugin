import { describe, expect, it } from "vitest";
import type { MaterialOption } from "../types/config";
import {
  defaultFinish,
  findMaterialByLabel,
  finishNeedsColor,
  isOtherFinish,
  materialGroups,
  materialTypes,
  materialsIn,
  searchMaterialEntries,
  searchMaterials,
} from "./materials";

const catalog: MaterialOption[] = [
  {
    id: "6061-t6-aluminum",
    label: "6061-T6 Aluminum",
    aliases: ["6061"],
    show_in_dropdown: true,
    group: "Metal",
    type: "Aluminum",
    finishes: ["As-machined", "Anodize Type II"],
    colors: [],
  },
  {
    id: "1018-steel",
    label: "1018 Steel",
    aliases: ["1018"],
    show_in_dropdown: true,
    group: "Metal",
    type: "Carbon steel",
    finishes: ["As-machined", "Black oxide"],
  },
  {
    id: "abs",
    label: "ABS",
    aliases: ["abs plastic"],
    show_in_dropdown: false,
    group: "Plastic",
    type: "ABS",
    finishes: ["As-machined"],
    colors: ["Black", "White"],
  },
  { id: "flat-added", label: "Inconel 718", aliases: ["718"], show_in_dropdown: false },
];

describe("searchMaterials", () => {
  it("matches material labels and aliases", () => {
    const results = searchMaterials("1018", [
      { id: "1018-steel", label: "1018 Steel", aliases: ["mild"], show_in_dropdown: true },
    ]);

    expect(results).toEqual(["1018 Steel"]);
  });
});

describe("material tree helpers", () => {
  it("lists groups and types in catalog order", () => {
    expect(materialGroups(catalog)).toEqual(["Metal", "Plastic"]);
    expect(materialTypes(catalog, "Metal")).toEqual(["Aluminum", "Carbon steel"]);
    expect(materialsIn(catalog, "Metal", "Aluminum").map((entry) => entry.label)).toEqual(["6061-T6 Aluminum"]);
  });

  it("finds entries by label regardless of case", () => {
    expect(findMaterialByLabel("abs", catalog)?.id).toBe("abs");
    expect(findMaterialByLabel("unobtainium", catalog)).toBeNull();
  });

  it("searches label, aliases, group, and type", () => {
    expect(searchMaterialEntries("718", catalog).map((entry) => entry.label)).toEqual(["Inconel 718"]);
    expect(searchMaterialEntries("plastic", catalog).map((entry) => entry.label)).toEqual(["ABS"]);
  });

  it("defaults finish to As-machined and keeps a still-valid finish", () => {
    expect(defaultFinish(catalog[0], null)).toBe("As-machined");
    expect(defaultFinish(catalog[0], "Anodize Type II")).toBe("Anodize Type II");
    expect(defaultFinish(catalog[0], "Black oxide")).toBe("As-machined");
    expect(defaultFinish(null, "As-machined")).toBeNull();
  });

  it("flags finishes that need a color and the Other finish", () => {
    expect(finishNeedsColor("Powder coat")).toBe(true);
    expect(finishNeedsColor("As-machined")).toBe(false);
    expect(isOtherFinish("Other: specify")).toBe(true);
    expect(isOtherFinish("As-machined")).toBe(false);
  });
});
