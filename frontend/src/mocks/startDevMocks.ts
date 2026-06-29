import { devFormConfig } from "./devConfig";
import { devMockWorker } from "./browser";

export async function startDevMocks(): Promise<void> {
  if (import.meta.env.VITE_MOCK_API === "false") {
    return;
  }

  window.rfqFormConfig = devFormConfig;

  await devMockWorker.start({
    onUnhandledRequest: "bypass",
    quiet: true,
  });
}
