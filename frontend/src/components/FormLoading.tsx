/**
 * Shown while the startup health check runs. Mirrors the "Loading quote form..."
 * indicator on subtractmanufacturing.com/get-quote (text + sliding red bar).
 */
export function FormLoading() {
  return (
    <div aria-live="polite" className="flex flex-col items-center px-4 pb-16 pt-12" role="status">
      <p className="mb-[18px] text-center text-base font-semibold leading-snug tracking-[0.04em] text-[#222222]">
        Loading quote form...
      </p>
      <div className="relative h-[5px] w-[min(420px,80%)] overflow-hidden rounded-full bg-black/10">
        <div className="absolute left-0 top-0 h-full w-[35%] animate-rfq-progress rounded-full bg-[#f44747] motion-reduce:animate-none" />
      </div>
    </div>
  );
}
