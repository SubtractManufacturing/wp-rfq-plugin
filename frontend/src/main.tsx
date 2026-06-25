import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import "./index.css";

function App() {
  return (
    <div className="p-4 text-center text-sm text-slate-600">
      RFQ Intake
    </div>
  );
}

const mount = document.getElementById("rfq-form-root");
if (mount) {
  createRoot(mount).render(
    <StrictMode>
      <App />
    </StrictMode>,
  );
}
