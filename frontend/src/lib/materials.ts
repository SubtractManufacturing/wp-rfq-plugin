import type { MaterialOption } from "../types/config";

export function dropdownMaterials(materials: MaterialOption[]): string[] {
  return materials.filter((material) => material.show_in_dropdown).map((material) => material.label);
}

export function searchMaterials(query: string, materials: MaterialOption[]): string[] {
  const needle = query.trim().toLowerCase();
  if (needle === "") {
    return [];
  }

  return materials
    .filter((material) => {
      const values = [material.label, ...material.aliases].map((value) => value.toLowerCase());
      return values.some((value) => value.includes(needle));
    })
    .map((material) => material.label);
}

export function materialSuggestions(query: string, materials: MaterialOption[]): string[] {
  const labels = query.trim() === "" ? dropdownMaterials(materials) : searchMaterials(query, materials);

  return [...new Set(labels)];
}
