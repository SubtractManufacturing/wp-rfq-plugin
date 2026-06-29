import type { RfqFormConfig } from "../types/config";

export const DEV_REST_BASE = "https://wp.test/wp-json/rfq/v1";

export const devFormConfig: RfqFormConfig = {
  restBase: DEV_REST_BASE,
  nonce: "",
  airtableEmbedUrl: "https://airtable.com/embed/appDevMock",
  internationalRfqEmail: "intl-rfq@example.test",
  salesContactEmail: "sales@example.test",
  maxParts: 20,
  materials: [
    {
      id: "1018-steel",
      label: "1018 Steel",
      aliases: ["1018", "1018 steel"],
      show_in_dropdown: true,
    },
    {
      id: "6061-aluminum",
      label: "6061 Aluminum",
      aliases: ["6061", "6061 aluminum"],
      show_in_dropdown: true,
    },
    {
      id: "7075-aluminum",
      label: "7075 Aluminum",
      aliases: ["7075", "7075 aluminum"],
      show_in_dropdown: true,
    },
    {
      id: "304-stainless",
      label: "304 Stainless",
      aliases: ["304", "304 stainless", "304 ss"],
      show_in_dropdown: true,
    },
    {
      id: "grade-5-titanium",
      label: "Grade 5 Titanium",
      aliases: ["ti-6al-4v", "grade 5"],
      show_in_dropdown: false,
    },
  ],
};
