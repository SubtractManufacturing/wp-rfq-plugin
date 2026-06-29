import { useForm } from "../state/FormContext";
import type { StepId } from "../types/manifest";
import { isDevFixtureMode } from "../mocks/devConfig";
import { canReachStep } from "../lib/navigation";

const steps: Array<{ id: StepId; label: string }> = [
  { id: "contact", label: "Contact" },
  { id: "uploads", label: "Uploads" },
  { id: "partMeta", label: "Part details" },
  { id: "global", label: "RFQ details" },
  { id: "review", label: "Review" },
];

export function Stepper() {
  const { step, setStep, contactSaved, parts } = useForm();
  const currentIndex = steps.findIndex((item) => item.id === step);

  return (
    <nav aria-label="RFQ progress" className="mb-6">
      <p className="mb-3 text-sm font-medium text-slate-600">Step {currentIndex + 1} of {steps.length}</p>
      <ol className="grid gap-2 sm:grid-cols-5">
        {steps.map((item, index) => {
          const reachable = isDevFixtureMode() || canReachStep(item.id, contactSaved, parts);
          const isCurrent = index === currentIndex;
          const isComplete = index < currentIndex;

          return (
            <li key={item.id}>
              <button
                className={`w-full rounded-full px-3 py-2 text-center text-sm ${
                  isCurrent
                    ? "bg-slate-900 text-white"
                    : isComplete
                      ? "bg-slate-200 text-slate-800"
                      : reachable
                        ? "bg-slate-100 text-slate-700 hover:bg-slate-200"
                        : "cursor-not-allowed bg-slate-50 text-slate-400"
                }`}
                disabled={isCurrent || !reachable}
                onClick={() => setStep(item.id)}
                type="button"
              >
                {item.label}
              </button>
            </li>
          );
        })}
      </ol>
    </nav>
  );
}
