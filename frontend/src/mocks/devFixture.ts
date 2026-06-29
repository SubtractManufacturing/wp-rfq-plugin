import type { ContactState, GlobalState, PartRow } from "../types/manifest";
import { DEV_SESSION_ID, DEV_TOKEN } from "./devConfig";

export interface DevFormBootstrap {
  sessionId: string;
  token: string;
  contact: ContactState;
  contactSaved: boolean;
  parts: PartRow[];
  global: GlobalState;
}

const DEV_PART_ID = "dev-part-1";

export function getDevFormBootstrap(): DevFormBootstrap {
  return {
    sessionId: DEV_SESSION_ID,
    token: DEV_TOKEN,
    contactSaved: true,
    contact: {
      first_name: "Alex",
      last_name: "Morgan",
      email: "alex.morgan@example.test",
      company: "Subtract Prototype Co.",
      phone: "(555) 555-0100",
      phone_country: "US",
      phone_country_code: "1",
      job_title: null,
    },
    parts: [
      {
        part_id: DEV_PART_ID,
        partFile: {
          file_key: `intake/${DEV_SESSION_ID}/parts/fixture-bracket.step`,
          filename: "fixture-bracket.step",
          content_type: "application/octet-stream",
          status: "confirmed",
          progress: 100,
        },
        drawings: [
          {
            file_key: `intake/${DEV_SESSION_ID}/drawings/fixture-bracket.pdf`,
            filename: "fixture-bracket.pdf",
            content_type: "application/pdf",
            status: "confirmed",
            progress: 100,
          },
        ],
        material: "6061 Aluminum",
        tolerance: "standard",
        tolerance_detail: null,
        threads_features: "4x M6 tapped holes",
        quantity: 2,
        target_unit_price: 42.5,
        notes: "Dev fixture part — edit freely while testing UI.",
      },
    ],
    global: {
      required_delivery_date: "2026-12-15",
      lead_time_preference: "standard",
      shipping_destination: { postal_code: "90210" },
      po_number: "PO-DEV-001",
      nda_required: false,
      notes: "Preloaded for local UI testing.",
    },
  };
}
