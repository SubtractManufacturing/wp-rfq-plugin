import { devFormConfig, isDevMockMode } from "./devConfig";
import { devMockWorker } from "./browser";

export async function startDevMocks(): Promise<void> {
  if (!isDevMockMode()) {
    return;
  }

  window.rfqFormConfig = devFormConfig;

  await devMockWorker.start({
    onUnhandledRequest: "bypass",
    quiet: true,
  });
}
