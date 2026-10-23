import { btnPrimaryClasses } from "./components/buttonStyles";

export function SuccessView({
  receiptNumber,
  email,
  supportEmail,
  onStartNew,
}: {
  receiptNumber: string;
  email: string;
  /** Shown as a mailto link so customers can correct a mistyped address. */
  supportEmail?: string;
  onStartNew: () => void;
}) {
  return (
    <section className="mx-auto max-w-2xl rounded-xl border border-slate-200 bg-white p-8 text-center shadow-sm">
      <svg className="mx-auto h-16 w-16 text-green-600" fill="none" stroke="currentColor" strokeWidth="1.5" viewBox="0 0 24 24" aria-hidden="true">
        <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
      </svg>
      <h1 className="mt-4 text-2xl font-semibold text-slate-950">Thank you for submitting your quote request.</h1>
      <p className="mt-3 text-slate-600">Our team is reviewing your files. We'll send your quote to:</p>
      <p className="mt-2 break-all text-lg font-medium text-slate-950" data-testid="success-email">{email}</p>
      {supportEmail ? (
        <p className="mt-2 text-sm text-slate-500">
          Wrong address? Email{" "}
          <a className="underline hover:text-slate-900" href={`mailto:${supportEmail}`}>{supportEmail}</a>{" "}
          with your reference number below.
        </p>
      ) : null}
      <button className={`${btnPrimaryClasses} mt-6`} onClick={onStartNew} type="button">
        Start a new quote request
      </button>
      <p className="mt-6 text-sm font-normal text-gray-500">Ref: {receiptNumber}</p>
    </section>
  );
}
