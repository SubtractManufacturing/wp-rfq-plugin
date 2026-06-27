import { describe, expect, it } from "vitest";
import { formatPhone, normalizePhone } from "./phone";

describe("phone helpers", () => {
  it("normalizes a formatted US phone number to ten digits", () => {
    expect(normalizePhone("+1 (555) 555-0100")).toEqual({
      phone: "5555550100",
      phone_country_code: "1",
    });
  });

  it("formats typed digits for display", () => {
    expect(formatPhone("5555550100")).toBe("(555) 555-0100");
  });
});
