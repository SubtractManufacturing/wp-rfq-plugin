import { useRef, useState } from "react";
import { apiFetch } from "../api/client";
import {
  btnDangerCompactClasses,
  btnPrimaryBlockClasses,
  btnSecondaryCompactClasses,
} from "../components/buttonStyles";
import { UploadDropzone } from "../components/UploadDropzone";
import { UploadProgress } from "../components/UploadProgress";
import { DRAWING_MAX_BYTES, PART_MAX_BYTES, resolveDrawingContentType } from "../lib/drawingContentType";
import { uploadFile } from "../lib/uploadFile";
import { createUploadQueue } from "../lib/uploadQueue";
import { randomUuid } from "../lib/uuid";
import { useForm } from "../state/FormContext";
import type { UploadUrlResponse } from "../types/api";
import type { PartRow, UploadedFile } from "../types/manifest";

const MAX_CONCURRENT_UPLOADS = 3;

function createPart(partFile: UploadedFile): PartRow {
  return {
    part_id: randomUuid(),
    partFile,
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

const UPLOAD_FAILED = "Upload failed.";

export function StepUploads({ fetchImpl = fetch }: { fetchImpl?: typeof fetch }) {
  const { config, parts, setParts, sessionId, token, setStep } = useForm();
  const [notice, setNotice] = useState<string | null>(null);
  const queueRef = useRef(createUploadQueue(MAX_CONCURRENT_UPLOADS));
  // Files the customer removed while they were still queued; their uploads are skipped.
  const discardedRef = useRef(new WeakSet<File>());

  const atLimit = parts.length >= config.maxParts;

  const patchPart = (partId: string, patch: (part: PartRow) => PartRow) => {
    setParts((current) => current.map((part) => (part.part_id === partId ? patch(part) : part)));
  };

  const discard = (file: UploadedFile | null | undefined) => {
    if (file?.sourceFile) {
      discardedRef.current.add(file.sourceFile);
    }
  };

  const applyFileUpdate = (partId: string, file: File, fileType: FileType, next: UploadedFile) => {
    patchPart(partId, (part) => {
      if (fileType === "part") {
        return part.partFile?.sourceFile === file ? { ...part, partFile: next } : part;
      }
      return {
        ...part,
        drawings: part.drawings.map((drawing) => (drawing.sourceFile === file ? next : drawing)),
      };
    });
  };

  const queueUpload = (partId: string, file: File, fileType: FileType, contentType: string) => {
    queueRef.current.enqueue(async () => {
      if (discardedRef.current.has(file)) {
        return;
      }

      const pending = toUploadedFile(file, "uploading");
      const update = (next: UploadedFile) => applyFileUpdate(partId, file, fileType, next);
      update(pending);

      try {
        const response = await apiFetch<UploadUrlResponse>(`/sessions/${sessionId}/upload-urls`, {
          method: "POST",
          token,
          fetchImpl,
          body: {
            part_id: partId,
            file_type: fileType,
            filename: file.name,
            content_type: contentType,
          },
        });

        await uploadFile(
          response.upload_url,
          file,
          contentType,
          (progress) => update({ ...pending, progress, status: "uploading" }),
          fetchImpl,
        );

        update({
          ...pending,
          file_key: response.file_key,
          status: "confirmed",
          progress: 100,
          content_type: contentType,
        });
      } catch {
        update(failedFile(file, UPLOAD_FAILED));
      }
    });
  };

  const addPartFiles = (files: File[]) => {
    const capacity = Math.max(0, config.maxParts - parts.length);
    const accepted = files.slice(0, capacity);
    const skipped = files.length - accepted.length;

    setNotice(
      skipped > 0
        ? `Only ${accepted.length} of ${files.length} files were added — RFQs are limited to ${config.maxParts} parts. Email ${config.internationalRfqEmail} for larger RFQs.`
        : null,
    );

    if (accepted.length === 0) {
      return;
    }

    const rows = accepted.map((file) =>
      createPart(
        file.size > PART_MAX_BYTES
          ? failedFile(file, "Part files must be 500 MB or smaller.")
          : toUploadedFile(file, "pending"),
      ),
    );

    setParts((current) => [...current, ...rows]);

    rows.forEach((row, index) => {
      const file = accepted[index];
      if (file && row.partFile?.status === "pending") {
        queueUpload(row.part_id, file, "part", "application/octet-stream");
      }
    });
  };

  const retryPartFile = (part: PartRow) => {
    const file = part.partFile?.sourceFile;
    if (!file) {
      return;
    }
    applyFileUpdate(part.part_id, file, "part", toUploadedFile(file, "pending"));
    queueUpload(part.part_id, file, "part", "application/octet-stream");
  };

  const addDrawingFiles = (part: PartRow, files: File[]) => {
    const additions: Array<{ file: File; contentType: string | null; entry: UploadedFile }> = files.map((file) => {
      const contentType = resolveDrawingContentType(file);
      if (!contentType) {
        return { file, contentType, entry: failedFile(file, "Drawings must be PDF, PNG, or JPEG.") };
      }
      if (file.size > DRAWING_MAX_BYTES) {
        return { file, contentType, entry: failedFile(file, "Drawing files must be 50 MB or smaller.") };
      }
      return { file, contentType, entry: toUploadedFile(file, "pending") };
    });

    patchPart(part.part_id, (row) => ({ ...row, drawings: [...row.drawings, ...additions.map((item) => item.entry)] }));

    additions.forEach(({ file, contentType, entry }) => {
      if (contentType && entry.status === "pending") {
        queueUpload(part.part_id, file, "drawing", contentType);
      }
    });
  };

  const retryDrawing = (part: PartRow, drawing: UploadedFile) => {
    const file = drawing.sourceFile;
    const contentType = file ? resolveDrawingContentType(file) : null;
    if (!file || !contentType) {
      return;
    }
    applyFileUpdate(part.part_id, file, "drawing", toUploadedFile(file, "pending"));
    queueUpload(part.part_id, file, "drawing", contentType);
  };

  const removePart = (partId: string) => {
    const part = parts.find((row) => row.part_id === partId);
    discard(part?.partFile);
    part?.drawings.forEach(discard);
    setNotice(null);
    setParts((current) => current.filter((part) => part.part_id !== partId));
  };

  const removeDrawing = (partId: string, drawingIndex: number) => {
    discard(parts.find((row) => row.part_id === partId)?.drawings[drawingIndex]);
    patchPart(partId, (part) => ({
      ...part,
      drawings: part.drawings.filter((_, index) => index !== drawingIndex),
    }));
  };

  const hasConfirmedPart = parts.some((part) => part.partFile?.status === "confirmed");
  const hasInFlight = parts.some(
    (part) =>
      isInFlight(part.partFile) || part.drawings.some((drawing) => isInFlight(drawing)),
  );
  const hasFailedPart = parts.some((part) => part.partFile?.status === "error");
  const canContinue = hasConfirmedPart && !hasInFlight && !hasFailedPart;

  let continueHint: string | null = null;
  if (hasInFlight) {
    continueHint = "Waiting for uploads to finish…";
  } else if (hasFailedPart) {
    continueHint = "Retry or remove failed part files to continue.";
  }

  return (
    <section className="space-y-5">
      <div>
        <h1 className="text-2xl font-semibold text-slate-950">Part uploads</h1>
      </div>

      {parts.length === 0 ? (
        <UploadDropzone inputLabel="Part files" onFiles={addPartFiles} />
      ) : (
        <>
          <div className="flex items-baseline justify-between">
            <h2 className="text-sm font-semibold text-slate-900">
              {parts.length} {parts.length === 1 ? "part" : "parts"}
            </h2>
            <p className="text-xs text-slate-500">
              {parts.length} of {config.maxParts} max
            </p>
          </div>
          <ul className="divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
            {parts.map((part, index) => (
              <PartUploadRow
                index={index}
                key={part.part_id}
                onAddDrawings={(files) => addDrawingFiles(part, files)}
                onRemove={() => removePart(part.part_id)}
                onRemoveDrawing={(drawingIndex) => removeDrawing(part.part_id, drawingIndex)}
                onRetryDrawing={(drawing) => retryDrawing(part, drawing)}
                onRetryPart={() => retryPartFile(part)}
                part={part}
              />
            ))}
          </ul>
          <UploadDropzone compact disabled={atLimit} inputLabel="Part files" onFiles={addPartFiles} />
        </>
      )}

      {atLimit ? (
        <p className="text-sm text-slate-600">
          Maximum parts reached. Email {config.internationalRfqEmail} for larger RFQs.
        </p>
      ) : null}
      {notice ? <p className="text-sm text-amber-700">{notice}</p> : null}

      <div className="flex flex-wrap items-center gap-3">
        <button
          className={btnPrimaryBlockClasses}
          disabled={!canContinue}
          onClick={() => setStep("partMeta")}
          type="button"
        >
          Continue to part details
        </button>
        {continueHint ? <p className="text-sm text-slate-600">{continueHint}</p> : null}
      </div>
    </section>
  );
}

interface PartUploadRowProps {
  part: PartRow;
  index: number;
  onRemove: () => void;
  onRetryPart: () => void;
  onAddDrawings: (files: File[]) => void;
  onRemoveDrawing: (drawingIndex: number) => void;
  onRetryDrawing: (drawing: UploadedFile) => void;
}

function PartUploadRow({
  part,
  index,
  onRemove,
  onRetryPart,
  onAddDrawings,
  onRemoveDrawing,
  onRetryDrawing,
}: PartUploadRowProps) {
  const drawingInputRef = useRef<HTMLInputElement | null>(null);
  const partFile = part.partFile;
  const filename = partFile?.filename ?? `Part ${index + 1}`;

  return (
    <li className="p-4">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-medium text-slate-900" title={filename}>
            <span className="mr-2 text-slate-400">{index + 1}.</span>
            {filename}
          </p>
          {partFile ? <FileStatus file={partFile} onRetry={onRetryPart} /> : null}
        </div>
        <button
          aria-label={`Remove ${filename}`}
          className="shrink-0 text-sm text-slate-500 transition-colors duration-150 hover:text-red-700"
          onClick={onRemove}
          type="button"
        >
          Remove
        </button>
      </div>

      <div className="mt-3 border-t border-slate-100 pt-3">
        {part.drawings.length > 0 ? (
          <ul className="mb-3 space-y-2">
            {part.drawings.map((drawing, drawingIndex) => (
              <li className="flex items-start justify-between gap-3" key={`${part.part_id}-drawing-${drawingIndex}`}>
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm text-slate-800" title={drawing.filename}>
                    {drawing.filename}
                  </p>
                  <FileStatus file={drawing} onRetry={() => onRetryDrawing(drawing)} />
                </div>
                <button
                  aria-label={`Remove ${drawing.filename}`}
                  className="shrink-0 text-xs text-slate-500 transition-colors duration-150 hover:text-red-700"
                  onClick={() => onRemoveDrawing(drawingIndex)}
                  type="button"
                >
                  Remove
                </button>
              </li>
            ))}
          </ul>
        ) : null}
        <input
          accept=".pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg"
          aria-label={`Supporting files for ${filename}`}
          className="sr-only"
          multiple
          onChange={(event) => {
            const files = Array.from(event.target.files ?? []);
            if (files.length > 0) {
              onAddDrawings(files);
            }
            event.target.value = "";
          }}
          ref={drawingInputRef}
          tabIndex={-1}
          type="file"
        />
        <button
          className={btnSecondaryCompactClasses}
          onClick={() => drawingInputRef.current?.click()}
          type="button"
        >
          + Add supporting files
        </button>
        <span className="ml-2 text-xs text-slate-500">Optional · PDF, PNG, or JPEG up to 50 MB each</span>
      </div>
    </li>
  );
}

function FileStatus({ file, onRetry }: { file: UploadedFile; onRetry: () => void }) {
  const size = file.sourceFile ? formatBytes(file.sourceFile.size) : null;

  if (file.status === "confirmed") {
    return (
      <p className="mt-0.5 text-xs text-green-700">
        Uploaded{size ? ` · ${size}` : ""}
      </p>
    );
  }

  if (file.status === "uploading") {
    return (
      <div className="mt-0.5">
        <p className="text-xs text-slate-500">
          Uploading… {Math.round(file.progress)}%{size ? ` · ${size}` : ""}
        </p>
        <UploadProgress progress={file.progress} />
      </div>
    );
  }

  if (file.status === "error") {
    return (
      <div className="mt-0.5 text-sm text-red-700">
        <p className="text-xs">{file.error ?? UPLOAD_FAILED}</p>
        {file.sourceFile && !isValidationError(file) ? (
          <button
            className={btnDangerCompactClasses}
            onClick={onRetry}
            type="button"
          >
            Retry upload
          </button>
        ) : null}
      </div>
    );
  }

  return <p className="mt-0.5 text-xs text-slate-500">Waiting to upload…{size ? ` · ${size}` : ""}</p>;
}

function isInFlight(file: UploadedFile | null): boolean {
  return file?.status === "pending" || file?.status === "uploading";
}

/** Size/type rejections can't be fixed by retrying — the customer must remove the file. */
function isValidationError(file: UploadedFile): boolean {
  return (file.error ?? UPLOAD_FAILED) !== UPLOAD_FAILED;
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) {
    return `${bytes} B`;
  }
  if (bytes < 1024 * 1024) {
    return `${(bytes / 1024).toFixed(0)} KB`;
  }
  if (bytes < 1024 * 1024 * 1024) {
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  }
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(2)} GB`;
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
