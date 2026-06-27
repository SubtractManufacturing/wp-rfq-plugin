import { describe, expect, it } from "vitest";
import { buildDraftPayload } from "./draft";
import { emptyContact, emptyGlobal, type PartRow } from "../types/manifest";

describe("buildDraftPayload", () => {
  // @covers AC-WP-015
  it("keeps file keys but strips upload URLs and browser File objects", () => {
    const parts: PartRow[] = [
      {
        part_id: "part-1",
        partFile: {
          file_key: "intake/session/parts/file.step",
          filename: "file.step",
          content_type: "application/octet-stream",
          status: "confirmed",
          progress: 100,
          upload_url: "https://s3.test/upload",
          sourceFile: new File(["cad"], "file.step"),
        },
        drawings: [],
        material: "",
        tolerance: "standard",
        tolerance_detail: null,
        threads_features: null,
        quantity: 1,
        target_unit_price: null,
        notes: null,
      },
    ];

    expect(buildDraftPayload("session", emptyContact, parts, emptyGlobal)).toEqual({
      session_id: "session",
      contact: emptyContact,
      parts: [
        expect.not.objectContaining({
          upload_url: expect.any(String),
          sourceFile: expect.any(File),
        }),
      ],
      global: emptyGlobal,
    });
  });
});
