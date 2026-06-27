import type { ContactState, GlobalState, LeadTimePreference, PartRow, RfqManifest } from "../types/manifest";
import { normalizePhone } from "./phone";

export function buildManifest(sessionId: string, contact: ContactState, parts: PartRow[], global: GlobalState): RfqManifest {
  const phone = normalizePhone(contact.phone);
  return {
    session_id: sessionId,
    contact: {
      first_name: contact.first_name,
      last_name: contact.last_name,
      email: contact.email,
      company: contact.company.trim() === "" ? null : contact.company,
      phone: phone.phone,
      phone_country_code: phone.phone_country_code,
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
        tolerance: part.tolerance,
        tolerance_detail: part.tolerance === "custom" ? part.tolerance_detail : null,
        threads_features: part.threads_features,
        quantity: part.quantity,
        target_unit_price: part.target_unit_price,
        notes: part.notes,
      })),
    global: {
      required_delivery_date: global.required_delivery_date,
      lead_time_preference: global.lead_time_preference as LeadTimePreference,
      shipping_destination: global.shipping_destination,
      po_number: global.po_number,
      nda_required: global.nda_required,
      notes: global.notes,
    },
  };
}
