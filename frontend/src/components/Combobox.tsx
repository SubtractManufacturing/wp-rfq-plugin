import { useRef, useState, type FocusEvent, type KeyboardEvent } from "react";
import { ChevronIcon } from "./ChevronIcon";
import { dropdownControlClasses } from "./fieldStyles";

interface ComboboxProps {
  id: string;
  value: string;
  onChange: (value: string) => void;
  /** Shown when the list is opened without typing (e.g. the common choices). */
  defaultOptions: string[];
  /** Used while the customer types; may return nothing. */
  search: (query: string) => string[];
  wrapperClassName?: string;
}

/**
 * Free-text input with a suggestion list. Styled like <Select>, but the customer
 * may always type a value that is not in the list.
 */
export function Combobox({ id, value, onChange, defaultOptions, search, wrapperClassName = "" }: ComboboxProps) {
  const rootRef = useRef<HTMLDivElement | null>(null);
  const inputRef = useRef<HTMLInputElement | null>(null);
  const [open, setOpen] = useState(false);
  const [typed, setTyped] = useState(false);
  const [activeIndex, setActiveIndex] = useState(-1);

  const options = Array.from(new Set(typed ? search(value) : defaultOptions));
  const expanded = open && options.length > 0;
  const listId = `${id}-listbox`;
  const optionId = (index: number) => `${id}-option-${index}`;

  const close = () => {
    setOpen(false);
    setActiveIndex(-1);
  };

  const openList = () => {
    setTyped(false);
    setOpen(true);
    setActiveIndex(-1);
  };

  const choose = (option: string) => {
    onChange(option);
    setTyped(false);
    close();
  };

  const handleKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    switch (event.key) {
      case "ArrowDown":
        event.preventDefault();
        if (!expanded) {
          openList();
        } else {
          setActiveIndex((index) => (index + 1) % options.length);
        }
        break;
      case "ArrowUp":
        if (expanded) {
          event.preventDefault();
          setActiveIndex((index) => (index <= 0 ? options.length - 1 : index - 1));
        }
        break;
      case "Enter": {
        const active = options[activeIndex];
        if (expanded && active !== undefined) {
          event.preventDefault();
          choose(active);
        }
        break;
      }
      case "Escape":
        if (expanded) {
          event.preventDefault();
          close();
        }
        break;
      default:
        break;
    }
  };

  const handleBlur = (event: FocusEvent<HTMLDivElement>) => {
    if (!rootRef.current?.contains(event.relatedTarget as Node | null)) {
      close();
    }
  };

  return (
    <div className={`relative ${wrapperClassName}`} onBlur={handleBlur} ref={rootRef}>
      <input
        aria-activedescendant={expanded && activeIndex >= 0 ? optionId(activeIndex) : undefined}
        aria-autocomplete="list"
        aria-controls={listId}
        aria-expanded={expanded}
        autoComplete="off"
        className={dropdownControlClasses}
        id={id}
        onChange={(event) => {
          onChange(event.target.value);
          setTyped(true);
          setOpen(true);
          setActiveIndex(-1);
        }}
        onClick={() => {
          if (!open) {
            openList();
          }
        }}
        onKeyDown={handleKeyDown}
        ref={inputRef}
        role="combobox"
        type="text"
        value={value}
      />
      <button
        aria-label="Show common options"
        className="absolute inset-y-0 right-0 flex w-9 items-center justify-center text-slate-500 transition-colors duration-150 hover:text-slate-900"
        onClick={() => {
          if (expanded) {
            close();
          } else {
            openList();
          }
          inputRef.current?.focus();
        }}
        onMouseDown={(event) => event.preventDefault()}
        tabIndex={-1}
        type="button"
      >
        <ChevronIcon className={`h-4 w-4 transition-transform ${expanded ? "rotate-180" : ""}`} />
      </button>
      {expanded ? (
        <ul
          className="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-md border border-slate-200 bg-white py-1 shadow-lg"
          id={listId}
          role="listbox"
        >
          {options.map((option, index) => (
            <li
              aria-selected={option === value}
              className={`cursor-pointer px-3 py-2 text-sm text-slate-900 ${
                index === activeIndex ? "bg-slate-100" : "hover:bg-slate-50"
              } ${option === value ? "font-semibold" : ""}`}
              id={optionId(index)}
              key={option}
              onClick={() => choose(option)}
              onMouseDown={(event) => event.preventDefault()}
              role="option"
            >
              {option}
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}
