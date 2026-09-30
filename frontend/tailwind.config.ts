import type { Config } from "tailwindcss";

export default {
  content: ["./src/**/*.{ts,tsx}", "!./src/**/*.test.{ts,tsx}"],
  // The form is embedded in arbitrary WordPress themes. Scoping every utility
  // under the mount point gives it ID-level specificity, so theme rules such as
  // `.entry-content input[type="text"]` cannot restyle the form.
  important: "#rfq-form-root",
  corePlugins: {
    // Tailwind's global preflight would restyle the whole host page (and the
    // theme would restyle ours). A scoped reset lives in src/index.css instead.
    preflight: false,
    // Emits a bare `.container` class, a name many themes also use.
    container: false,
  },
  theme: {
    extend: {},
  },
  plugins: [],
} satisfies Config;
