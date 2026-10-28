import { useEffect, useRef } from "react";
import { canReachStep } from "../lib/navigation";
import { useForm } from "../state/FormContext";
import type { StepId } from "../types/manifest";

/**
 * Gives each form step a browser history entry, so the browser Back and Forward
 * buttons move between steps instead of leaving the page and losing the RFQ.
 */
export function useStepHistory() {
  const { step, setStep, contactSaved, parts } = useForm();
  const mounted = useRef(false);

  useEffect(() => {
    const entry = { rfqStep: step };
    if (!mounted.current) {
      mounted.current = true;
      window.history.replaceState(entry, "");
      return;
    }
    // A step restored by Back/Forward already matches its history entry.
    if (window.history.state?.rfqStep !== step) {
      window.history.pushState(entry, "");
    }
  }, [step]);

  useEffect(() => {
    const onPopState = (event: PopStateEvent) => {
      const target = (event.state as { rfqStep?: StepId } | null)?.rfqStep;
      if (target && canReachStep(target, contactSaved, parts)) {
        setStep(target);
      }
    };
    window.addEventListener("popstate", onPopState);
    return () => window.removeEventListener("popstate", onPopState);
  }, [contactSaved, parts, setStep]);
}
