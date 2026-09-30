import type { LeadTimePreference } from "../types/manifest";

export const leadTimeOptions: Array<{ value: LeadTimePreference; label: string }> = [
  { value: "standard", label: "Standard" },
  { value: "expedited", label: "Expedited" },
  { value: "economy", label: "Economy" },
  { value: "target_date", label: "Meet Target Date" },
];

export const leadTimeLabels: Record<LeadTimePreference, string> = {
  standard: "Standard",
  expedited: "Expedited",
  economy: "Economy",
  target_date: "Meet Target Date",
};
