import type { ContactState, GlobalState, PartRow, UploadedFile } from "../types/manifest";

export function buildDraftPayload(sessionId: string, contact: ContactState, parts: PartRow[], global: GlobalState) {
  return {
    session_id: sessionId,
    contact,
    parts: parts.map((part) => ({
      ...part,
      partFile: part.partFile ? sanitizeFile(part.partFile) : null,
      drawings: part.drawings.map(sanitizeFile),
    })),
    global,
  };
}

function sanitizeFile(file: UploadedFile) {
  return {
    file_key: file.file_key,
    filename: file.filename,
    content_type: file.content_type,
    status: file.status,
    progress: file.progress,
    error: file.error,
  };
}
