import { useId, useMemo, useRef, useState } from "react";
import type { MaterialOption } from "../types/config";
import { materialSuggestions } from "../lib/materials";

export function MaterialField({
  id,
  label,
  materials,
  value,
  onChange,
}: {
  id: string;
  label: string;
  materials: MaterialOption[];
  value: string;
  onChange: (next: string) => void;
}) {
  const listboxId = useId();
  const containerRef = useRef<HTMLDivElement>(null);
  const [open, setOpen] = useState(false);
  const [activeIndex, setActiveIndex] = useState(-1);

  const suggestions = useMemo(() => materialSuggestions(value, materials), [materials, value]);

  const showList = open && suggestions.length > 0;

  const selectSuggestion = (next: string) => {
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
          className="w-full rounded-md border border-slate-300 px-3 py-2"
          id={id}
          onBlur={(event) => {
            if (containerRef.current?.contains(event.relatedTarget as Node)) {
              return;
            }
            closeList();
          }}
          onChange={(event) => {
            onChange(event.target.value);
            setOpen(true);
            setActiveIndex(-1);
          }}
          onFocus={() => {
            setOpen(true);
            setActiveIndex(-1);
          }}
          onKeyDown={(event) => {
            if (!showList) {
              if (event.key === "ArrowDown" || event.key === "ArrowUp") {
                setOpen(true);
              }
              return;
            }

            if (event.key === "ArrowDown") {
              event.preventDefault();
              setActiveIndex((current) => (current + 1) % suggestions.length);
              return;
            }

            if (event.key === "ArrowUp") {
              event.preventDefault();
              setActiveIndex((current) => (current <= 0 ? suggestions.length - 1 : current - 1));
              return;
            }

            if (event.key === "Enter" && activeIndex >= 0) {
              event.preventDefault();
              selectSuggestion(suggestions[activeIndex] ?? value);
              return;
            }

            if (event.key === "Escape") {
              event.preventDefault();
              closeList();
            }
          }}
          role="combobox"
          type="text"
          value={value}
        />
        {showList ? (
          <ul
            className="absolute z-20 mt-1 max-h-48 w-full overflow-auto rounded-md border border-slate-300 bg-white py-1 shadow-lg"
            id={listboxId}
            role="listbox"
          >
            {suggestions.map((suggestion, index) => (
              <li
                aria-selected={index === activeIndex}
                className={`cursor-pointer px-3 py-2 text-sm text-slate-900 ${
                  index === activeIndex ? "bg-slate-100" : "hover:bg-slate-50"
                }`}
                key={suggestion}
                onMouseDown={(event) => event.preventDefault()}
                onMouseEnter={() => setActiveIndex(index)}
                onMouseUp={() => selectSuggestion(suggestion)}
                role="option"
              >
                {suggestion}
              </li>
            ))}
          </ul>
        ) : null}
      </div>
    </label>
  );
}
