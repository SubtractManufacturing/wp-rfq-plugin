import { apiFetch } from "../api/client";
import { FieldError } from "../components/FieldError";
import { buildManifest } from "../lib/manifest";
import { useForm } from "../state/FormContext";
import type { SubmitResponse } from "../types/api";

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

  return (
    <section className="space-y-5">
      <div>
        <h1 className="text-2xl font-semibold text-slate-950">Review and submit</h1>
        <p className="mt-2 text-sm text-slate-600">Confirm your RFQ details before submitting.</p>
      </div>
      <div className="rounded-lg border border-slate-200 p-4 text-sm text-slate-700">
        <p><strong>Contact:</strong> {contact.first_name} {contact.last_name}, {contact.email}</p>
        <p><strong>Parts:</strong> {parts.length}</p>
        <p><strong>Shipping:</strong> {global.shipping_destination.postal_code}</p>
      </div>
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
