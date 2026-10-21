import { describe, expect, it } from "vitest";
import type { MaterialOption } from "../types/config";
import {
  customToleranceDisplay,
  manifestToleranceForPart,
  normalizeTolerance,
  parseToleranceValue,
  toleranceCategoryForMaterial,
  toleranceDetailInInches,
  toleranceLabel,
  toleranceTierLabel,
} from "./tolerances";

const catalog: MaterialOption[] = [
  { id: "6061", label: "6061-T6 Aluminum", aliases: ["6061"], show_in_dropdown: true, group: "Metal", type: "Aluminum" },
  { id: "abs", label: "ABS", aliases: ["abs"], show_in_dropdown: false, group: "Plastic", type: "ABS" },
];

// @covers AC-WP-034
describe("global tolerance values", () => {
  it("shows metal values in inches for metal materials", () => {
    expect(toleranceTierLabel("economy", "metal")).toBe('Economy (±0.010")');
    expect(toleranceTierLabel("standard", "metal")).toBe('Standard (±0.005")');
    expect(toleranceTierLabel("tight", "metal")).toBe('Tight (±0.003")');
  });

  it("shows plastic values in inches for plastic materials", () => {
    expect(toleranceTierLabel("economy", "plastic")).toBe('Economy (±0.020")');
    expect(toleranceTierLabel("standard", "plastic")).toBe('Standard (±0.010")');
    expect(toleranceTierLabel("tight", "plastic")).toBe('Tight (±0.005")');
  });

  it("picks the category from the catalog group, then custom-text keywords", () => {
    expect(toleranceCategoryForMaterial("6061-T6 Aluminum", catalog)).toBe("metal");
    expect(toleranceCategoryForMaterial("ABS", catalog)).toBe("plastic");
    expect(toleranceCategoryForMaterial("Customer-supplied nylon bar", catalog)).toBe("plastic");
    expect(toleranceCategoryForMaterial("Customer-supplied brass", catalog)).toBe("metal");
    expect(toleranceCategoryForMaterial("", catalog)).toBe("metal");
  });

  it("converts a typed custom tolerance to inches for the manifest, keeping the typed value for display", () => {
    expect(parseToleranceValue("0.005")).toBe(0.005);
    expect(parseToleranceValue("±0.005")).toBe(0.005);
    expect(parseToleranceValue("+/-0.005")).toBe(0.005);
    expect(parseToleranceValue("")).toBeNull();
    expect(parseToleranceValue("abc")).toBeNull();
    expect(parseToleranceValue("0")).toBeNull();
    expect(toleranceDetailInInches("0.005", "in")).toBe('+/- 0.005"');
    expect(toleranceDetailInInches("0.1", "mm")).toBe('+/- 0.00394"');
    expect(toleranceDetailInInches("1", "mm")).toBe('+/- 0.03937"');
    expect(customToleranceDisplay("0.1", "mm")).toBe("±0.1 mm");
    expect(customToleranceDisplay("0.005", "in")).toBe('±0.005"');
  });

  it("builds the v2 manifest tolerance as the actual value, never a tier label", () => {
    expect(manifestToleranceForPart({ tolerance: "standard", material: "6061-T6 Aluminum" }, catalog)).toBe('+/- 0.005"');
    expect(manifestToleranceForPart({ tolerance: "economy", material: "ABS" }, catalog)).toBe('+/- 0.020"');
    expect(manifestToleranceForPart({ tolerance: "tight", material: "ABS" }, catalog)).toBe('+/- 0.005"');
    expect(manifestToleranceForPart({ tolerance: "precision", material: "6061-T6 Aluminum" }, catalog)).toBe('+/- 0.003"');
    expect(
      manifestToleranceForPart({ tolerance: "custom", tolerance_detail: "±0.001 on all diameters", material: "ABS" }, catalog),
    ).toBe("±0.001 on all diameters");
    expect(
      manifestToleranceForPart(
        { tolerance: "custom", tolerance_value: "0.1", tolerance_unit: "mm", material: "Customer-supplied brass" },
        catalog,
      ),
    ).toBe('+/- 0.00394"');
  });

  it("labels a part tolerance and reads legacy precision as tight", () => {
    expect(toleranceLabel("standard", "ABS", catalog)).toBe('Standard (±0.010")');
    expect(toleranceLabel("precision", "6061-T6 Aluminum", catalog)).toBe('Tight (±0.003")');
    expect(toleranceLabel("custom", "ABS", catalog)).toBe("Custom");
    expect(normalizeTolerance("precision")).toBe("tight");
  });
});
