export function isValidPostalCode(value: string): boolean {
  const code = value.trim().toUpperCase();
  const compact = code.replace(/\s/g, "");
  return /^\d{5}(-?\d{4})?$/.test(code) || /^[A-Z]\d[A-Z]\d[A-Z]\d$/.test(compact);
}
