const DRAWING_EXTENSIONS: Record<string, string> = {
  pdf: "application/pdf",
  png: "image/png",
  jpg: "image/jpeg",
  jpeg: "image/jpeg",
};

export const PART_MAX_BYTES = 500 * 1024 * 1024;
export const DRAWING_MAX_BYTES = 50 * 1024 * 1024;

export function resolveDrawingContentType(file: File): string {
  const extension = file.name.split(".").pop()?.toLowerCase() ?? "";
  const fromExtension = DRAWING_EXTENSIONS[extension];
  if (fromExtension) {
    return fromExtension;
  }

  if (file.type === "application/pdf" || file.type === "image/png" || file.type === "image/jpeg") {
    return file.type;
  }

  return "application/octet-stream";
}
