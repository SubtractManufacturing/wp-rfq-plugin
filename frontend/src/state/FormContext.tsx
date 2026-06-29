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

interface FormBootstrap {
  sessionId: string;
  token: string;
  contact: ContactState;
  contactSaved: boolean;
  parts: PartRow[];
  global: GlobalState;
}

export interface FormState {
  token: string | null;
  sessionId: string | null;
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
  setSession: (sessionId: string, token: string) => void;
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
  bootstrap,
  sessionId: initialSessionId = bootstrap?.sessionId ?? null,
  token: initialToken = bootstrap?.token ?? null,
}: {
  children: ReactNode;
  config: RfqFormConfig;
  bootstrap?: FormBootstrap;
  sessionId?: string | null;
  token?: string | null;
}) {
  const [sessionId, setSessionId] = useState<string | null>(initialSessionId);
  const [currentToken, setToken] = useState<string | null>(initialToken);
  const [step, setStep] = useState<StepId>("contact");
  const [contact, setContact] = useState<ContactState>(bootstrap?.contact ?? emptyContact);
  const [contactSaved, setContactSaved] = useState(bootstrap?.contactSaved ?? false);
  const [parts, setParts] = useState<PartRow[]>(bootstrap?.parts ?? []);
  const [global, setGlobal] = useState<GlobalState>(bootstrap?.global ?? emptyGlobal);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [tokenWarning, setTokenWarning] = useState(false);
  const [draftStatus, setDraftStatus] = useState<FormState["draftStatus"]>("idle");
  const [receiptNumber, setReceiptNumber] = useState<string | null>(null);

  const setSession = (nextSessionId: string, nextToken: string) => {
    setSessionId(nextSessionId);
    setToken(nextToken);
  };

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
      setSession,
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
