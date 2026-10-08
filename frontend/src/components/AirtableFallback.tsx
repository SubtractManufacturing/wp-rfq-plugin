import { useEffect, useState } from "react";
import { FormLoading } from "./FormLoading";

/** If Airtable never fires `load` (blocked or down), reveal the iframe anyway rather than hiding it forever. */
export const FALLBACK_LOAD_TIMEOUT_MS = 10_000;

export function AirtableFallback({ src }: { src: string }) {
  const [loaded, setLoaded] = useState(false);

  useEffect(() => {
    if (!src) {
      return undefined;
    }
    const timer = window.setTimeout(() => setLoaded(true), FALLBACK_LOAD_TIMEOUT_MS);
    return () => window.clearTimeout(timer);
  }, [src]);

  if (!src) {
    return (
      <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
        The RFQ form is temporarily unavailable. Please contact sales for help submitting your request.
      </div>
    );
  }

  return (
    <div className="relative">
      {loaded ? null : (
        <div className="absolute inset-x-0 top-0 z-10 bg-white">
          <FormLoading />
        </div>
      )}
      <iframe
        className={`h-[720px] w-full rounded-lg border border-slate-200 transition-opacity duration-[400ms] ${
          loaded ? "opacity-100" : "opacity-0"
        }`}
        onLoad={() => setLoaded(true)}
        src={src}
        title="RFQ fallback form"
      />
    </div>
  );
}
