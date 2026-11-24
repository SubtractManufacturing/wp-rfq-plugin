import { useEffect, useRef, useState } from "react";
import type { MaterialOption } from "../types/config";
import type { PartRow } from "../types/manifest";
import {
  defaultFinish,
  findMaterialByLabel,
  finishNeedsColor,
  isOtherFinish,
  materialGroups,
  materialTypes,
  materialsIn,
  ungroupedMaterials,
} from "../lib/materials";

const CUSTOM_GROUP = "__custom";
const MORE_GROUP = "__more";

type MaterialPatch = Pick<
  PartRow,
  "material" | "finish" | "finish_color" | "finish_specification" | "plastic_color"
>;

interface MaterialPickerProps {
  /** Prefix used to keep element ids unique when several parts are on the page. */
  idPrefix: string;
  materials: MaterialOption[];
  value: MaterialPatch;
  onChange: (patch: Partial<MaterialPatch>) => void;
}

const chipBaseClasses =
  "rounded-md border px-3 py-2 text-sm font-medium transition-colors duration-150";
const chipSelectedClasses = "border-slate-900 bg-slate-900 text-white";
const chipUnselectedClasses = "border-slate-300 bg-white text-slate-800 hover:bg-slate-50";

/**
 * Guided material selection, ported from the Quoting 2.0 material menus:
 * group → type → alloy/grade, then the stock colors (some plastics) and finishes
 * that apply to the chosen material. The picked grade shows its typical use and
 * relative material/machining cost. "Other" keeps the free-text escape hatch the
 * manifest has always allowed.
 */
