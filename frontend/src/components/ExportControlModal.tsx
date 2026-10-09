import { useEffect, useId, useRef, useState, type KeyboardEvent } from "react";
import { btnPrimaryClasses } from "./buttonStyles";

interface ExportControlModalProps {
  contactEmail: string;
  onAcknowledge: () => void;
}

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled])';

/**
 * Blocking notice shown the first time a customer reaches the uploads step.
 * It can only be dismissed by confirming the files are not export-controlled
 * (no Escape or backdrop dismissal).
 */
export function ExportControlModal({ contactEmail, onAcknowledge }: ExportControlModalProps) {
  const [confirmed, setConfirmed] = useState(false);
  // Browsers disagree on whether a mouse click on a checkbox matches :focus-visible, so track the input
  // modality ourselves: no focus ring after a pointer interaction, ring again as soon as a key is pressed.
  const [pointerFocus, setPointerFocus] = useState(false);
  const noRing = pointerFocus ? "focus:outline-none" : "";
  const dialogRef = useRef<HTMLDivElement | null>(null);
  const titleId = useId();
  const descriptionId = useId();

  // Move focus into the dialog (not onto the checkbox, which would draw a focus ring on it).
  useEffect(() => {
    dialogRef.current?.focus();
  }, []);

  // Keep keyboard focus inside the dialog while it is open.
  const trapFocus = (event: KeyboardEvent<HTMLDivElement>) => {
    setPointerFocus(false);
    if (event.key !== "Tab") {
      return;
    }
    const focusable = Array.from(dialogRef.current?.querySelectorAll<HTMLElement>(FOCUSABLE) ?? []);
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (!first || !last) {
      return;
    }
    const active = document.activeElement;
    if (event.shiftKey && (active === first || active === dialogRef.current)) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && active === last) {
      event.preventDefault();
      first.focus();
    }
  };

  return (
    <div className="fixed inset-0 z-[99999] flex items-center justify-center bg-slate-950/60 p-4">
      <div
        aria-describedby={descriptionId}
        aria-labelledby={titleId}
        aria-modal="true"
        className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl outline-none focus:outline-none"
        onKeyDown={trapFocus}
        onPointerDown={() => setPointerFocus(true)}
        ref={dialogRef}
        role="dialog"
        tabIndex={-1}
      >
        <h2 className="text-xl font-semibold text-slate-950" id={titleId}>
          Do not upload controlled files
        </h2>
        <div className="mt-3 space-y-3 text-sm text-slate-700" id={descriptionId}>
          <p>
            This form is not approved for export-controlled data. Do not upload files or drawings that are
            subject to <strong>ITAR</strong>, <strong>EAR</strong>, or any other export control or
            handling restriction.
          </p>
          <p>
            If your project includes controlled files, contact Subtract directly at{" "}
            <a className="font-medium text-slate-900 underline" href={`mailto:${contactEmail}`}>
              {contactEmail}
            </a>{" "}
            and we will arrange a secure way to send them.
          </p>
        </div>

        <label className="mt-5 flex cursor-pointer items-start gap-3 text-sm text-slate-900">
          <input
            checked={confirmed}
            className={`mt-0.5 h-4 w-4 shrink-0 ${noRing}`}
            onChange={(event) => setConfirmed(event.target.checked)}
            type="checkbox"
          />
          <span>
            I confirm that the files I upload are not subject to ITAR, EAR, or any other export control
            restriction.
          </span>
        </label>

        <div className="mt-6 flex justify-end">
          <button
            className={`${btnPrimaryClasses} ${noRing}`}
            disabled={!confirmed}
            onClick={onAcknowledge}
            type="button"
          >
            Agree and continue
          </button>
        </div>
      </div>
    </div>
  );
}
