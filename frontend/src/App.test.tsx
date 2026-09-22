import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { App, FormShell } from "./App";
import { FormProvider, useForm } from "./state/FormContext";
import type { RfqFormConfig } from "./types/config";

const config: RfqFormConfig = {
  restBase: "https://example.test/wp-json/rfq/v1",
  nonce: "nonce",
  airtableEmbedUrl: "https://airtable.com/embed/app",
  internationalRfqEmail: "rfq@example.test",
  salesContactEmail: "sales@example.test",
  maxParts: 20,
  materials: [],
};

describe("App startup", () => {
  it("shows a loading message while the health check is in flight", () => {
    const fetchMock = vi.fn(() => new Promise<Response>(() => undefined));

    render(<App config={config} fetchImpl={fetchMock} />);

    expect(screen.getByText(/loading rfq form/i)).toBeInTheDocument();
  });

  // @covers AC-WP-009
  it("renders the contact step after a healthy backend without creating a session", async () => {
    const fetchMock = vi.fn().mockResolvedValueOnce(new Response(JSON.stringify({ status: "ok" })));

    render(<App config={config} fetchImpl={fetchMock} />);

    expect(await screen.findByRole("heading", { name: /contact information/i })).toBeInTheDocument();
    expect(screen.getByText(/step 1 of 5/i)).toBeInTheDocument();
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock).toHaveBeenCalledWith(
      "https://example.test/wp-json/rfq/v1/health",
      expect.objectContaining({ method: "GET" }),
    );
  });

  // @covers AC-WP-002
  it("renders the Airtable fallback when health fails", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response("nope", { status: 503 }));

    render(<App config={config} fetchImpl={fetchMock} />);

    await waitFor(() => {
      expect(screen.getByTitle("RFQ fallback form")).toHaveAttribute(
        "src",
        "https://airtable.com/embed/app",
      );
    });
  });

  it("aborts the health check when the app unmounts during startup", () => {
    const abortSpy = vi.spyOn(AbortController.prototype, "abort");
    const clearTimeoutSpy = vi.spyOn(window, "clearTimeout");
    const fetchMock = vi.fn(() => new Promise<Response>(() => undefined));
    const { unmount } = render(<App config={config} fetchImpl={fetchMock} />);
    unmount();
    expect(abortSpy).toHaveBeenCalled();
    expect(clearTimeoutSpy).toHaveBeenCalled();
    abortSpy.mockRestore();
    clearTimeoutSpy.mockRestore();
  });

  it("clears the health-check timeout after the request completes", async () => {
    const clearTimeoutSpy = vi.spyOn(window, "clearTimeout");
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ status: "ok" })));

    render(<App config={config} fetchImpl={fetchMock} />);

    await screen.findByRole("heading", { name: /contact information/i });
    expect(clearTimeoutSpy).toHaveBeenCalled();
    clearTimeoutSpy.mockRestore();
  });

  it("re-runs startup when the REST base changes", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ status: "ok" })));
    const { rerender } = render(<App config={config} fetchImpl={fetchMock} />);

    expect(await screen.findByRole("heading", { name: /contact information/i })).toBeInTheDocument();

    rerender(
      <App
        config={{ ...config, restBase: "https://other.test/wp-json/rfq/v1" }}
        fetchImpl={fetchMock}
      />,
    );

    await waitFor(() => {
      expect(fetchMock).toHaveBeenCalledWith(
        "https://other.test/wp-json/rfq/v1/health",
        expect.objectContaining({ method: "GET" }),
      );
    });
  });

  it("renders the success view when a receipt number is set", async () => {
    const user = userEvent.setup();

    function ReceiptSetter() {
      const { setReceiptNumber } = useForm();
      return (
        <button onClick={() => setReceiptNumber("RFQ-20260626-000001")} type="button">
          Complete
        </button>
      );
    }

    render(
      <FormProvider config={config} sessionId="session-1" token="jwt">
        <ReceiptSetter />
        <FormShell fetchImpl={fetch} />
      </FormProvider>,
    );

    await user.click(screen.getByRole("button", { name: /complete/i }));
    expect(await screen.findByText(/RFQ-20260626-000001/)).toBeInTheDocument();
  });

  it("renders each step shell when the active step changes", async () => {
    const user = userEvent.setup();

    function StepNavigator() {
      const { setStep } = useForm();
      return (
        <div>
          <button onClick={() => setStep("uploads")} type="button">Go uploads</button>
          <button onClick={() => setStep("partMeta")} type="button">Go part meta</button>
          <button onClick={() => setStep("global")} type="button">Go global</button>
          <button onClick={() => setStep("review")} type="button">Go review</button>
        </div>
      );
    }

    render(
      <FormProvider config={config} sessionId="session-1" token="jwt">
        <StepNavigator />
        <FormShell fetchImpl={vi.fn()} />
      </FormProvider>,
    );

    await user.click(screen.getByRole("button", { name: /go uploads/i }));
    expect(await screen.findByRole("heading", { name: /part uploads/i })).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /go part meta/i }));
    expect(await screen.findByRole("heading", { name: /part details/i })).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /go global/i }));
    expect(await screen.findByRole("heading", { name: /rfq details/i })).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /go review/i }));
    expect(await screen.findByRole("heading", { name: /review and submit/i })).toBeInTheDocument();
  });

  it("keeps the uploads step visible when crypto.randomUUID is unavailable", async () => {
    const user = userEvent.setup();
    vi.stubGlobal("crypto", {
      getRandomValues: crypto.getRandomValues.bind(crypto),
      randomUUID: undefined,
    });

    function StepNavigator() {
      const { setStep } = useForm();
      return (
        <button onClick={() => setStep("uploads")} type="button">
          Go uploads
        </button>
      );
    }

    try {
      render(
        <FormProvider config={config} sessionId="session-1" token="jwt">
          <StepNavigator />
          <FormShell fetchImpl={vi.fn()} />
        </FormProvider>,
      );

      await user.click(screen.getByRole("button", { name: /go uploads/i }));
      expect(await screen.findByRole("heading", { name: /part uploads/i })).toBeInTheDocument();
    } finally {
      vi.unstubAllGlobals();
    }
  });
});
