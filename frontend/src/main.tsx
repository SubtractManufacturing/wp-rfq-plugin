import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { App } from "./App";
import { getConfig } from "./config";
import "./index.css";

const mount = document.getElementById("rfq-form-root");
if (mount) {
  createRoot(mount).render(
    <StrictMode>
      <App config={getConfig()} />
    </StrictMode>,
  );
}
