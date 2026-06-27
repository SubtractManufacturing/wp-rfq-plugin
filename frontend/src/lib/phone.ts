export function digitsOnly(value: string): string {
  return value.replace(/\D/g, "").slice(0, 10);
}

export function formatPhone(value: string): string {
  const digits = digitsOnly(value);
  if (digits.length <= 3) {
    return digits;
  }
  if (digits.length <= 6) {
    return `(${digits.slice(0, 3)}) ${digits.slice(3)}`;
  }
  return `(${digits.slice(0, 3)}) ${digits.slice(3, 6)}-${digits.slice(6)}`;
}

export function normalizePhone(value: string): { phone: string | null; phone_country_code: "1" | null } {
  const rawDigits = value.replace(/\D/g, "");
  const digits = rawDigits.length === 11 && rawDigits.startsWith("1") ? rawDigits.slice(1) : digitsOnly(value);
  if (digits === "") {
    return { phone: null, phone_country_code: null };
  }
  return { phone: digits, phone_country_code: "1" };
}
