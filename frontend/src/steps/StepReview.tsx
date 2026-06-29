import type { ReactNode } from "react";
import { apiFetch } from "../api/client";
import { FieldError } from "../components/FieldError";
import { buildManifest } from "../lib/manifest";
import { formatPhoneSummary } from "../lib/phone";
import { useForm } from "../state/FormContext";
import type { SubmitResponse } from "../types/api";
import type { LeadTimePreference, StepId } from "../types/manifest";

const leadTimeLabels: Record<LeadTimePreference, string> = {
  no_rush: "No rush",
  standard: "Standard",
  target_date: "Meet target date",
  expedited: "Expedited",
  economy: "Economy",
};

export function StepReview({ fetchImpl = fetch }: { fetchImpl?: typeof fetch }) {
  const {
    contact,
    global,
    parts,
    sessionId,
    setReceiptNumber,
    setStep,
    setSubmitError,
    submitError,
    token,
  } = useForm();

  const submit = async () => {
    if (!sessionId || !token) {
      setSubmitError("Session expired. Return to contact and continue again.");
      return;
    }

    const manifest = buildManifest(sessionId, contact, parts, global);
    try {
      const response = await apiFetch<SubmitResponse>(`/sessions/${sessionId}/submit`, {
        method: "POST",
        token,
        fetchImpl,
        body: manifest,
      });
      setSubmitError(null);
      setReceiptNumber(response.receipt_number);
    } catch (error) {
      setSubmitError(error instanceof Error ? error.message : "Submission failed. Please retry.");
    }
  };

  const edit = (step: StepId) => () => setStep(step);

  return (
    <section className="space-y-5">
      <div>
        <h1 className="text-2xl font-semibold text-slate-950">Review and submit</h1>
        <p className="mt-2 text-sm text-slate-600">Confirm your RFQ details before submitting.</p>
      </div>

      <ReviewSection onEdit={edit("contact")} title="Contact">
        <p>{contact.first_name} {contact.last_name}</p>
        <p>{contact.email}</p>
        {contact.company ? <p>{contact.company}</p> : null}
        {contact.phone ? <p>{formatPhoneSummary(contact.phone, contact.phone_country)}</p> : null}
      </ReviewSection>

      <ReviewSection onEdit={edit("uploads")} title="Parts">
        {parts.map((part, index) => (
          <div className="mt-3 border-t border-slate-100 pt-3 first:mt-0 first:border-t-0 first:pt-0" key={part.part_id}>
            <p className="font-medium text-slate-900">Part {index + 1}</p>
            <p>File: {part.partFile?.filename ?? "—"}</p>
            <p>Drawings: {part.drawings.filter((drawing) => drawing.status === "confirmed").map((drawing) => drawing.filename).join(", ") || "None"}</p>
            <p>Material: {part.material}</p>
            <p>Tolerance: {part.tolerance}{part.tolerance === "custom" && part.tolerance_detail ? ` (${part.tolerance_detail})` : ""}</p>
            <p>Quantity: {part.quantity}</p>
            {part.threads_features ? <p>Threads/features: {part.threads_features}</p> : null}
            {part.target_unit_price !== null ? <p>Target unit price: ${part.target_unit_price.toFixed(2)}</p> : null}
            {part.notes ? <p>Notes: {part.notes}</p> : null}
          </div>
        ))}
      </ReviewSection>

      <ReviewSection onEdit={edit("global")} title="RFQ details">
        <p>Delivery date: {global.required_delivery_date}</p>
        <p>Lead time: {global.lead_time_preference ? leadTimeLabels[global.lead_time_preference] : "—"}</p>
        <p>Shipping postal code: {global.shipping_destination.postal_code}</p>
        {global.po_number ? <p>PO number: {global.po_number}</p> : null}
        <p>NDA required: {global.nda_required ? "Yes" : "No"}</p>
        {global.notes ? <p>Notes: {global.notes}</p> : null}
      </ReviewSection>

      <FieldError message={submitError} />
      {submitError ? (
        <p className="text-sm text-slate-600">Submission failed, but your work is saved. Retry submission without re-uploading files.</p>
      ) : null}
      <div className="flex flex-wrap gap-3">
        <button className="rounded-md border border-slate-300 px-4 py-2 text-sm" onClick={() => setStep("global")} type="button">
          Back
        </button>
        <button className="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white" onClick={() => void submit()} type="button">
          {submitError ? "Retry Submission" : "Submit RFQ"}
        </button>
      </div>
    </section>
  );
}

function ReviewSection({
  title,
  onEdit,
  children,
}: {
  title: string;
  onEdit: () => void;
  children: ReactNode;
}) {
  return (
    <div className="rounded-lg border border-slate-200 p-4 text-sm text-slate-700">
      <div className="flex items-center justify-between gap-3">
        <h2 className="text-base font-semibold text-slate-900">{title}</h2>
        <button className="text-sm text-slate-600 underline" onClick={onEdit} type="button">
          Edit
        </button>
      </div>
      <div className="mt-3 space-y-1">{children}</div>
    </div>
  );
}
