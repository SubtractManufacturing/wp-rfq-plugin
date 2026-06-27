import { createContext, useContext, useMemo, useState, type ReactNode } from "react";
import type { RfqFormConfig } from "../types/config";
import {
  emptyContact,
  emptyGlobal,
  type ContactState,
  type GlobalState,
  type PartRow,
  type StepId,
} from "../types/manifest";

export interface FormState {
  token: string;
  sessionId: string;
  step: StepId;
  contact: ContactState;
  contactSaved: boolean;
  parts: PartRow[];
  global: GlobalState;
  submitError: string | null;
  tokenWarning: boolean;
  draftStatus: "idle" | "saving" | "saved" | "error";
  receiptNumber: string | null;
}

interface FormContextValue extends FormState {
  config: RfqFormConfig;
  setToken: (token: string) => void;
  setStep: (step: StepId) => void;
  setContact: (contact: ContactState) => void;
  setContactSaved: (saved: boolean) => void;
  setParts: (parts: PartRow[]) => void;
  setGlobal: (global: GlobalState) => void;
  setSubmitError: (message: string | null) => void;
  setTokenWarning: (warning: boolean) => void;
  setDraftStatus: (status: FormState["draftStatus"]) => void;
  setReceiptNumber: (receiptNumber: string | null) => void;
}

const FormContext = createContext<FormContextValue | null>(null);

export function FormProvider({
  children,
  config,
  sessionId,
  token,
}: {
  children: ReactNode;
  config: RfqFormConfig;
  sessionId: string;
  token: string;
}) {
  const [currentToken, setToken] = useState(token);
  const [step, setStep] = useState<StepId>("contact");
  const [contact, setContact] = useState<ContactState>(emptyContact);
  const [contactSaved, setContactSaved] = useState(false);
  const [parts, setParts] = useState<PartRow[]>([]);
  const [global, setGlobal] = useState<GlobalState>(emptyGlobal);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [tokenWarning, setTokenWarning] = useState(false);
  const [draftStatus, setDraftStatus] = useState<FormState["draftStatus"]>("idle");
  const [receiptNumber, setReceiptNumber] = useState<string | null>(null);

  const value = useMemo<FormContextValue>(
    () => ({
      config,
      sessionId,
      token: currentToken,
      step,
      contact,
      contactSaved,
      parts,
      global,
      submitError,
      tokenWarning,
      draftStatus,
      receiptNumber,
      setToken,
      setStep,
      setContact,
      setContactSaved,
      setParts,
      setGlobal,
      setSubmitError,
      setTokenWarning,
      setDraftStatus,
      setReceiptNumber,
    }),
    [config, sessionId, currentToken, step, contact, contactSaved, parts, global, submitError, tokenWarning, draftStatus, receiptNumber],
  );

  return <FormContext.Provider value={value}>{children}</FormContext.Provider>;
}

export function useForm() {
  const value = useContext(FormContext);
  if (!value) {
    throw new Error("useForm must be used inside FormProvider");
  }
  return value;
}
