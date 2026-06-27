import { useForm } from "../state/FormContext";
import type { StepId } from "../types/manifest";

const steps: Array<{ id: StepId; label: string }> = [
  { id: "contact", label: "Contact" },
  { id: "uploads", label: "Uploads" },
  { id: "partMeta", label: "Part details" },
  { id: "global", label: "RFQ details" },
  { id: "review", label: "Review" },
];

export function Stepper() {
  const { step } = useForm();
  const currentIndex = steps.findIndex((item) => item.id === step);

  return (
    <nav aria-label="RFQ progress" className="mb-6">
      <p className="mb-3 text-sm font-medium text-slate-600">Step {currentIndex + 1} of {steps.length}</p>
      <ol className="grid gap-2 sm:grid-cols-5">
        {steps.map((item, index) => (
          <li
            className={`rounded-full px-3 py-2 text-center text-sm ${
              index === currentIndex ? "bg-slate-900 text-white" : "bg-slate-100 text-slate-700"
            }`}
            key={item.id}
          >
            {item.label}
          </li>
        ))}
      </ol>
    </nav>
  );
}
