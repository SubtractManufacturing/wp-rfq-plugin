import { useForm } from "../state/FormContext";

export function DraftSaveIndicator() {
  const { draftStatus, tokenWarning } = useForm();

  if (tokenWarning) {
    return <p className="text-sm text-amber-700">Session refresh failed. Draft autosave is paused.</p>;
  }

  if (draftStatus === "error") {
    return <p className="text-sm text-amber-700">Draft not saved.</p>;
  }

  if (draftStatus === "saving") {
    return <p className="text-sm text-slate-500">Saving draft...</p>;
  }

  if (draftStatus === "saved") {
    return <p className="text-sm text-slate-500">Draft saved.</p>;
  }

  return null;
}
