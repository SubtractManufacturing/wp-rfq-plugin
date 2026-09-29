import { useState } from "react";
import { Combobox } from "../components/Combobox";
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
        <p className="mt-2 text-sm text-slate-600">Add material, tolerance, and quantity for each uploaded part.</p>
      </div>
      {parts.map((part, index) => {
        return (
          <div className="rounded-lg border border-slate-200 p-4" key={part.part_id}>
            <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
              Part {index + 1} of {parts.length}
            </p>
            <h2 className="mt-0.5 break-all text-lg font-semibold text-slate-900">
              {part.partFile?.filename ?? `Part ${index + 1}`}
            </h2>
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
            <label className="mt-3 block text-sm font-medium text-slate-800">
              Threads / features
              <input
                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
                onChange={(event) => updatePart(part.part_id, { threads_features: event.target.value || null })}
                value={part.threads_features ?? ""}
              />
            </label>
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
            <label className="mt-3 block text-sm font-medium text-slate-800">
              Target unit price (USD)
              <input
                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
                min={0}
                onChange={(event) => updateTargetPrice(part.part_id, event.target.value)}
                step="0.01"
                type="number"
                value={part.target_unit_price ?? ""}
              />
              <span className="mt-1 block text-xs text-slate-500">Optional — your target price per part in USD.</span>
            </label>
            {priceErrors[part.part_id] ? (
              <p className="mt-1 text-sm text-red-700">{priceErrors[part.part_id]}</p>
            ) : null}
            <label className="mt-3 block text-sm font-medium text-slate-800">
              Notes
              <textarea
                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
                onChange={(event) => updatePart(part.part_id, { notes: event.target.value || null })}
                value={part.notes ?? ""}
              />
            </label>
          </div>
        );
      })}
      <button
        className="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:bg-slate-300"
        disabled={!canContinue}
        onClick={() => setStep("global")}
        type="button"
      >
        Continue to RFQ details
      </button>
    </section>
  );
}
