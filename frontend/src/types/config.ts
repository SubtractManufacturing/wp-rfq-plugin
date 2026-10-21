export interface MaterialOption {
  id: string;
  label: string;
  aliases: string[];
  show_in_dropdown: boolean;
  /** Tree level 1, e.g. "Metal" or "Plastic". Empty for flat admin-added entries. */
  group?: string;
  /** Tree level 2 within the group, e.g. "Aluminum" or "Acetal". */
  type?: string;
  /** Short plain-language guidance shown after the material is picked. */
  guidance?: string;
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
