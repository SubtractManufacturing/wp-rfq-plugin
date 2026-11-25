export interface MaterialOption {
  id: string;
  label: string;
  aliases: string[];
  show_in_dropdown: boolean;
  /** Tree level 1, e.g. "Non-Ferrous" or "Plastics". Empty for flat admin-added entries. */
  group?: string;
  /** Tree level 2 within the group, e.g. "Aluminum" or "Acetal". */
  type?: string;
  /** Short plain-language guidance shown after the material is picked. */
  guidance?: string;
  /** Relative raw-material cost, 1 (lower) to 5 (higher). Null when unrated. */
  material_cost?: number | null;
  /** Relative machining cost, 1 (lower) to 5 (higher). Null when unrated. */
  machining_cost?: number | null;
  /** Finishes offered for this material. Empty means no catalog finishes. */
  finishes?: string[];
  /** Stock colors offered for this material (some plastics). Empty means no color choice. */
  colors?: string[];
}

export interface RfqFormConfig {
  restBase: string;
  nonce: string;
  airtableEmbedUrl: string;
  internationalRfqEmail: string;
  salesContactEmail: string;
  maxParts: number;
  materials: MaterialOption[];
}

declare global {
  interface Window {
    rfqFormConfig?: RfqFormConfig;
  }
}
