import { describe, expect, it } from "vitest";
import { partDisplayName, partLabelFromFilename } from "./partLabel";
import type { PartRow } from "../types/manifest";

function partRow(overrides: Partial<PartRow> = {}): PartRow {
  return {
    part_id: "part-1",
    partFile: null,
    drawings: [],
    material: "",
    tolerance: "standard",
    tolerance_detail: null,
    threads_features: null,
    quantity: 1,
    target_unit_price: null,
    notes: null,
    ...overrides,
  };
}

describe("partLabelFromFilename", () => {
  it("strips common CAD extensions", () => {
    expect(partLabelFromFilename("fixture-bracket.step")).toBe("fixture-bracket");
    expect(partLabelFromFilename("bracket.sldprt")).toBe("bracket");
    expect(partLabelFromFilename("part.iges")).toBe("part");
  });

  it("keeps multi-dot basenames", () => {
    expect(partLabelFromFilename("my.file.name.dwg")).toBe("my.file.name");
  });

  it("handles path segments", () => {
    expect(partLabelFromFilename("uploads/fixture-bracket.step")).toBe("fixture-bracket");
  });
});

describe("partDisplayName", () => {
  it("uses confirmed part filename when available", () => {
    const part = partRow({
      partFile: {
        file_key: "key",
        filename: "fixture-bracket.step",
        content_type: "application/octet-stream",
        status: "confirmed",
        progress: 100,
      },
    });

    expect(partDisplayName(part, 0)).toBe("fixture-bracket");
  });

  it("falls back to positional label before upload", () => {
    expect(partDisplayName(partRow(), 2)).toBe("Part 3");
  });
});
