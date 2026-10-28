import { useState } from "react";
import { btnPrimaryClasses, btnSecondaryClasses } from "../components/buttonStyles";
import { ChevronIcon } from "../components/ChevronIcon";
import { InfoTooltip } from "../components/InfoTooltip";
import { MaterialPicker } from "../components/MaterialPicker";
import { Select } from "../components/Select";
import { findMaterialByLabel, isOtherFinish } from "../lib/materials";
import {
  normalizeTolerance,
  parseToleranceValue,
  toleranceCategoryForMaterial,
  toleranceTierLabel,
} from "../lib/tolerances";
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

  const applyMaterialToAll = (source: PartRow) => {
    setParts(
      parts.map((part) => ({
        ...part,
        material: source.material,
        finish: source.finish ?? null,
        finish_color: source.finish_color ?? null,
        finish_specification: source.finish_specification ?? null,
        plastic_color: source.plastic_color ?? null,
        tolerance: source.tolerance,
        tolerance_detail: source.tolerance_detail,
        tolerance_value: source.tolerance_value ?? null,
        tolerance_unit: source.tolerance_unit ?? null,
      })),
    );
  };

  const handleMaterialChange = (part: PartRow, patch: Partial<PartRow>) => {
    const nextMaterial = patch.material ?? part.material;
    const wasCustomMaterial =
      part.material.trim() !== "" && findMaterialByLabel(part.material, config.materials) === null;
    let tolerancePatch: Partial<PartRow> = {};
    if (nextMaterial.trim() !== "" && findMaterialByLabel(nextMaterial, config.materials) === null) {
      // Custom materials get a typed tolerance, stored as the custom tier.
      tolerancePatch = { tolerance: "custom" };
    } else if (wasCustomMaterial && findMaterialByLabel(nextMaterial, config.materials) !== null) {
      // Back to a catalog material: return to the standard tier.
      tolerancePatch = { tolerance: "standard" };
    }
    updatePart(part.part_id, { ...patch, ...tolerancePatch });
  };

  const hasPriceErrors = Object.keys(priceErrors).length > 0;
  const canContinue = parts.length > 0 && parts.every((part) => {
    const isCustomMaterial =
      part.material.trim() !== "" && findMaterialByLabel(part.material, config.materials) === null;
    const hasToleranceDetail = isCustomMaterial
      ? parseToleranceValue(part.tolerance_value) !== null
      : part.tolerance !== "custom" || (part.tolerance_detail?.trim() ?? "") !== "";
    const hasFinishDetail = !isOtherFinish(part.finish) || (part.finish_specification?.trim() ?? "") !== "";
    return part.material.trim() !== "" && part.quantity >= 1 && hasToleranceDetail && hasFinishDetail;
  }) && !hasPriceErrors;

  return (
    <section className="space-y-5">
      <div>
        <h1 className="text-2xl font-semibold text-slate-950">Part details</h1>
        <p className="mt-2 text-sm text-slate-600">
          Add quantity, material, finish, and global tolerance for each uploaded part. Pick a material group to get started,
          or search if you already know the alloy you need.
        </p>
      </div>
      {parts.map((part, index) => {
        const leaf = findMaterialByLabel(part.material, config.materials);
        const hasCompleteMaterial = part.material.trim() !== "";
        const isCustomMaterial = hasCompleteMaterial && leaf === null;
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
            <div className="mt-3 border-t border-slate-100 pt-3">
              <MaterialPicker
                idPrefix={`material-${part.part_id}`}
                materials={config.materials}
                onChange={(patch) => handleMaterialChange(part, patch)}
                value={part}
              />
              {parts.length > 1 && part.material.trim() !== "" ? (
                <button
                  className="mt-3 text-sm text-slate-600 underline transition-colors duration-150 hover:text-slate-900"
                  onClick={() => applyMaterialToAll(part)}
                  type="button"
                >
                  Use this material, finish &amp; tolerance for all parts
                </button>
              ) : null}
            </div>
            {hasCompleteMaterial && isCustomMaterial ? (
              <div className="mt-3">
                <label className="block text-sm font-medium text-slate-800" htmlFor={`tolerance-value-${part.part_id}`}>
                  Global Tolerance
                </label>
                <div className="mt-1 flex gap-2">
                  <span className="relative block flex-1">
                    <span aria-hidden="true" className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-slate-500">
                      ±
                    </span>
                    <input
                      className="w-full rounded-md border border-slate-300 py-2 pl-8 pr-3"
                      id={`tolerance-value-${part.part_id}`}
                      inputMode="decimal"
                      onChange={(event) =>
                        updatePart(part.part_id, {
                          tolerance: "custom",
                          tolerance_value: event.target.value.replace(/^±/, "").replace(/^\+-\//, ""),
                        })
                      }
                      placeholder="0.005"
                      value={part.tolerance_value ?? ""}
                    />
                  </span>
                  <Select
                    aria-label="Tolerance unit"
                    onChange={(event) =>
                      updatePart(part.part_id, { tolerance_unit: event.target.value as "in" | "mm" })
                    }
                    value={part.tolerance_unit ?? "in"}
                  >
                    <option value="in">Inches (")</option>
                    <option value="mm">Millimeters (mm)</option>
                  </Select>
                </div>
              </div>
            ) : null}
            {hasCompleteMaterial && !isCustomMaterial ? (
              <>
                <label className="mt-3 block text-sm font-medium text-slate-800">
                  Global Tolerance
                  <Select
                    onChange={(event) => updatePart(part.part_id, { tolerance: event.target.value as Tolerance })}
                    value={normalizeTolerance(part.tolerance)}
                    wrapperClassName="mt-1"
                  >
                    {(() => {
                      const category = toleranceCategoryForMaterial(part.material, config.materials);
                      return (
                        <>
                          <option value="economy">{toleranceTierLabel("economy", category)}</option>
                          <option value="standard">{toleranceTierLabel("standard", category)}</option>
                          <option value="tight">{toleranceTierLabel("tight", category)}</option>
                          <option value="custom">Custom</option>
                        </>
                      );
                    })()}
                  </Select>
                </label>
                {part.tolerance === "custom" ? (
                  <label className="mt-3 block text-sm font-medium text-slate-800">
                    Custom tolerance detail
                    <input
                      className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
                      onChange={(event) => updatePart(part.part_id, { tolerance_detail: event.target.value })}
                      value={part.tolerance_detail ?? ""}
                    />
                  </label>
                ) : null}
              </>
            ) : null}
            <div className="mt-3">
              <div className="flex items-center gap-1.5">
                <label className="text-sm font-medium text-slate-800" htmlFor={`notes-${part.part_id}`}>
                  Notes &amp; Details
                </label>
                <InfoTooltip id={`notes-help-${part.part_id}`} label="What to include in Notes & Details">
                  <p>Use this field for anything not covered by material, finish, and global tolerance above, like:</p>
                  <ul className="mt-1.5 space-y-0.5 pl-4">
                    <li className="list-disc">Threads or special features</li>
                    <li className="list-disc">Masking or postprocessing requirements</li>
                    <li className="list-disc">Inspection or certification needs</li>
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
                      Optional - your target price per part in USD.
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
      <div className="flex flex-wrap gap-3">
        <button className={btnSecondaryClasses} onClick={() => setStep("uploads")} type="button">
          Back
        </button>
        <button
          className={btnPrimaryClasses}
          disabled={!canContinue}
          onClick={() => setStep("global")}
          type="button"
        >
          Continue to RFQ details
        </button>
      </div>
    </section>
  );
}
