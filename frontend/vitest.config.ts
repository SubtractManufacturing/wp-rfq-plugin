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
        "src/hooks/useAppHealthStartup.ts",
        "src/lib/manifest.ts",
        "src/lib/persistSessionDraft.ts",
        "src/lib/appHealth.ts",
      ],
      thresholds: {
        lines: 100,
        branches: 100,
        functions: 100,
        statements: 100,
      },
    },
  },
});
