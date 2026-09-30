import type { SelectHTMLAttributes } from "react";
import { ChevronIcon } from "./ChevronIcon";
import { dropdownChevronClasses, dropdownControlClasses } from "./fieldStyles";

interface SelectProps extends SelectHTMLAttributes<HTMLSelectElement> {
  /** Classes for the wrapper (spacing, width). */
  wrapperClassName?: string;
}

/** Native <select> with the shared dropdown styling and chevron. */
export function Select({ wrapperClassName = "", className = "", children, ...props }: SelectProps) {
  return (
    <span className={`relative block ${wrapperClassName}`}>
      <select {...props} className={`${dropdownControlClasses} appearance-none ${className}`}>
        {children}
      </select>
      <ChevronIcon className={dropdownChevronClasses} />
    </span>
  );
}
