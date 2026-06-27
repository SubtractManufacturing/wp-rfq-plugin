import { apiFetch } from "../api/client";
import { FieldError } from "../components/FieldError";
import { formatPhone, normalizePhone } from "../lib/phone";
import { useForm } from "../state/FormContext";

export function StepContact({ fetchImpl = fetch }: { fetchImpl?: typeof fetch }) {
  const { contact, setContact, setContactSaved, setStep, sessionId, token } = useForm();

  const update = (field: keyof typeof contact, value: string) => {
    setContact({
      ...contact,
      [field]: field === "phone" ? formatPhone(value) : value,
    });
    setContactSaved(false);
  };

  const saveContact = async () => {
    const normalizedPhone = normalizePhone(contact.phone);
    await apiFetch(`/sessions/${sessionId}/contact`, {
      method: "PATCH",
      token,
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
    setStep("uploads");
  };

  const requiredMissing = contact.first_name.trim() === "" || contact.last_name.trim() === "" || contact.email.trim() === "";

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
            onBlur={() => {
              if (!requiredMissing) {
                void saveContact();
              }
            }}
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
        <label className="block text-sm font-medium text-slate-800">
          Phone
          <span className="mt-1 flex items-center gap-2">
            <span className="text-sm text-slate-500">+1</span>
            <input
              className="w-full rounded-md border border-slate-300 px-3 py-2"
              onChange={(event) => update("phone", event.target.value)}
              value={contact.phone}
            />
          </span>
        </label>
      </div>
      <FieldError message={requiredMissing ? "First name, last name, and email are required." : null} />
      <button
        className="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:bg-slate-300"
        disabled={requiredMissing}
        onClick={() => {
          void saveContact();
        }}
        type="button"
      >
        Continue to uploads
      </button>
    </section>
  );
}
