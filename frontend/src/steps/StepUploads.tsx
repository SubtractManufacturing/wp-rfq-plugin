import { useEffect, useRef } from "react";
import { apiFetch } from "../api/client";
import { UploadProgress } from "../components/UploadProgress";
import { DRAWING_MAX_BYTES, PART_MAX_BYTES, resolveDrawingContentType } from "../lib/drawingContentType";
import { uploadFile } from "../lib/uploadFile";
import { randomUuid } from "../lib/uuid";
import { useForm } from "../state/FormContext";
import type { UploadUrlResponse } from "../types/api";
import type { PartRow, UploadedFile } from "../types/manifest";

function createPart(): PartRow {
  return {
    part_id: randomUuid(),
    partFile: null,
    drawings: [],
    material: "",
    tolerance: "standard",
    tolerance_detail: null,
    threads_features: null,
    quantity: 1,
    target_unit_price: null,
    notes: null,
  };
}

type FileType = "part" | "drawing";

export function StepUploads({ fetchImpl = fetch }: { fetchImpl?: typeof fetch }) {
  const { config, parts, setParts, sessionId, token, setStep } = useForm();
  const rows = parts.length === 0 ? [createPart()] : parts;
  const partInputRefs = useRef<Record<string, HTMLInputElement | null>>({});

  useEffect(() => {
    if (parts.length === 0) {
      setParts([createPart()]);
    }
  }, [parts.length, setParts]);

  const updatePart = (part: PartRow) => setParts(rows.map((row) => (row.part_id === part.part_id ? part : row)));

  const removePart = (partId: string) => {
    if (rows.length <= 1) {
      return;
    }
    setParts(rows.filter((row) => row.part_id !== partId));
  };

  const uploadToS3 = async (
    part: PartRow,
    file: File,
    fileType: FileType,
    contentType: string,
    onFileUpdate: (file: UploadedFile) => void,
  ) => {
    const pending = toUploadedFile(file, "uploading");
    onFileUpdate(pending);

    const setProgress = (progress: number) => {
      onFileUpdate({ ...pending, progress, status: "uploading" });
    };

    try {
      const response = await apiFetch<UploadUrlResponse>(`/sessions/${sessionId}/upload-urls`, {
        method: "POST",
        token,
        fetchImpl,
        body: {
          part_id: part.part_id,
          file_type: fileType,
          filename: file.name,
          content_type: contentType,
        },
      });

      await uploadFile(response.upload_url, file, contentType, setProgress, fetchImpl);

      onFileUpdate({
        ...pending,
        file_key: response.file_key,
        status: "confirmed",
        progress: 100,
        content_type: contentType,
      });
    } catch {
      onFileUpdate(failedFile(file, "Upload failed."));
    }
  };

  const uploadPartFile = async (part: PartRow, file: File) => {
    if (file.size > PART_MAX_BYTES) {
      updatePart({ ...part, partFile: failedFile(file, "Part files must be 500 MB or smaller.") });
      return;
    }

    await uploadToS3(part, file, "part", "application/octet-stream", (partFile) => {
      updatePart({ ...part, partFile });
    });
  };

  const uploadDrawingFile = async (part: PartRow, file: File, drawingIndex?: number) => {
    const contentType = resolveDrawingContentType(file);
    if (!contentType) {
      const errorFile = failedFile(file, "Drawings must be PDF, PNG, or JPEG.");
      if (drawingIndex === undefined) {
        updatePart({ ...part, drawings: [...part.drawings, errorFile] });
      } else {
        const drawings = [...part.drawings];
        drawings[drawingIndex] = errorFile;
        updatePart({ ...part, drawings });
      }
      return;
    }

    if (file.size > DRAWING_MAX_BYTES) {
      const errorFile = failedFile(file, "Drawing files must be 50 MB or smaller.");
      if (drawingIndex === undefined) {
        updatePart({ ...part, drawings: [...part.drawings, errorFile] });
      } else {
        const drawings = [...part.drawings];
        drawings[drawingIndex] = errorFile;
        updatePart({ ...part, drawings });
      }
      return;
    }

    const placeholderIndex = drawingIndex ?? part.drawings.length;
    const drawings = [...part.drawings];
    if (drawingIndex === undefined) {
      drawings.push(toUploadedFile(file, "uploading"));
      updatePart({ ...part, drawings });
    }

    await uploadToS3(part, file, "drawing", contentType, (drawingFile) => {
      const nextDrawings = [...(drawingIndex === undefined ? drawings : part.drawings)];
      nextDrawings[placeholderIndex] = drawingFile;
      updatePart({ ...part, drawings: nextDrawings });
    });
  };

  const canContinue = rows.some((part) => part.partFile?.status === "confirmed");

  return (
    <section className="space-y-5">
      <div>
        <h1 className="text-2xl font-semibold text-slate-950">Part uploads</h1>
        <p className="mt-2 text-sm text-slate-600">
          Upload one CAD file per part. Larger RFQs should be emailed to {config.internationalRfqEmail}.
        </p>
      </div>
      {rows.map((part, index) => (
        <div className="rounded-lg border border-slate-200 p-4" key={part.part_id}>
          <div className="flex items-start justify-between gap-3">
            <h2 className="font-medium text-slate-900">Part {index + 1}</h2>
            {rows.length > 1 ? (
              <button
                className="text-sm text-red-700 underline"
                onClick={() => removePart(part.part_id)}
                type="button"
              >
                Remove part
              </button>
            ) : null}
          </div>

          {part.partFile?.status === "confirmed" ? (
            <div className="mt-3 text-sm text-slate-700">
              <p className="text-green-700">Uploaded: {part.partFile.filename}</p>
              <button
                className="mt-2 rounded-md border border-slate-300 px-3 py-1 text-sm"
                onClick={() => {
                  updatePart({ ...part, partFile: null });
                  partInputRefs.current[part.part_id]?.click();
                }}
                type="button"
              >
                Replace file
              </button>
            </div>
          ) : (
            <label className="mt-3 block text-sm font-medium text-slate-800">
              Part file
              <input
                className="mt-1 block w-full text-sm"
                onChange={(event) => {
                  const file = event.target.files?.[0];
                  if (file) {
                    void uploadPartFile(part, file);
                  }
                  event.target.value = "";
                }}
                ref={(element) => {
                  partInputRefs.current[part.part_id] = element;
                }}
                type="file"
              />
            </label>
          )}

          {part.partFile?.status === "uploading" ? <UploadProgress progress={part.partFile.progress} /> : null}
          {part.partFile?.status === "error" ? (
            <FileUploadError
              file={part.partFile}
              onRetry={() => {
                if (part.partFile?.sourceFile) {
                  void uploadPartFile(part, part.partFile.sourceFile);
                }
              }}
            />
          ) : null}

          <div className="mt-4 border-t border-slate-100 pt-4">
            <p className="text-sm font-medium text-slate-800">Supporting files (optional)</p>
            <p className="mt-1 text-xs text-slate-500">PDF, PNG, or JPEG up to 50 MB each.</p>
            {part.drawings.map((drawing, drawingIndex) => (
              <div className="mt-3" key={`${part.part_id}-drawing-${drawingIndex}`}>
                {drawing.status === "confirmed" ? (
                  <p className="text-sm text-green-700">Uploaded: {drawing.filename}</p>
                ) : null}
                {drawing.status === "uploading" ? <UploadProgress progress={drawing.progress} /> : null}
                {drawing.status === "error" ? (
                  <FileUploadError
                    file={drawing}
                    onRetry={() => {
                      if (drawing.sourceFile) {
                        void uploadDrawingFile(part, drawing.sourceFile, drawingIndex);
                      }
                    }}
                  />
                ) : null}
              </div>
            ))}
            <label className="mt-3 block text-sm font-medium text-slate-800">
              Add drawing
              <input
                accept=".pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg"
                className="mt-1 block w-full text-sm"
                onChange={(event) => {
                  const file = event.target.files?.[0];
                  if (file) {
                    void uploadDrawingFile(part, file);
                  }
                  event.target.value = "";
                }}
                type="file"
              />
            </label>
          </div>
        </div>
      ))}
      <button
        className="rounded-md border border-slate-300 px-4 py-2 text-sm disabled:text-slate-400"
        disabled={rows.length >= config.maxParts}
        onClick={() => setParts([...rows, createPart()])}
        type="button"
      >
        Add part
      </button>
      {rows.length >= config.maxParts ? (
        <p className="text-sm text-slate-600">Maximum parts reached. Email {config.internationalRfqEmail} for larger RFQs.</p>
      ) : null}
      <button
        className="block rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:bg-slate-300"
        disabled={!canContinue}
        onClick={() => setStep("partMeta")}
        type="button"
      >
        Continue to part details
      </button>
    </section>
  );
}

function FileUploadError({ file, onRetry }: { file: UploadedFile; onRetry: () => void }) {
  return (
    <div className="mt-2 text-sm text-red-700">
      <p>{file.error ?? "Upload failed."}</p>
      <button className="mt-2 rounded-md border border-red-300 px-3 py-1" onClick={onRetry} type="button">
        Retry upload
      </button>
    </div>
  );
}

function toUploadedFile(file: File, status: UploadedFile["status"]): UploadedFile {
  return {
    file_key: "",
    filename: file.name,
    content_type: file.type || "application/octet-stream",
    status,
    progress: 0,
    sourceFile: file,
  };
}

function failedFile(file: File, error: string): UploadedFile {
  return {
    ...toUploadedFile(file, "error"),
    error,
  };
}
