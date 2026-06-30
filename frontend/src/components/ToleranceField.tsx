import { useId, useRef, useState } from "react";
import type { Tolerance } from "../types/manifest";

const TOLERANCE_OPTIONS: Array<{ value: Tolerance; label: string }> = [
  { value: "standard", label: "Standard" },
  { value: "precision", label: "Precision" },
  { value: "custom", label: "Custom" },
];

function labelForTolerance(value: Tolerance): string {
  return TOLERANCE_OPTIONS.find((option) => option.value === value)?.label ?? value;
}

export function ToleranceField({
  id,
  label,
  value,
  onChange,
}: {
  id: string;
  label: string;
  value: Tolerance;
  onChange: (next: Tolerance) => void;
}) {
  const listboxId = useId();
  const containerRef = useRef<HTMLDivElement>(null);
  const [open, setOpen] = useState(false);
  const [activeIndex, setActiveIndex] = useState(-1);

  const showList = open;

  const selectOption = (next: Tolerance) => {
    onChange(next);
    setOpen(false);
    setActiveIndex(-1);
  };

  const closeList = () => {
    setOpen(false);
    setActiveIndex(-1);
  };

  return (
    <label className="mt-3 block text-sm font-medium text-slate-800" htmlFor={id}>
      {label}
      <div className="relative mt-1" ref={containerRef}>
        <input
          aria-autocomplete="list"
          aria-controls={showList ? listboxId : undefined}
          aria-expanded={showList}
          className="w-full cursor-pointer rounded-md border border-slate-300 px-3 py-2"
          id={id}
          onBlur={(event) => {
            if (containerRef.current?.contains(event.relatedTarget as Node)) {
              return;
            }
            closeList();
          }}
          onClick={() => {
            setOpen(true);
            setActiveIndex(TOLERANCE_OPTIONS.findIndex((option) => option.value === value));
          }}
          onFocus={() => {
            setOpen(true);
            setActiveIndex(TOLERANCE_OPTIONS.findIndex((option) => option.value === value));
          }}
          onKeyDown={(event) => {
            if (!showList) {
              if (event.key === "ArrowDown" || event.key === "ArrowUp" || event.key === "Enter" || event.key === " ") {
                event.preventDefault();
                setOpen(true);
                setActiveIndex(TOLERANCE_OPTIONS.findIndex((option) => option.value === value));
              }
              return;
            }

            if (event.key === "ArrowDown") {
              event.preventDefault();
              setActiveIndex((current) => (current + 1) % TOLERANCE_OPTIONS.length);
              return;
            }

            if (event.key === "ArrowUp") {
              event.preventDefault();
              setActiveIndex((current) => (current <= 0 ? TOLERANCE_OPTIONS.length - 1 : current - 1));
              return;
            }

            if ((event.key === "Enter" || event.key === " ") && activeIndex >= 0) {
              event.preventDefault();
              selectOption(TOLERANCE_OPTIONS[activeIndex]?.value ?? value);
              return;
            }

            if (event.key === "Escape") {
              event.preventDefault();
              closeList();
            }
          }}
          readOnly
          role="combobox"
          type="text"
          value={labelForTolerance(value)}
        />
        {showList ? (
          <ul
            className="absolute z-20 mt-1 max-h-48 w-full overflow-auto rounded-md border border-slate-300 bg-white py-1 shadow-lg"
            id={listboxId}
            role="listbox"
          >
            {TOLERANCE_OPTIONS.map((option, index) => (
              <li
                aria-selected={option.value === value}
                className={`cursor-pointer px-3 py-2 text-sm text-slate-900 ${
                  index === activeIndex ? "bg-slate-100" : "hover:bg-slate-50"
                }`}
                key={option.value}
                onMouseDown={(event) => event.preventDefault()}
                onMouseEnter={() => setActiveIndex(index)}
                onMouseUp={() => selectOption(option.value)}
                role="option"
              >
                {option.label}
              </li>
            ))}
          </ul>
        ) : null}
      </div>
    </label>
  );
}
