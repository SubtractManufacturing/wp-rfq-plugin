export interface MaterialOption {
  id: string;
  label: string;
  aliases: string[];
  show_in_dropdown: boolean;
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
