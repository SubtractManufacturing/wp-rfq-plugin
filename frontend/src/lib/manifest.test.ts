import { describe, expect, it } from "vitest";
import { buildManifest } from "./manifest";
import { emptyContact, emptyGlobal, type PartRow } from "../types/manifest";

const confirmedPart: PartRow = {
  part_id: "part-1",
  partFile: {
    file_key: "intake/session/parts/file.step",
    filename: "file.step",
    content_type: "application/octet-stream",
    status: "confirmed",
    progress: 100,
  },
  drawings: [
    {
      file_key: "intake/session/drawings/file.pdf",
      filename: "file.pdf",
      content_type: "application/pdf",
      status: "confirmed",
      progress: 100,
    },
  ],
  material: "1018 Steel",
  tolerance: "standard",
  tolerance_detail: null,
  threads_features: null,
  quantity: 2,
  target_unit_price: null,
  notes: null,
};

describe("buildManifest", () => {
  // @covers AC-WP-004
  // @covers AC-WP-013
  it("builds the server manifest from confirmed files only", () => {
    expect(
      buildManifest("session", { ...emptyContact, first_name: "Jane", last_name: "Smith", email: "jane@example.com", company: "Acme" }, [confirmedPart], {
        ...emptyGlobal,
        required_delivery_date: "2026-08-01",
        lead_time_preference: "standard",
        shipping_destination: { postal_code: "90210" },
      }),
    ).toMatchObject({
      session_id: "session",
      parts: [{ part_file_key: "intake/session/parts/file.step", drawing_file_keys: ["intake/session/drawings/file.pdf"], material: "1018 Steel", quantity: 2 }],
      global: { lead_time_preference: "standard" },
    });
  });

  it("includes international phone metadata in the contact section", () => {
    expect(
      buildManifest(
        "session",
        {
          ...emptyContact,
          first_name: "Jane",
          last_name: "Smith",
          email: "jane@example.com",
          phone: "07911 123456",
          phone_country: "GB",
          phone_country_code: "44",
        },
        [],
        emptyGlobal,
      ).contact,
    ).toEqual({
      first_name: "Jane",
      last_name: "Smith",
      email: "jane@example.com",
      company: null,
      phone: "7911123456",
      phone_country_code: "44",
      job_title: null,
    });
  });

  it("omits unconfirmed parts and keeps custom tolerance details", () => {
    const customPart: PartRow = {
      ...confirmedPart,
      part_id: "part-2",
      partFile: {
        file_key: "",
        filename: "pending.step",
        content_type: "application/octet-stream",
        status: "pending",
        progress: 0,
      },
      tolerance: "custom",
      tolerance_detail: "±0.001 in",
    };

    const manifest = buildManifest(
      "session",
      emptyContact,
      [confirmedPart, customPart],
      emptyGlobal,
    );

    expect(manifest.parts).toHaveLength(1);
    expect(manifest.parts[0]?.tolerance_detail).toBeNull();

    const customOnly = buildManifest(
      "session",
      emptyContact,
      [{ ...customPart, partFile: confirmedPart.partFile }],
      emptyGlobal,
    );
    expect(customOnly.parts[0]?.tolerance_detail).toBe("±0.001 in");
  });
});
