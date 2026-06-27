import { dropdownMaterials, searchMaterials } from "../lib/materials";
import { useForm } from "../state/FormContext";
import type { PartRow, Tolerance } from "../types/manifest";

export function StepPartMeta() {
  const { config, parts, setParts, setStep } = useForm();

  const updatePart = (partId: string, update: Partial<PartRow>) => {
    setParts(parts.map((part) => (part.part_id === partId ? { ...part, ...update } : part)));
  };

  const canContinue = parts.length > 0 && parts.every((part) => {
    const hasToleranceDetail = part.tolerance !== "custom" || (part.tolerance_detail?.trim() ?? "") !== "";
    return part.material.trim() !== "" && part.quantity >= 1 && hasToleranceDetail;
  });

  return (
    <section className="space-y-5">
      <div>
        <h1 className="text-2xl font-semibold text-slate-950">Part details</h1>
        <p className="mt-2 text-sm text-slate-600">Add material, tolerance, and quantity for each uploaded part.</p>
      </div>
      {parts.map((part, index) => {
        const suggestions = searchMaterials(part.material, config.materials);
        return (
          <div className="rounded-lg border border-slate-200 p-4" key={part.part_id}>
            <h2 className="font-medium text-slate-900">Part {index + 1}</h2>
            <label className="mt-3 block text-sm font-medium text-slate-800">
              Material
              <input
                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
                list={`materials-${part.part_id}`}
                onChange={(event) => updatePart(part.part_id, { material: event.target.value })}
                value={part.material}
              />
              <datalist id={`materials-${part.part_id}`}>
                {[...dropdownMaterials(config.materials), ...suggestions].map((label) => (
                  <option key={label} value={label} />
                ))}
              </datalist>
            </label>
            <label className="mt-3 block text-sm font-medium text-slate-800">
              Tolerance
              <select
                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
                onChange={(event) => updatePart(part.part_id, { tolerance: event.target.value as Tolerance })}
                value={part.tolerance}
              >
                <option value="standard">Standard</option>
                <option value="precision">Precision</option>
                <option value="custom">Custom</option>
              </select>
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
              Quantity
              <input
                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
                min={1}
                onChange={(event) => updatePart(part.part_id, { quantity: Number(event.target.value) })}
                type="number"
                value={part.quantity}
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
