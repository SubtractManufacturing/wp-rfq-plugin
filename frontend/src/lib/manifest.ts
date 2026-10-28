import type { ContactState, GlobalState, LeadTimePreference, PartRow, RfqManifest } from "../types/manifest";
import { getPhoneValidationError, normalizePhone } from "./phone";
import { manifestToleranceForPart } from "./tolerances";
import type { MaterialOption } from "../types/config";

/** Current manifest format version. Legacy manifests without this field are v1. See docs/adr/0003-manifest-versioning.md. */
export const MANIFEST_VERSION = 3 as const;

export function buildManifest(
  sessionId: string,
  contact: ContactState,
  parts: PartRow[],
  global: GlobalState,
  materials: MaterialOption[] = [],
): RfqManifest {
  const phoneError = getPhoneValidationError(contact.phone, contact.phone_country);
  const normalizedPhone =
    phoneError === null && contact.phone.trim() !== ""
      ? normalizePhone(contact.phone, contact.phone_country)
      : { phone: null, phone_country_code: null };

  return {
    manifest_version: MANIFEST_VERSION,
    session_id: sessionId,
    contact: {
      first_name: contact.first_name,
      last_name: contact.last_name,
      email: contact.email,
      company: contact.company.trim() === "" ? null : contact.company,
      phone: normalizedPhone.phone,
      phone_country_code: normalizedPhone.phone_country_code,
      job_title: null,
    },
    parts: parts
      .filter((part) => part.partFile?.status === "confirmed" && part.partFile.file_key !== "")
      .map((part) => ({
        part_id: part.part_id,
        part_file_key: part.partFile?.file_key ?? "",
        drawing_file_keys: part.drawings
          .filter((drawing) => drawing.status === "confirmed")
          .map((drawing) => drawing.file_key),
        material: part.material,
        finish: part.finish ?? null,
        finish_color: part.finish_color ?? null,
        finish_specification: part.finish_specification ?? null,
        plastic_color: part.plastic_color ?? null,
        tolerance: manifestToleranceForPart(part, materials),
        tolerance_detail: null,
        threads_features: part.threads_features,
        quantity: part.quantity,
        target_unit_price: part.target_unit_price,
        notes: part.notes,
      })),
    global: {
      required_delivery_date:
        global.lead_time_preference === "target_date" && global.required_delivery_date !== ""
          ? global.required_delivery_date
          : null,
      lead_time_preference: global.lead_time_preference as LeadTimePreference,
      shipping_destination: global.shipping_destination,
      reference_number: global.reference_number,
      nda_required: global.nda_required,
      notes: global.notes,
    },
  };
}
