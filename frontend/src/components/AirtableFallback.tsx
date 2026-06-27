export function AirtableFallback({ src }: { src: string }) {
  if (!src) {
    return (
      <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
        The RFQ form is temporarily unavailable. Please contact sales for help submitting your request.
      </div>
    );
  }

  return (
    <iframe
      className="h-[720px] w-full rounded-lg border border-slate-200"
      src={src}
      title="RFQ fallback form"
    />
  );
}
