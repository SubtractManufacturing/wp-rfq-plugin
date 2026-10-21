import type { MaterialOption } from "../types/config";
import type { Tolerance } from "../types/manifest";
import { findMaterialByLabel } from "./materials";

export type ToleranceCategory = "metal" | "plastic";
export type ToleranceTier = "economy" | "standard" | "tight";

/** Global tolerance amounts in inches (numeric part only), by material category and tier. */
export const TOLERANCE_INCHES: Record<ToleranceCategory, Record<ToleranceTier, string>> = {
  metal: { economy: "0.010", standard: "0.005", tight: "0.003" },
  plastic: { economy: "0.020", standard: "0.010", tight: "0.005" },
};

/** Global tolerance values in inches, by material category and tier. */
export const TOLERANCE_VALUES: Record<ToleranceCategory, Record<ToleranceTier, string>> = {
  metal: { economy: '±0.010"', standard: '±0.005"', tight: '±0.003"' },
  plastic: { economy: '±0.020"', standard: '±0.010"', tight: '±0.005"' },
};

const PLASTIC_KEYWORDS = [
  "plastic",
  "abs",
  "nylon",
  "acetal",
  "delrin",
  "polycarbonate",
  "lexan",
  "acrylic",
  "pmma",
  "plexiglass",
  "peek",
  "ptfe",
  "teflon",
  "uhmw",
  "hdpe",
  "polyethylene",
  "pvc",
  "ultem",
  "pei",
];

/**
 * Whether global tolerance values should use the metal or plastic table.
 * Catalog materials know their group; custom text falls back to keyword
 * matching, then metal (the tighter, safer default to quote against).
 */
export function toleranceCategoryForMaterial(material: string, materials: MaterialOption[]): ToleranceCategory {
  const leaf = findMaterialByLabel(material, materials);
  if (leaf?.group === "Plastic") {
    return "plastic";
  }
  if (leaf?.group === "Metal") {
    return "metal";
  }
  const text = material.toLowerCase();
  return PLASTIC_KEYWORDS.some((keyword) => text.includes(keyword)) ? "plastic" : "metal";
}

/** Legacy "precision" selections read as the tight tier. */
export function normalizeTolerance(tolerance: Tolerance): ToleranceTier | "custom" {
  return tolerance === "precision" ? "tight" : tolerance;
}

const TIER_LABELS: Record<ToleranceTier, string> = {
  economy: "Economy",
  standard: "Standard",
  tight: "Tight",
};

/** Dropdown/review label for a tier, e.g. `Standard (±0.005")`. */
export function toleranceTierLabel(tier: ToleranceTier, category: ToleranceCategory): string {
  return `${TIER_LABELS[tier]} (${TOLERANCE_VALUES[category][tier]})`;
}

/** Full label for a part's global tolerance, e.g. `Tight (±0.005")` or `Custom`. */
export function toleranceLabel(tolerance: Tolerance, material: string, materials: MaterialOption[]): string {
  const normalized = normalizeTolerance(tolerance);
  if (normalized === "custom") {
    return "Custom";
  }
  return toleranceTierLabel(normalized, toleranceCategoryForMaterial(material, materials));
}

export type ToleranceUnit = "in" | "mm";

/** Parse a typed tolerance amount; returns null unless it is a positive number. */
export function parseToleranceValue(raw: string | null | undefined): number | null {
  let trimmed = (raw ?? "").trim();
  if (trimmed.startsWith("+/-")) {
    trimmed = trimmed.slice(3);
  }
  trimmed = trimmed.replace(/^±/, "").trim();
  if (trimmed === "") {
    return null;
  }
  const parsed = Number(trimmed);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
}

/**
 * Convert a typed tolerance to the inches string stored in the manifest,
 * e.g. ("0.1", "mm") -> `+/- 0.00394"`. The customer never sees this conversion.
 */
export function toleranceDetailInInches(raw: string | null | undefined, unit: ToleranceUnit | null | undefined): string | null {
  const parsed = parseToleranceValue(raw);
  if (parsed === null) {
    return null;
  }
  const inches = unit === "mm" ? parsed / 25.4 : parsed;
  const rounded = Number(inches.toFixed(5));
  return `+/- ${rounded}"`;
}

/** Manifest (v2) tolerance string for a tier, e.g. `+/- 0.005"`. Sent verbatim for ERP import. */
export function manifestToleranceForTier(tier: ToleranceTier, category: ToleranceCategory): string {
  return `+/- ${TOLERANCE_INCHES[category][tier]}"`;
}

/**
 * The v2 manifest tolerance for a part: always the actual tolerance, never a
 * tier label, so the ERP can import it verbatim.
 */
export function manifestToleranceForPart(
  part: {
    tolerance: Tolerance;
    tolerance_detail?: string | null;
    tolerance_value?: string | null;
    tolerance_unit?: ToleranceUnit | null;
    material: string;
  },
  materials: MaterialOption[],
): string {
  const normalized = normalizeTolerance(part.tolerance);
  if (normalized === "custom") {
    return toleranceDetailInInches(part.tolerance_value, part.tolerance_unit) ?? (part.tolerance_detail ?? "").trim();
  }
  return manifestToleranceForTier(normalized, toleranceCategoryForMaterial(part.material, materials));
}

/** What the customer typed, in their own unit, for review screens. */
export function customToleranceDisplay(raw: string | null | undefined, unit: ToleranceUnit | null | undefined): string | null {
  const parsed = parseToleranceValue(raw);
  if (parsed === null) {
    return null;
  }
  return unit === "mm" ? `±${parsed} mm` : `±${parsed}"`;
}
