import { StrictMode } from "react";
import { createRoot } from "react-dom/client";

function App() {
  return null;
}

const mount = document.getElementById("rfq-form-root");
if (mount) {
  createRoot(mount).render(
    <StrictMode>
      <App />
    </StrictMode>,
  );
}
