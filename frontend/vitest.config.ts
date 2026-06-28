import { defineConfig } from "vitest/config";
import react from "@vitejs/plugin-react";

export default defineConfig({
  plugins: [react()],
  test: {
    environment: "jsdom",
    globals: true,
    setupFiles: ["./src/test/setup.ts"],
    css: true,
    coverage: {
      provider: "v8",
      include: [
        "src/lib/phone.ts",
        "src/lib/session.ts",
        "src/components/PhoneField.tsx",
        "src/steps/StepContact.tsx",
        "src/App.tsx",
        "src/state/FormContext.tsx",
        "src/hooks/useJwtRefresh.ts",
        "src/hooks/useAutosave.ts",
        "src/lib/manifest.ts",
      ],
      thresholds: {
        lines: 99,
        statements: 98,
        branches: 96,
        functions: 97,
      },
    },
  },
});