export function MaterialPicker({ idPrefix, materials, value, onChange }: MaterialPickerProps) {
  const leaf = findMaterialByLabel(value.material, materials);
  const [group, setGroup] = useState<string>(() =>
    leaf ? leafGroup(leaf, materials) : value.material.trim() !== "" ? CUSTOM_GROUP : "",
  );
  const [type, setType] = useState<string>(() => leaf?.type ?? "");
  const [customText, setCustomText] = useState<string>(() => (leaf ? "" : value.material));
  const customTextRef = useRef(customText);
  customTextRef.current = customText;

  // Keep the tree in sync when the material is set from outside this picker
  // (e.g. "Use for all parts" on another part card).
  useEffect(() => {
    const nextLeaf = findMaterialByLabel(value.material, materials);
    if (nextLeaf) {
      setGroup(leafGroup(nextLeaf, materials));
      setType(nextLeaf.type ?? "");
      return;
    }
    if (value.material.trim() !== "" && value.material !== customTextRef.current) {
      setGroup(CUSTOM_GROUP);
      setCustomText(value.material);
    }
  }, [value.material, materials]);

  if (materials.length === 0) {
    return (
      <label className="block text-sm font-medium text-slate-800" htmlFor={`${idPrefix}-custom`}>
        Material
        <input
          className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2"
          id={`${idPrefix}-custom`}
          onChange={(event) => onChange({ material: event.target.value })}
          placeholder="e.g. 6061 Aluminum"
          value={value.material}
        />
      </label>
    );
  }

  const groups = materialGroups(materials);
  const hasUngrouped = ungroupedMaterials(materials).length > 0;
  const types = group !== "" && group !== CUSTOM_GROUP && group !== MORE_GROUP ? materialTypes(materials, group) : [];
  const leaves =
    group === MORE_GROUP
      ? ungroupedMaterials(materials)
      : group !== "" && group !== CUSTOM_GROUP && (type !== "" || types.length === 0)
        ? materialsIn(materials, group, type)
        : [];
  const showLeafStep = leaves.length > 0;
  const finishes = leaf?.finishes ?? [];
  const colors = leaf?.colors ?? [];

  const selectLeaf = (entry: MaterialOption) => {
    const nextFinish = defaultFinish(entry, value.finish);
    setGroup(leafGroup(entry, materials));
    setType(entry.type ?? "");
    onChange({
      material: entry.label,
      plastic_color: null,
      finish: nextFinish,
      finish_color: nextFinish === value.finish ? (value.finish_color ?? null) : null,
      finish_specification: isOtherFinish(nextFinish) ? (value.finish_specification ?? null) : null,
    });
  };

  const selectGroup = (nextGroup: string) => {
    setGroup(nextGroup);
    setType("");
    if (nextGroup === CUSTOM_GROUP) {
      onChange({
        material: customText,
        plastic_color: null,
        finish: null,
        finish_color: null,
        finish_specification: null,
      });
      return;
    }
    // Changing group restarts the tree: nothing counts as chosen until a leaf is picked.
    onChange({ material: "", plastic_color: null, finish: null, finish_color: null, finish_specification: null });
  };

  const selectType = (nextType: string) => {
    setType(nextType);
    if (leaf) {
      onChange({ material: "", plastic_color: null, finish: null, finish_color: null, finish_specification: null });
    }
  };

  const startOver = () => {
    setGroup("");
    setType("");
    setCustomText("");
    onChange({ material: "", plastic_color: null, finish: null, finish_color: null, finish_specification: null });
  };

  const selectFinish = (finish: string) => {
    onChange({
      finish,
      finish_color: finish === value.finish ? (value.finish_color ?? null) : null,
      finish_specification: isOtherFinish(finish) ? (value.finish_specification ?? null) : null,
    });
  };

  return (
    <div>
      <div className="flex items-baseline justify-between gap-3">
        <p className="text-sm font-medium text-slate-800">Material</p>
        {value.material.trim() !== "" ? (
          <button
            className="text-sm text-slate-600 underline transition-colors duration-150 hover:text-slate-900"
            onClick={startOver}
            type="button"
          >
            Start over
          </button>
        ) : null}
      </div>

      <ChoiceStep title="Material group">
        {groups.map((name) => (
          <Chip key={name} label={name} selected={group === name} onClick={() => selectGroup(name)} />
        ))}
        {hasUngrouped ? (
          <Chip label="More materials" selected={group === MORE_GROUP} onClick={() => selectGroup(MORE_GROUP)} />
        ) : null}
        <Chip label="Other" selected={group === CUSTOM_GROUP} onClick={() => selectGroup(CUSTOM_GROUP)} />
      </ChoiceStep>

      {group === CUSTOM_GROUP ? (
        <div className="mt-4">
          <label className="block text-sm font-medium text-slate-800" htmlFor={`${idPrefix}-specify`}>
            Specify material
          </label>
          <input
            className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
            id={`${idPrefix}-specify`}
            maxLength={200}
            onChange={(event) => {
              setCustomText(event.target.value);
              onChange({ material: event.target.value });
            }}
            placeholder="Material, grade, and condition"
            value={customText}
          />
          <p className="mt-1 text-xs text-amber-800">This material requires manual quoting.</p>
        </div>
      ) : null}

      {types.length > 0 ? (
        <ChoiceStep title="Material type">
          {types.map((name) => (
            <Chip key={name} label={name} selected={type === name} onClick={() => selectType(name)} />
          ))}
        </ChoiceStep>
      ) : null}

      {showLeafStep ? (
        <ChoiceStep title="Alloy / grade">
          {leaves.map((entry) => (
            <Chip
              key={entry.id}
              label={entry.label}
              selected={leaf?.id === entry.id}
              onClick={() => selectLeaf(entry)}
            />
          ))}
        </ChoiceStep>
      ) : null}

      {leaf && colors.length > 0 ? (
        <ChoiceStep title="Plastic color">
          {/* A single stock color is the only choice, so "No preference" adds nothing. */}
          {colors.length > 1 ? (
            <Chip
              label="No preference"
              selected={(value.plastic_color ?? null) === null}
              onClick={() => onChange({ plastic_color: null })}
            />
          ) : null}
          {colors.map((color) => (
            <Chip
              key={color}
              label={color}
              selected={value.plastic_color === color}
              onClick={() => onChange({ plastic_color: color })}
            />
          ))}
        </ChoiceStep>
      ) : null}

      {leaf && (leaf.guidance || hasCostRatings(leaf)) ? (
        <div aria-atomic="true" aria-live="polite" className="mt-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
          {leaf.guidance ? <p className="text-sm text-slate-700">{leaf.guidance}</p> : null}
          {hasCostRatings(leaf) ? (
            <div className="mt-2 grid grid-cols-1 gap-3 min-[421px]:grid-cols-2 min-[421px]:gap-x-5">
              {leaf.material_cost ? <CostRating label="Material cost" value={leaf.material_cost} /> : null}
              {leaf.machining_cost ? <CostRating label="Machining cost" value={leaf.machining_cost} /> : null}
            </div>
          ) : null}
        </div>
      ) : null}

      {leaf && finishes.length > 0 ? (
        <div className="mt-5 border-t border-slate-200 pt-4">
          <ChoiceStep title="Finish">
            {finishes.map((finish) => (
              <Chip
                key={finish}
                label={finish}
                selected={value.finish === finish}
                onClick={() => selectFinish(finish)}
              />
            ))}
          </ChoiceStep>
          {finishNeedsColor(value.finish) ? (
            <div className="mt-3">
              <label className="block text-sm font-medium text-slate-800" htmlFor={`${idPrefix}-finish-color`}>
                Finish color
              </label>
              <input
                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                id={`${idPrefix}-finish-color`}
                maxLength={100}
                onChange={(event) => onChange({ finish_color: event.target.value || null })}
                placeholder={
                  value.finish?.toLowerCase().includes("powder coat")
                    ? "e.g. RAL 9005 matte black"
                    : value.finish?.toLowerCase().includes("cerakote")
                      ? "e.g. H-146 Graphite Black"
                      : "e.g. Black, clear, or blue"
                }
                value={value.finish_color ?? ""}
              />
              <p className="mt-1 text-xs text-slate-500">Optional - leave blank if you have no preference.</p>
            </div>
          ) : null}
          {isOtherFinish(value.finish) ? (
            <div className="mt-3">
              <label className="block text-sm font-medium text-slate-800" htmlFor={`${idPrefix}-finish-spec`}>
                Specify finish
              </label>
              <input
                className="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                id={`${idPrefix}-finish-spec`}
                maxLength={200}
                onChange={(event) => onChange({ finish_specification: event.target.value || null })}
                placeholder="e.g. Electroless nickel, mask threaded holes"
                value={value.finish_specification ?? ""}
              />
              <p className="mt-1 text-xs text-slate-500">Required so we can quote this finish.</p>
            </div>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}

function leafGroup(entry: MaterialOption, materials: MaterialOption[]): string {
  if ((entry.group ?? "") !== "" && (entry.type ?? "") !== "") {
    return entry.group ?? "";
  }
  return ungroupedMaterials(materials).some((candidate) => candidate.id === entry.id) ? MORE_GROUP : (entry.group ?? "");
}

function hasCostRatings(entry: MaterialOption): boolean {
  return Boolean(entry.material_cost) || Boolean(entry.machining_cost);
}

/** One-to-five dollar-sign meter, e.g. "$$$" with two ghosted signs. */
function CostRating({ label, value }: { label: string; value: number }) {
  return (
    <div>
      <span className="block text-xs font-medium text-slate-600">{label}</span>
      <div
        aria-label={label}
        aria-valuemax={5}
        aria-valuemin={1}
        aria-valuenow={value}
        aria-valuetext={`${value} of 5 dollar signs`}
        role="meter"
      >
        <strong aria-hidden="true" className="whitespace-nowrap text-2xl leading-none tracking-wide text-slate-900">
          {"$".repeat(value)}
          <span className="text-slate-300">{"$".repeat(5 - value)}</span>
        </strong>
      </div>
    </div>
  );
}

function ChoiceStep({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <fieldset className="mt-4">
      <legend className="text-sm font-medium text-slate-800">{title}</legend>
      <div className="mt-2 flex flex-wrap gap-2">{children}</div>
    </fieldset>
  );
}

function Chip({
  label,
  selected,
  onClick,
}: {
  label: string;
  selected: boolean;
  onClick: () => void;
}) {
  return (
    <button
      aria-pressed={selected}
      className={`${chipBaseClasses} ${selected ? chipSelectedClasses : chipUnselectedClasses}`}
      onClick={onClick}
      type="button"
    >
      {label}
    </button>
  );
}
