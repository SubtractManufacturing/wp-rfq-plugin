export function UploadProgress({ progress }: { progress: number }) {
  const pct = Math.min(100, Math.max(0, Math.round(progress)));

  return (
    <div
      aria-label="Upload progress"
      aria-valuemax={100}
      aria-valuemin={0}
      aria-valuenow={pct}
      className="mt-2 h-2 w-full overflow-hidden rounded-full bg-slate-200"
      role="progressbar"
    >
      <div className="h-full bg-slate-700 transition-all duration-150" style={{ width: `${pct}%` }} />
    </div>
  );
}
