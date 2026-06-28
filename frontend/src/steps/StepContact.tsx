import { useState } from "react";
import { apiFetch } from "../api/client";
import { PhoneField } from "../components/PhoneField";
import { FieldError } from "../components/FieldError";
import { getPhoneValidationError, normalizePhone } from "../lib/phone";
import { ensureSession } from "../lib/session";
import { useForm } from "../state/FormContext";

export function StepContact({ fetchImpl = fetch }: { fetchImpl?: typeof fetch }) {
  const {
    config,
    contact,
    setContact,
    setContactSaved,
    setSession,
    setStep,
    sessionId,
    token,
  } = useForm();
  const [contactError, setContactError] = useState<string | null>(null);
  const [contactSaving, setContactSaving] = useState(false);
  const [startingSession, setStartingSession] = useState(false);

  const update = (field: keyof typeof contact, value: string) => {
    setContact({
      ...contact,
      [field]: value,
    });
    setContactSaved(false);
    setContactError(null);
  };

  const saveContact = async ({ advance }: { advance: boolean }) => {
    const phoneError = getPhoneValidationError(contact.phone, contact.phone_country);
    if (phoneError) {
      setContactError(phoneError);
      return;
    }

    const normalizedPhone = normalizePhone(contact.phone, contact.phone_country);
    const needsSession = !sessionId || !token;
    setContactSaving(true);
    setStartingSession(needsSession);
    setContactError(null);

    try {
      const activeSession = await ensureSession({
        restBase: config.restBase,
        fetchImpl,
        sessionId,
        token,
        setSession,
      });

      await apiFetch(`/sessions/${activeSession.sessionId}/contact`, {
        method: "PATCH",
        token: activeSession.token,
        fetchImpl,
        body: {
          first_name: contact.first_name,
          last_name: contact.last_name,
          email: contact.email,
          company: contact.company.trim() === "" ? null : contact.company,
          phone: normalizedPhone.phone,
          phone_country_code: normalizedPhone.phone_country_code,
          job_title: null,
        },
      });
      setContactSaved(true);
      if (advance) {
        setStep("uploads");
      }
    } catch (error) {
      setContactError(error instanceof Error ? error.message : "Could not save contact information.");
    } finally {
      setContactSaving(false);
      setStartingSession(false);
    }
  };

  const requiredMissing =
    contact.first_name.trim() === "" || contact.last_name.trim() === "" || contact.email.trim() === "";

  const continueLabel = contactSaving
    ? startingSession
      ? "Starting..."
      : "Saving..."
    : "Continue to uploads";

  return (
    <section className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold text-slate-950">Contact information</h1>
        <p className="mt-2 text-sm text-slate-600">Tell us who should receive quote updates.</p>
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        <label className="block text-sm font-medium text-slate-800">
          First name
          <input
            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
            onChange={(event) => update("first_name", event.target.value)}
            required
            value={contact.first_name}
          />
        </label>
        <label className="block text-sm font-medium text-slate-800">
          Last name
          <input
            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
            onChange={(event) => update("last_name", event.target.value)}
            required
            value={contact.last_name}
          />
        </label>
        <label className="block text-sm font-medium text-slate-800">
          Email
          <input
            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
            onChange={(event) => update("email", event.target.value)}
            required
            type="email"
            value={contact.email}
          />
        </label>
        <label className="block text-sm font-medium text-slate-800">
          Company
          <input
            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
            onChange={(event) => update("company", event.target.value)}
            value={contact.company}
          />
        </label>
        <label className="block text-sm font-medium text-slate-800 sm:col-span-2">
          Phone
          <PhoneField
            onChange={({ phone, phoneCountry }) => {
              setContact({
                ...contact,
                phone,
                phone_country: phoneCountry,
              });
              setContactSaved(false);
              setContactError(null);
            }}
            phone={contact.phone}
            phoneCountry={contact.phone_country}
          />
        </label>
      </div>
      <FieldError message={requiredMissing ? "First name, last name, and email are required." : contactError} />
      {contactError ? (
        <button
          className="rounded-md border border-red-300 px-4 py-2 text-sm text-red-700"
          disabled={contactSaving || requiredMissing}
          onClick={() => {
            void saveContact({ advance: false });
          }}
          type="button"
        >
          Retry save
        </button>
      ) : null}
      <button
        className="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:bg-slate-300"
        disabled={requiredMissing || contactSaving}
        onMouseDown={(event) => event.preventDefault()}
        onClick={() => {
          void saveContact({ advance: true });
        }}
        type="button"
      >
        {continueLabel}
      </button>
    </section>
  );
}
