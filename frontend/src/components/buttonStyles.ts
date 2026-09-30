/** Shared push-button styles so CTAs get a consistent, minimal hover affordance. */
const transition = "transition-colors duration-150";

export const btnPrimaryClasses =
  `rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white ${transition} ` +
  "hover:bg-slate-800 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:hover:bg-slate-300";

export const btnPrimaryBlockClasses = `block ${btnPrimaryClasses}`;

export const btnSecondaryClasses =
  `rounded-md border border-slate-300 bg-white px-4 py-2 text-sm text-slate-800 ${transition} hover:bg-slate-50`;

export const btnSecondaryCompactClasses =
  `rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-800 ${transition} hover:bg-slate-50`;

export const btnDangerOutlineClasses =
  `rounded-md border border-red-300 px-4 py-2 text-sm text-red-700 ${transition} ` +
  "hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-transparent";

export const btnDangerCompactClasses =
  `rounded-md border border-red-300 px-2 py-0.5 text-xs text-red-700 ${transition} hover:bg-red-50`;

export const btnTextLinkClasses =
  `text-sm text-slate-600 underline ${transition} hover:text-slate-900`;
