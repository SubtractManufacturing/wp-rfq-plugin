import { describe, expect, it } from "vitest";
import { buildManifest } from "./manifest";
import { emptyContact, emptyGlobal, type PartRow } from "../types/manifest";

describe("buildManifest", () => {
  // @covers AC-WP-004
  // @covers AC-WP-013
  it("builds the server manifest from confirmed files only", () => {
    const part: PartRow = {
      part_id: "part-1",
      partFile: {
        file_key: "intake/session/parts/file.step",
        filename: "file.step",
        content_type: "application/octet-stream",
        status: "confirmed",
        progress: 100,
      },
      drawings: [],
      material: "1018 Steel",
      tolerance: "standard",
      tolerance_detail: null,
      threads_features: null,
      quantity: 2,
      target_unit_price: null,
      notes: null,
    };

    expect(
      buildManifest("session", { ...emptyContact, first_name: "Jane", last_name: "Smith", email: "jane@example.com" }, [part], {
        ...emptyGlobal,
        required_delivery_date: "2026-08-01",
        lead_time_preference: "standard",
        shipping_destination: { postal_code: "90210" },
      }),
    ).toMatchObject({
      session_id: "session",
      parts: [{ part_file_key: "intake/session/parts/file.step", material: "1018 Steel", quantity: 2 }],
      global: { lead_time_preference: "standard" },
    });
  });
});
