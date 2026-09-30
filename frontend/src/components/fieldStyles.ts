/**
 * Shared look for every dropdown-style control (native selects and the material
 * combobox) so they read as the same component. Explicit focus styles keep the
 * host WordPress theme from restyling them. Font size is set explicitly (matching
 * the text-sm labels the plain inputs inherit from) because the theme gives bare
 * inputs a much larger default, which made controls outside a label taller.
 */
export const dropdownControlClasses =
  "block w-full rounded-md border border-slate-300 bg-white px-3 py-2 pr-9 text-sm leading-5 text-slate-900 " +
  "focus:border-slate-900 focus:outline-none focus:ring-2 focus:ring-slate-900/20 disabled:bg-slate-50 disabled:text-slate-400";

export const dropdownChevronClasses = "pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500";
