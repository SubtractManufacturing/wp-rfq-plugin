import type { ReactNode } from "react";

interface InfoTooltipProps {
  /** Id of the tooltip bubble; also used by the field it describes via `aria-describedby`. */
  id: string;
  /** Accessible name for the trigger button. */
  label: string;
  children: ReactNode;
}

/**
 * Small "i" trigger that reveals help text on hover or keyboard focus.
 * The bubble stays in the DOM (visually hidden) so it can describe a field to assistive tech.
 */
export function InfoTooltip({ id, label, children }: InfoTooltipProps) {
  return (
    <span className="group relative inline-flex align-middle">
      <button
        aria-describedby={id}
        aria-label={label}
        className="rounded-full text-slate-400 transition-colors duration-150 hover:text-slate-700 focus-visible:text-slate-700"
        type="button"
      >
        <svg
          aria-hidden="true"
          className="h-4 w-4"
          fill="none"
          stroke="currentColor"
          strokeLinecap="round"
          strokeLinejoin="round"
          strokeWidth={1.75}
          viewBox="0 0 24 24"
        >
          <circle cx="12" cy="12" r="9" />
          <path d="M12 11v5" />
          <path d="M12 8h.01" />
        </svg>
      </button>
      {/* Padding (not margin) bridges the gap so the pointer can move onto the bubble without closing it. */}
      <span
        className="invisible absolute bottom-full left-1/2 z-20 -translate-x-8 pb-2 opacity-0 transition-opacity duration-150 group-focus-within:visible group-focus-within:opacity-100 group-hover:visible group-hover:opacity-100"
        id={id}
        role="tooltip"
      >
        <span className="block w-64 max-w-[80vw] rounded-md bg-slate-900 p-3 text-left text-xs font-normal leading-relaxed text-white shadow-lg">
          {children}
        </span>
      </span>
    </span>
  );
}
