import { useState } from "react";
import { btnPrimaryClasses } from "../components/buttonStyles";
import { ChevronIcon } from "../components/ChevronIcon";
import { Combobox } from "../components/Combobox";
import { InfoTooltip } from "../components/InfoTooltip";
import { Select } from "../components/Select";
import { dropdownMaterials, searchMaterials } from "../lib/materials";
import { useForm } from "../state/FormContext";
import type { PartRow, Tolerance } from "../types/manifest";

function parseTargetUnitPrice(value: string): number | null {
  const trimmed = value.trim();
  if (trimmed === "") {
    return null;
  }
  const parsed = Number(trimmed);
  if (!Number.isFinite(parsed) || parsed < 0) {
    return null;
  }
  return Math.round(parsed * 100) / 100;
}

export function StepPartMeta() {
  const { config, parts, setParts, setStep } = useForm();
  const [priceErrors, setPriceErrors] = useState<Record<string, string>>({});
  const [moreDetailsOpen, setMoreDetailsOpen] = useState<Record<string, boolean>>({});

  const updatePart = (partId: string, update: Partial<PartRow>) => {
    setParts(parts.map((part) => (part.part_id === partId ? { ...part, ...update } : part)));
  };

  const updateTargetPrice = (partId: string, rawValue: string) => {
    if (rawValue.trim() === "") {
      setPriceErrors((current) => {
        const next = { ...current };
        delete next[partId];
        return next;
      });
      updatePart(partId, { target_unit_price: null });
      return;
    }

    const parsed = parseTargetUnitPrice(rawValue);
    if (parsed === null) {
      setPriceErrors((current) => ({ ...current, [partId]: "Enter a valid price of 0 or greater." }));
      return;
    }

    setPriceErrors((current) => {
      const next = { ...current };
      delete next[partId];
      return next;
    });
    updatePart(partId, { target_unit_price: parsed });
  };

  const hasPriceErrors = Object.keys(priceErrors).length > 0;
  const canContinue = parts.length > 0 && parts.every((part) => {
    const hasToleranceDetail = part.tolerance !== "custom" || (part.tolerance_detail?.trim() ?? "") !== "";
    return part.material.trim() !== "" && part.quantity >= 1 && hasToleranceDetail;
  }) && !hasPriceErrors;

  return (
    <section className="space-y-5">
      <div>
        <h1 className="text-2xl font-semibold text-slate-950">Part details</h1>
        <p className="mt-2 text-sm text-slate-600">Add quantity, material, and tolerance for each uploaded part.</p>
      </div>
      {parts.map((part, index) => {
        const hasPriceError = Boolean(priceErrors[part.part_id]);
        // Stay open when a price is set or invalid so a hidden value/error never blocks Continue.
        const moreOpen = hasPriceError || (moreDetailsOpen[part.part_id] ?? part.target_unit_price !== null);
        return (
          <div className="rounded-lg border border-slate-200 p-4" key={part.part_id}>
            <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
              Part {index + 1} of {parts.length}
            </p>
            <h2 className="mt-0.5 break-all text-lg font-semibold text-slate-900">
              {part.partFile?.filename ?? `Part ${index + 1}`}
            </h2>
            <label className="mt-3 block text-sm font-medium text-slate-800">
              Quantity
              <input
                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
                min={1}
                onChange={(event) => updatePart(part.part_id, { quantity: Number(event.target.value) })}
                type="number"
                value={part.quantity}
              />
            </label>
            <div className="mt-3">
              <label className="block text-sm font-medium text-slate-800" htmlFor={`material-${part.part_id}`}>
                Material
              </label>
              <Combobox
                defaultOptions={dropdownMaterials(config.materials)}
                id={`material-${part.part_id}`}
                onChange={(material) => updatePart(part.part_id, { material })}
                search={(query) => searchMaterials(query, config.materials)}
                value={part.material}
                wrapperClassName="mt-1"
              />
            </div>
            <label className="mt-3 block text-sm font-medium text-slate-800">
              Tolerance
              <Select
                onChange={(event) => updatePart(part.part_id, { tolerance: event.target.value as Tolerance })}
                value={part.tolerance}
                wrapperClassName="mt-1"
              >
                <option value="standard">Standard</option>
                <option value="precision">Precision</option>
                <option value="custom">Custom</option>
              </Select>
            </label>
            {part.tolerance === "custom" ? (
              <label className="mt-3 block text-sm font-medium text-slate-800">
                Tolerance detail
                <input
                  className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
                  onChange={(event) => updatePart(part.part_id, { tolerance_detail: event.target.value })}
                  value={part.tolerance_detail ?? ""}
                />
              </label>
            ) : null}
            <div className="mt-3">
              <div className="flex items-center gap-1.5">
                <label className="text-sm font-medium text-slate-800" htmlFor={`notes-${part.part_id}`}>
                  Notes &amp; Details
                </label>
                <InfoTooltip id={`notes-help-${part.part_id}`} label="What to include in Notes & Details">
                  <p>Use this field to define any other requirements for these parts like:</p>
                  <ul className="mt-1.5 space-y-0.5 pl-4">
                    <li className="list-disc">Color specifications</li>
                    <li className="list-disc">Finishing specifications</li>
                    <li className="list-disc">Postprocessing requirements</li>
                    <li className="list-disc">Etc.</li>
                  </ul>
                </InfoTooltip>
              </div>
              <textarea
                aria-describedby={`notes-help-${part.part_id}`}
                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
                id={`notes-${part.part_id}`}
                onChange={(event) => updatePart(part.part_id, { notes: event.target.value || null })}
                value={part.notes ?? ""}
              />
            </div>
            <div className="mt-4 border-t border-slate-200 pt-3">
              <button
                aria-controls={`more-details-${part.part_id}`}
                aria-expanded={moreOpen}
                className="flex items-center gap-1 text-sm font-medium text-slate-700 transition-colors duration-150 hover:text-slate-950"
                onClick={() => setMoreDetailsOpen((current) => ({ ...current, [part.part_id]: !moreOpen }))}
                type="button"
              >
                <ChevronIcon className={`h-4 w-4 transition-transform ${moreOpen ? "rotate-180" : ""}`} />
                More details
              </button>
              {moreOpen ? (
                <div className="mt-3" id={`more-details-${part.part_id}`}>
                  <label className="block text-sm font-medium text-slate-800">
                    Target unit price (USD)
                    <input
                      className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
                      min={0}
                      onChange={(event) => updateTargetPrice(part.part_id, event.target.value)}
                      step="0.01"
                      type="number"
                      value={part.target_unit_price ?? ""}
                    />
                    <span className="mt-1 block text-xs text-slate-500">
                      Optional — your target price per part in USD.
                    </span>
                  </label>
                  {hasPriceError ? (
                    <p className="mt-1 text-sm text-red-700">{priceErrors[part.part_id]}</p>
                  ) : null}
                </div>
              ) : null}
            </div>
          </div>
        );
      })}
      <button
        className={btnPrimaryClasses}
        disabled={!canContinue}
        onClick={() => setStep("global")}
        type="button"
      >
        Continue to RFQ details
      </button>
    </section>
  );
}
