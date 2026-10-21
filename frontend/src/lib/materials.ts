import type { MaterialOption } from "../types/config";

export function dropdownMaterials(materials: MaterialOption[]): string[] {
  return materials.filter((material) => material.show_in_dropdown).map((material) => material.label);
}

export function searchMaterials(query: string, materials: MaterialOption[]): string[] {
  return searchMaterialEntries(query, materials).map((material) => material.label);
}

export function searchMaterialEntries(query: string, materials: MaterialOption[]): MaterialOption[] {
  const needle = query.trim().toLowerCase();
  if (needle === "") {
    return [];
  }

  return materials.filter((material) => {
    const values = [material.label, material.group ?? "", material.type ?? "", ...material.aliases].map((value) =>
      value.toLowerCase(),
    );
    return values.some((value) => value.includes(needle));
  });
}

export function findMaterialByLabel(label: string, materials: MaterialOption[]): MaterialOption | null {
  const normalized = label.trim().toLowerCase();
  if (normalized === "") {
    return null;
  }
  return materials.find((material) => material.label.toLowerCase() === normalized) ?? null;
}

/** Ordered unique tree level-1 groups present in the catalog. */
export function materialGroups(materials: MaterialOption[]): string[] {
  return uniqueInOrder(materials.map((material) => material.group ?? "").filter((group) => group !== ""));
}

/** Ordered unique tree level-2 types within a group. */
export function materialTypes(materials: MaterialOption[], group: string): string[] {
  return uniqueInOrder(
    materials
      .filter((material) => (material.group ?? "") === group)
      .map((material) => material.type ?? "")
      .filter((type) => type !== ""),
  );
}

/** Catalog leaves under a group/type. Flat entries (no tree fields) are returned for empty group/type filters. */
export function materialsIn(materials: MaterialOption[], group: string, type: string): MaterialOption[] {
  return materials.filter(
    (material) => (material.group ?? "") === group && (material.type ?? "") === type,
  );
}

/** Entries with no tree position (e.g. flat admin-added materials) - still searchable and pickable. */
export function ungroupedMaterials(materials: MaterialOption[]): MaterialOption[] {
  return materials.filter((material) => (material.group ?? "") === "" || (material.type ?? "") === "");
}

function uniqueInOrder(values: string[]): string[] {
  return Array.from(new Set(values));
}

/** Finishes that ask for a color/detail, e.g. anodize or powder coat. */
export function finishNeedsColor(finish: string | null | undefined): boolean {
  if (!finish) {
    return false;
  }
  const normalized = finish.toLowerCase();
  return normalized.includes("anodize") || normalized.includes("powder coat") || normalized.includes("cerakote");
}

export const OTHER_FINISH = "Other: specify";

export function isOtherFinish(finish: string | null | undefined): boolean {
  return (finish ?? "") === OTHER_FINISH;
}

/** Default finish for a newly picked material: keep the current one if still offered. */
export function defaultFinish(
  material: MaterialOption | null,
  currentFinish: string | null | undefined,
): string | null {
  const finishes = material?.finishes ?? [];
  if (finishes.length === 0) {
    return null;
  }
  if (currentFinish && finishes.includes(currentFinish)) {
    return currentFinish;
  }
  return finishes.includes("As-machined") ? "As-machined" : finishes[0];
}
