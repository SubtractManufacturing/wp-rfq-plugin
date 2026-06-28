import { useEffect, useState } from "react";
import { SuccessView } from "./SuccessView";
import { apiFetch } from "./api/client";
import { AirtableFallback } from "./components/AirtableFallback";
import { DraftSaveIndicator } from "./components/DraftSaveIndicator";
import { Stepper } from "./components/Stepper";
import { useAutosave } from "./hooks/useAutosave";
import { useJwtRefresh } from "./hooks/useJwtRefresh";
import { FormProvider, useForm } from "./state/FormContext";
import { StepContact } from "./steps/StepContact";
import { StepGlobal } from "./steps/StepGlobal";
import { StepPartMeta } from "./steps/StepPartMeta";
import { StepReview } from "./steps/StepReview";
import { StepUploads } from "./steps/StepUploads";
import type { RfqFormConfig } from "./types/config";

type StartupState = { status: "loading" } | { status: "fallback" } | { status: "ready" };

export function App({
  config,
  fetchImpl = fetch,
}: {
  config: RfqFormConfig;
  fetchImpl?: typeof fetch;
}) {
  const [startup, setStartup] = useState<StartupState>({ status: "loading" });

  useEffect(() => {
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), 3000);

    async function start() {
      try {
        await apiFetch<{ status: string }>("/health", {
          method: "GET",
          restBase: config.restBase,
          fetchImpl,
          signal: controller.signal,
        });
        setStartup({ status: "ready" });
      } catch {
        setStartup({ status: "fallback" });
      } finally {
        window.clearTimeout(timer);
      }
    }

    void start();
    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [config.restBase, fetchImpl]);

  if (startup.status === "fallback") {
    return <AirtableFallback src={config.airtableEmbedUrl} />;
  }

  if (startup.status === "loading") {
    return <p className="p-4 text-center text-sm text-slate-600">Loading RFQ form...</p>;
  }

  return (
    <FormProvider config={config}>
      <FormShell fetchImpl={fetchImpl} />
    </FormProvider>
  );
}

export function FormShell({ fetchImpl }: { fetchImpl: typeof fetch }) {
  const { receiptNumber, setToken, setTokenWarning, token, sessionId, step } = useForm();
  useJwtRefresh({ token, sessionId, onToken: setToken, onWarning: setTokenWarning });
  useAutosave({ fetchImpl });

  if (receiptNumber) {
    return <SuccessView receiptNumber={receiptNumber} />;
  }

  return (
    <div className="mx-auto max-w-4xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
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
