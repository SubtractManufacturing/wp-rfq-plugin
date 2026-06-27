export type StepId = "contact" | "uploads" | "partMeta" | "global" | "review";
export type Tolerance = "standard" | "precision" | "custom";
export type LeadTimePreference = "no_rush" | "standard" | "target_date" | "expedited" | "economy";
export type UploadStatus = "pending" | "uploading" | "confirmed" | "error";

export interface ContactState {
  first_name: string;
  last_name: string;
  email: string;
  company: string;
  phone: string;
  phone_country_code: "1" | null;
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
  tolerance: Tolerance;
  tolerance_detail: string | null;
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
  session_id: string;
  contact: {
    first_name: string;
    last_name: string;
    email: string;
    company: string | null;
    phone: string | null;
    phone_country_code: "1" | null;
    job_title: null;
  };
  parts: Array<{
    part_id: string;
    part_file_key: string;
    drawing_file_keys: string[];
    material: string;
    tolerance: Tolerance;
    tolerance_detail: string | null;
    threads_features?: string | null;
    quantity: number;
    target_unit_price: number | null;
    notes?: string | null;
  }>;
  global: {
    required_delivery_date: string;
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
