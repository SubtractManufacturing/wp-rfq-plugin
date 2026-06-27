import type { RfqFormConfig } from "./types/config";

export function getConfig(): RfqFormConfig {
  if (window.rfqFormConfig) {
    return window.rfqFormConfig;
  }

  return {
    restBase: "/wp-json/rfq/v1",
    nonce: "",
    airtableEmbedUrl: "",
    internationalRfqEmail: "",
    salesContactEmail: "",
    maxParts: 20,
    materials: [],
  };
}
