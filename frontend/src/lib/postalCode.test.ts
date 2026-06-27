import { describe, expect, it } from "vitest";
import { isValidPostalCode } from "./postalCode";

describe("isValidPostalCode", () => {
  it("accepts US ZIP and Canadian postal codes", () => {
    expect(isValidPostalCode("90210")).toBe(true);
    expect(isValidPostalCode("90210-1234")).toBe(true);
    expect(isValidPostalCode("K1A 0B1")).toBe(true);
  });

  it("rejects unsupported postal codes", () => {
    expect(isValidPostalCode("SW1A 1AA")).toBe(false);
  });
});
