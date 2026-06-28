import { describe, expect, it } from "vitest";
import { canReachStep } from "./navigation";
import type { PartRow } from "../types/manifest";

const confirmedPart: PartRow = {
  part_id: "part-1",
  partFile: {
    file_key: "key",
    filename: "part.step",
    content_type: "application/octet-stream",
    status: "confirmed",
    progress: 100,
  },
  drawings: [],
  material: "",
  tolerance: "standard",
  tolerance_detail: null,
  threads_features: null,
  quantity: 1,
  target_unit_price: null,
  notes: null,
};

describe("canReachStep", () => {
  it("blocks uploads until contact is saved", () => {
    expect(canReachStep("contact", false, [])).toBe(true);
    expect(canReachStep("uploads", false, [])).toBe(false);
    expect(canReachStep("uploads", true, [])).toBe(true);
  });

  it("blocks part metadata until a part file is confirmed", () => {
    expect(canReachStep("partMeta", true, [])).toBe(false);
    expect(canReachStep("partMeta", true, [confirmedPart])).toBe(true);
  });
});
