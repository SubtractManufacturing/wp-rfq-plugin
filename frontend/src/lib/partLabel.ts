import type { PartRow } from "../types/manifest";

export function partLabelFromFilename(filename: string): string {
  const base = filename.split(/[/\\]/).pop() ?? filename;
  const dot = base.lastIndexOf(".");
  return dot > 0 ? base.slice(0, dot) : base;
}

export function partDisplayName(part: PartRow, index: number): string {
  if (part.partFile?.status === "confirmed" && part.partFile.filename) {
    return partLabelFromFilename(part.partFile.filename);
  }
  return `Part ${index + 1}`;
}
