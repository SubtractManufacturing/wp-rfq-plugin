import { useRef, useState, type DragEvent } from "react";
import { btnPrimaryClasses, btnSecondaryClasses } from "./buttonStyles";

interface UploadDropzoneProps {
  onFiles: (files: File[]) => void;
  inputLabel: string;
  disabled?: boolean;
  compact?: boolean;
}

export function UploadDropzone({ onFiles, inputLabel, disabled = false, compact = false }: UploadDropzoneProps) {
  const inputRef = useRef<HTMLInputElement | null>(null);
  const [dragging, setDragging] = useState(false);

  const open = () => {
    if (!disabled) {
      inputRef.current?.click();
    }
  };

  const handleDragOver = (event: DragEvent<HTMLDivElement>) => {
    if (disabled) {
      return;
    }
    event.preventDefault();
    setDragging(true);
  };

  const handleDrop = (event: DragEvent<HTMLDivElement>) => {
    event.preventDefault();
    setDragging(false);
    if (disabled) {
      return;
    }
    const files = Array.from(event.dataTransfer.files);
    if (files.length > 0) {
      onFiles(files);
    }
  };

  const zoneClasses = [
    "border-dashed transition-colors",
    disabled ? "cursor-not-allowed border-slate-200 bg-slate-50 opacity-60" : "cursor-pointer",
    !disabled && dragging ? "border-slate-900 bg-slate-100" : "",
    !disabled && !dragging ? "border-slate-300 bg-white hover:border-slate-400 hover:bg-slate-50" : "",
  ].join(" ");

  return (
    <div
      className={
        compact
          ? `flex flex-wrap items-center justify-between gap-3 rounded-lg border px-4 py-3 ${zoneClasses}`
          : `flex flex-col items-center justify-center rounded-xl border-2 px-6 py-14 text-center ${zoneClasses}`
      }
      onClick={open}
      onDragLeave={() => setDragging(false)}
      onDragOver={handleDragOver}
      onDrop={handleDrop}
    >
      <input
        aria-label={inputLabel}
        className="sr-only"
        disabled={disabled}
        multiple
        onChange={(event) => {
          const files = Array.from(event.target.files ?? []);
          if (files.length > 0) {
            onFiles(files);
          }
          event.target.value = "";
        }}
        onClick={(event) => event.stopPropagation()}
        ref={inputRef}
        tabIndex={-1}
        type="file"
      />
      {compact ? (
        <>
          <p className="text-sm text-slate-600">Drag &amp; drop more files here, or</p>
          <button
            className={`${btnSecondaryClasses} font-medium text-slate-900 disabled:cursor-not-allowed disabled:text-slate-400 disabled:hover:bg-white`}
            disabled={disabled}
            type="button"
          >
            Add more parts
          </button>
        </>
      ) : (
        <>
          <svg
            aria-hidden="true"
            className="h-10 w-10 text-slate-400"
            fill="none"
            stroke="currentColor"
            strokeLinecap="round"
            strokeLinejoin="round"
            strokeWidth={1.5}
            viewBox="0 0 24 24"
          >
            <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
            <path d="M14 3v5h5" />
            <path d="M12 12v6M9 15h6" />
          </svg>
          <p className="mt-4 text-lg font-semibold text-slate-950">Upload part files</p>
          <p className="mt-1 text-sm text-slate-600">Drag &amp; drop or click to browse</p>
          <p className="mt-1 text-xs text-slate-500">STEP, IGES, STL, SLDPRT, etc.</p>
          <button
            className={`${btnPrimaryClasses} mt-5 px-5`}
            type="button"
          >
            Select files
          </button>
        </>
      )}
    </div>
  );
}
