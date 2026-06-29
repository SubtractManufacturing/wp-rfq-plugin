import {
  AsYouType,
  type CountryCode,
  getCountries,
  getCountryCallingCode,
  isValidPhoneNumber,
  parsePhoneNumberFromString,
} from "libphonenumber-js";

export type { CountryCode };

export interface CountryOption {
  code: CountryCode;
  label: string;
  callingCode: string;
}

const defaultRegionDisplayNames = new Intl.DisplayNames(["en"], { type: "region" });

export function createCountryOptions(
  regionDisplayNames: Pick<Intl.DisplayNames, "of"> = defaultRegionDisplayNames,
): CountryOption[] {
  return getCountries()
    .map((code) => ({
      code,
      label: regionDisplayNames.of(code) ?? code,
      callingCode: getCountryCallingCode(code),
    }))
    .sort((left, right) => left.label.localeCompare(right.label));
}

export function getCountryOptions(): CountryOption[] {
  return createCountryOptions();
}

export function formatPhoneDisplay(value: string, country: CountryCode): string {
  if (value.trim() === "") {
    return "";
  }

  return new AsYouType(country).input(value);
}

export function getPhoneValidationError(display: string, country: CountryCode): string | null {
  if (display.trim() === "") {
    return null;
  }

  const parsed = parsePhoneNumberFromString(display, country);
  if (!parsed || !isValidPhoneNumber(parsed.number, country)) {
    return "Enter a valid phone number for the selected country.";
  }

  return null;
}

export function normalizePhone(
  display: string,
  country: CountryCode,
): { phone: string | null; phone_country_code: string | null } {
  if (display.trim() === "") {
    return { phone: null, phone_country_code: null };
  }

  const parsed = parsePhoneNumberFromString(display, country);
  if (!parsed || !isValidPhoneNumber(parsed.number, country)) {
    return { phone: null, phone_country_code: null };
  }

  return {
    phone: parsed.nationalNumber,
    phone_country_code: String(parsed.countryCallingCode),
  };
}

export function formatPhoneSummary(display: string, country: CountryCode): string {
  const normalized = normalizePhone(display, country);
  if (!normalized.phone || !normalized.phone_country_code) {
    return display;
  }

  if (normalized.phone_country_code === "1" && normalized.phone.length === 10) {
    return `+1 (${normalized.phone.slice(0, 3)}) ${normalized.phone.slice(3, 6)}-${normalized.phone.slice(6)}`;
  }

  return `+${normalized.phone_country_code} ${normalized.phone}`;
}
