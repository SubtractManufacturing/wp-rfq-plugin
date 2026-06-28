import { describe, expect, it } from "vitest";
import {
  createCountryOptions,
  formatPhoneDisplay,
  formatPhoneSummary,
  getCountryOptions,
  getPhoneValidationError,
  normalizePhone,
} from "./phone";

describe("phone helpers", () => {
  it("returns null phone values for empty input", () => {
    expect(normalizePhone("", "US")).toEqual({
      phone: null,
      phone_country_code: null,
    });
    expect(getPhoneValidationError("", "US")).toBeNull();
  });

  it("normalizes a valid US phone number", () => {
    expect(normalizePhone("(202) 555-0105", "US")).toEqual({
      phone: "2025550105",
      phone_country_code: "1",
    });
    expect(getPhoneValidationError("(202) 555-0105", "US")).toBeNull();
  });

  it("rejects an invalid US phone number", () => {
    expect(getPhoneValidationError("(555) 555-0100", "US")).toMatch(/valid phone number/i);
    expect(normalizePhone("(555) 555-0100", "US")).toEqual({
      phone: null,
      phone_country_code: null,
    });
  });

  it("normalizes a valid UK phone number", () => {
    expect(normalizePhone("07911 123456", "GB")).toEqual({
      phone: "7911123456",
      phone_country_code: "44",
    });
    expect(getPhoneValidationError("07911 123456", "GB")).toBeNull();
  });

  it("formats phone numbers as the user types", () => {
    expect(formatPhoneDisplay("2025550105", "US")).toBe("(202) 555-0105");
    expect(formatPhoneDisplay("", "US")).toBe("");
  });

  it("formats phone summaries for review", () => {
    expect(formatPhoneSummary("(202) 555-0105", "US")).toBe("+1 (202) 555-0105");
    expect(formatPhoneSummary("07911 123456", "GB")).toBe("+44 7911123456");
    expect(formatPhoneSummary("invalid", "US")).toBe("invalid");
  });

  it("lists countries with labels and calling codes", () => {
    const options = getCountryOptions();
    expect(options.some((option) => option.code === "US" && option.callingCode === "1")).toBe(true);
    expect(options.some((option) => option.code === "GB" && option.callingCode === "44")).toBe(true);
    expect(options[0]?.label.localeCompare(options[1]?.label ?? "") ?? 0).toBeLessThanOrEqual(0);
  });

  it("falls back to the ISO code when a region label is unavailable", () => {
    const options = createCountryOptions({
      of: () => undefined,
    });

    expect(options.some((option) => option.code === "US" && option.label === "US")).toBe(true);
  });

  it("rejects invalid phone numbers without a parsed value", () => {
    expect(getPhoneValidationError("123", "US")).toMatch(/valid phone number/i);
  });
});
