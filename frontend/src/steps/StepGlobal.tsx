import { btnPrimaryClasses } from "../components/buttonStyles";
import { FieldError } from "../components/FieldError";
import { Select } from "../components/Select";
import { leadTimeOptions } from "../lib/leadTime";
import { isValidPostalCode } from "../lib/postalCode";
import { useForm } from "../state/FormContext";
import type { LeadTimePreference } from "../types/manifest";

export function StepGlobal() {
  const { config, global, setGlobal, setStep } = useForm();
  const postalValid = isValidPostalCode(global.shipping_destination.postal_code);
  const needsTargetDate = global.lead_time_preference === "target_date";
  const canContinue =
    global.lead_time_preference !== "" &&
    postalValid &&
    (!needsTargetDate || global.required_delivery_date !== "");

  return (
    <section className="space-y-5">
      <div>
        <h1 className="text-2xl font-semibold text-slate-950">RFQ details</h1>
        <p className="mt-2 text-sm text-slate-600">Orders outside North America should be emailed to {config.internationalRfqEmail}.</p>
      </div>
      <label className="block text-sm font-medium text-slate-800">
        Shipping ZIP or postal code
        <input
          className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
          onChange={(event) => setGlobal({ ...global, shipping_destination: { postal_code: event.target.value } })}
          value={global.shipping_destination.postal_code}
        />
      </label>
      <FieldError message={global.shipping_destination.postal_code && !postalValid ? "Enter a valid US ZIP or Canadian postal code." : null} />
      <label className="block text-sm font-medium text-slate-800">
        Lead time preference
        <Select
          onChange={(event) => {
            const lead_time_preference = event.target.value as LeadTimePreference | "";
            setGlobal({
              ...global,
              lead_time_preference,
              required_delivery_date: lead_time_preference === "target_date" ? global.required_delivery_date : "",
            });
          }}
          value={global.lead_time_preference}
          wrapperClassName="mt-1"
        >
          <option value="">Select timing</option>
          {leadTimeOptions.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </Select>
      </label>
      {needsTargetDate ? (
        <label className="block text-sm font-medium text-slate-800">
          Target date
          <input
            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
            min={new Date().toISOString().slice(0, 10)}
            onChange={(event) => setGlobal({ ...global, required_delivery_date: event.target.value })}
            type="date"
            value={global.required_delivery_date}
          />
        </label>
      ) : null}
      <label className="block text-sm font-medium text-slate-800">
        Purchase order number
        <input
          className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
          onChange={(event) => setGlobal({ ...global, po_number: event.target.value || null })}
          value={global.po_number ?? ""}
        />
      </label>
      <label className="flex gap-2 text-sm text-slate-800">
        <input
          checked={global.nda_required}
          onChange={(event) => setGlobal({ ...global, nda_required: event.target.checked })}
          type="checkbox"
        />
        Treat as NDA — These parts will be completely excluded from posting on social media or being used in any marketing materials.
      </label>
      {global.nda_required ? (
        <p className="rounded-md bg-slate-50 p-3 text-sm text-slate-700">
          If you require a formal NDA signed by both parties before sharing any IP, please email {config.salesContactEmail}.
        </p>
      ) : null}
      <label className="block text-sm font-medium text-slate-800">
        Additional notes
        <textarea
          className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
          onChange={(event) => setGlobal({ ...global, notes: event.target.value || null })}
          value={global.notes ?? ""}
        />
      </label>
      <button
        className={btnPrimaryClasses}
        disabled={!canContinue}
        onClick={() => setStep("review")}
        type="button"
      >
        Continue to review
      </button>
    </section>
  );
}
