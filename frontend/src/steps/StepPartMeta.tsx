import { useState } from "react";
import { MaterialField } from "../components/MaterialField";
import { ToleranceField } from "../components/ToleranceField";
import { partDisplayName } from "../lib/partLabel";
import { useForm } from "../state/FormContext";
import type { PartRow } from "../types/manifest";

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

function otherSectionOpen(part: PartRow): boolean {
  return (
    part.tolerance === "custom"
    || (part.tolerance_detail?.trim() ?? "") !== ""
    || (part.threads_features?.trim() ?? "") !== ""
    || part.target_unit_price !== null
  );
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
        <p className="mt-2 text-sm text-slate-600">
          Add material, tolerance, and quantity for each part. Notes are optional.
        </p>
      </div>
      {parts.map((part, index) => (
          <div className="rounded-lg border border-slate-200 p-4" key={part.part_id}>
            <h2 className="font-medium text-slate-900">{partDisplayName(part, index)}</h2>
            <MaterialField
              id={`material-${part.part_id}`}
              label="Material"
              materials={config.materials}
              onChange={(material) => updatePart(part.part_id, { material })}
              value={part.material}
            />
            <ToleranceField
              id={`tolerance-${part.part_id}`}
              label="Tolerance"
              onChange={(tolerance) => updatePart(part.part_id, { tolerance })}
              value={part.tolerance}
            />
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
              Notes
              <textarea
                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
                onChange={(event) => updatePart(part.part_id, { notes: event.target.value || null })}
                value={part.notes ?? ""}
              />
            </label>
            <details
              className="mt-3 rounded-md border border-slate-200 p-3"
              key={`${part.part_id}-${otherSectionOpen(part)}`}
              open={otherSectionOpen(part) ? true : undefined}
            >
              <summary className="cursor-pointer text-sm font-medium text-slate-800">Other</summary>
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
            </details>
          </div>
      ))}
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
