import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { App } from "./App";
import { getConfig } from "./config";
import "./index.css";

async function bootstrap(): Promise<void> {
  if (import.meta.env.DEV) {
    const { startDevMocks } = await import("./mocks/startDevMocks");
    await startDevMocks();
  }

  const mount = document.getElementById("rfq-form-root");
  if (!mount) {
    return;
  }

  createRoot(mount).render(
    <StrictMode>
      <App config={getConfig()} />
    </StrictMode>,
  );
}

void bootstrap();
