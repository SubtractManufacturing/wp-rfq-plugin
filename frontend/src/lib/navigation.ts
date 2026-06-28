import type { PartRow, StepId } from "../types/manifest";

export function hasConfirmedPartFile(parts: PartRow[]): boolean {
  return parts.some((part) => part.partFile?.status === "confirmed");
}

export function canReachStep(
  target: StepId,
  contactSaved: boolean,
  parts: PartRow[],
): boolean {
  if (target === "contact") {
    return true;
  }

  if (!contactSaved) {
    return false;
  }

  if (target === "uploads") {
    return true;
  }

  return hasConfirmedPartFile(parts);
}
