import type { CountryCode } from "../lib/phone";

export type StepId = "contact" | "uploads" | "partMeta" | "global" | "review";
/** "precision" is the legacy name for the tight tier; new selections use "tight". */
export type Tolerance = "economy" | "standard" | "tight" | "precision" | "custom";
export type LeadTimePreference = "standard" | "target_date" | "expedited" | "economy";
export type UploadStatus = "pending" | "uploading" | "confirmed" | "error";

export interface ContactState {
  first_name: string;
  last_name: string;
  email: string;
  company: string;
  phone: string;
  phone_country: CountryCode;
  phone_country_code: string | null;
  job_title: null;
}

export interface UploadedFile {
  file_key: string;
  filename: string;
  content_type: string;
  status: UploadStatus;
  progress: number;
  error?: string;
  upload_url?: string;
  sourceFile?: File;
}

export interface PartRow {
  part_id: string;
  partFile: UploadedFile | null;
  drawings: UploadedFile[];
  material: string;
  /** Finish picked from the material's finish list, e.g. "Anodize Type II". */
  finish?: string | null;
  /** Color/detail for finishes that need one, e.g. powder-coat color. */
  finish_color?: string | null;
  /** Free-text detail when finish is "Other: specify". */
  finish_specification?: string | null;
  /** Stock color for plastics that offer one; null means no preference. */
  plastic_color?: string | null;
  tolerance: Tolerance;
  tolerance_detail: string | null;
  /** Raw amount typed for a custom material's tolerance, in tolerance_unit. Converted to inches in the manifest. */
  tolerance_value?: string | null;
  /** Unit the customer typed tolerance_value in; the conversion to inches is never shown to them. */
  tolerance_unit?: "in" | "mm" | null;
  threads_features: string | null;
  quantity: number;
  target_unit_price: number | null;
  notes: string | null;
}

export interface GlobalState {
  required_delivery_date: string;
  lead_time_preference: LeadTimePreference | "";
  shipping_destination: { postal_code: string };
  po_number: string | null;
  nda_required: boolean;
  notes: string | null;
}

export interface RfqManifest {
  /** Manifest format version. Absence means v1 (legacy). Bump on any manifest shape/meaning change - see docs/adr/0003-manifest-versioning.md. */
  manifest_version: 2;
  session_id: string;
  contact: {
    first_name: string;
    last_name: string;
    email: string;
    company: string | null;
    phone: string | null;
    phone_country_code: string | null;
    job_title: null;
  };
  parts: Array<{
    part_id: string;
    part_file_key: string;
    drawing_file_keys: string[];
    material: string;
    finish?: string | null;
    finish_color?: string | null;
    finish_specification?: string | null;
    plastic_color?: string | null;
    /** Actual global tolerance, verbatim for ERP import, e.g. `+/- 0.005"`. Never a tier label in v2. */
    tolerance: string;
    /** Kept for v1 shape compatibility; always null in v2 (custom tolerance text lives in `tolerance`). */
    tolerance_detail: string | null;
    threads_features?: string | null;
    quantity: number;
    target_unit_price: number | null;
    notes?: string | null;
  }>;
  global: {
    required_delivery_date: string | null;
    lead_time_preference: LeadTimePreference;
    shipping_destination: { postal_code: string };
    po_number: string | null;
    nda_required: boolean;
    notes: string | null;
  };
}

export const emptyContact: ContactState = {
  first_name: "",
  last_name: "",
  email: "",
  company: "",
  phone: "",
  phone_country: "US",
  phone_country_code: null,
  job_title: null,
};

export const emptyGlobal: GlobalState = {
  required_delivery_date: "",
  lead_time_preference: "",
  shipping_destination: { postal_code: "" },
  po_number: null,
  nda_required: false,
  notes: null,
};
