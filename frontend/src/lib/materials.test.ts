import { describe, expect, it } from "vitest";
import { materialSuggestions, searchMaterials } from "./materials";

const catalog = [
  { id: "1018-steel", label: "1018 Steel", aliases: ["mild"], show_in_dropdown: true },
  { id: "ti-grade-5", label: "Grade 5 Titanium", aliases: ["ti-6al-4v"], show_in_dropdown: false },
];

describe("searchMaterials", () => {
  it("matches material labels and aliases", () => {
    const results = searchMaterials("1018", catalog);

    expect(results).toEqual(["1018 Steel"]);
  });
});

describe("materialSuggestions", () => {
  it("returns dropdown materials when the query is empty", () => {
    expect(materialSuggestions("", catalog)).toEqual(["1018 Steel"]);
  });

  it("returns type-ahead matches when the query is non-empty", () => {
    expect(materialSuggestions("ti-6al", catalog)).toEqual(["Grade 5 Titanium"]);
  });
});
