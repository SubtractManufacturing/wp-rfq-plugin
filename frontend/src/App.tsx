import { useLayoutEffect, useRef } from "react";
import { SuccessView } from "./SuccessView";
import { AirtableFallback } from "./components/AirtableFallback";
import { DraftSaveIndicator } from "./components/DraftSaveIndicator";
import { FormLoading } from "./components/FormLoading";
import { Stepper } from "./components/Stepper";
import { useAppHealthStartup } from "./hooks/useAppHealthStartup";
import { useAutosave } from "./hooks/useAutosave";
import { useJwtRefresh } from "./hooks/useJwtRefresh";
import { useLeaveWarning } from "./hooks/useLeaveWarning";
import { useStepHistory } from "./hooks/useStepHistory";
import { FormProvider, useForm } from "./state/FormContext";
import { StepContact } from "./steps/StepContact";
import { StepGlobal } from "./steps/StepGlobal";
import { StepPartMeta } from "./steps/StepPartMeta";
import { StepReview } from "./steps/StepReview";
import { StepUploads } from "./steps/StepUploads";
import type { RfqFormConfig } from "./types/config";

/** Gap left above the card when a step change has scrolled it off the top of the window. */
const FORM_TOP_MARGIN = 16;

export function App({
  config,
  fetchImpl = fetch,
}: {
  config: RfqFormConfig;
  fetchImpl?: typeof fetch;
}) {
  const startup = useAppHealthStartup({ restBase: config.restBase, fetchImpl });

  if (startup.status === "fallback") {
    return <AirtableFallback src={config.airtableEmbedUrl} />;
  }

  if (startup.status === "loading") {
    return <FormLoading />;
  }

  return (
    <FormProvider config={config}>
      <FormShell fetchImpl={fetchImpl} />
    </FormProvider>
  );
}

export function FormShell({ fetchImpl }: { fetchImpl: typeof fetch }) {
  const {
    config,
    contact,
    receiptNumber,
    setToken,
    setTokenWarning,
    startNewRequest,
    token,
    sessionId,
    step,
  } = useForm();
  const formRef = useRef<HTMLDivElement>(null);
  const previousStep = useRef(step);
  useJwtRefresh({ token, sessionId, onToken: setToken, onWarning: setTokenWarning });
  useAutosave({ fetchImpl });
  useStepHistory();
  useLeaveWarning(sessionId !== null && !receiptNumber);

  useLayoutEffect(() => {
    if (previousStep.current === step) {
      return;
    }
    previousStep.current = step;

    const form = formRef.current;
    if (!form) {
      return;
    }
    // A shorter step clamps the window toward the footer. If the card is still
    // on screen, leave the scroll position alone.
    const top = form.getBoundingClientRect().top;
    if (top >= 0) {
      return;
    }

    const root = document.documentElement;
    const previous = root.style.scrollBehavior;
    // Inline `auto` wins over a theme's `html { scroll-behavior: smooth }`.
    root.style.scrollBehavior = "auto";
    try {
      window.scrollBy(0, top - FORM_TOP_MARGIN);
    } finally {
      root.style.scrollBehavior = previous;
    }
  }, [step]);

  if (receiptNumber) {
    return (
      <SuccessView
        email={contact.email}
        onStartNew={startNewRequest}
        receiptNumber={receiptNumber}
        supportEmail={config.salesContactEmail || undefined}
      />
    );
  }

  return (
    <div ref={formRef} className="mx-auto max-w-4xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
      <Stepper />
      <DraftSaveIndicator />
      {step === "contact" ? <StepContact fetchImpl={fetchImpl} /> : null}
      {step === "uploads" ? <StepUploads fetchImpl={fetchImpl} /> : null}
      {step === "partMeta" ? <StepPartMeta /> : null}
      {step === "global" ? <StepGlobal /> : null}
      {step === "review" ? <StepReview fetchImpl={fetchImpl} /> : null}
    </div>
  );
}
