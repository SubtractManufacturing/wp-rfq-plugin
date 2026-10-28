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
  it("shows a loading indicator while the health check is in flight", () => {
    const fetchMock = vi.fn(() => new Promise<Response>(() => undefined));

    render(<App config={config} fetchImpl={fetchMock} />);

    expect(screen.getByRole("status")).toHaveTextContent(/loading quote form/i);
    expect(screen.queryByTitle("RFQ fallback form")).not.toBeInTheDocument();
  });

  it("replaces the loading indicator with the form once health succeeds", async () => {
    const fetchMock = vi.fn().mockResolvedValueOnce(new Response(JSON.stringify({ status: "ok" })));

    render(<App config={config} fetchImpl={fetchMock} />);

    expect(await screen.findByRole("heading", { name: /contact information/i })).toBeInTheDocument();
    expect(screen.queryByText(/loading quote form/i)).not.toBeInTheDocument();
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

  // @covers AC-WP-014
  it("shows the email address the customer entered on the success view", async () => {
    const user = userEvent.setup();

    function Completer() {
      const { setContact, setReceiptNumber, contact } = useForm();
      return (
        <button
          onClick={() => {
            setContact({ ...contact, email: "jane@example.test" });
            setReceiptNumber("RFQ-20260626-000001");
          }}
          type="button"
        >
          Complete
        </button>
      );
    }

    render(
      <FormProvider config={config} sessionId="session-1" token="jwt">
        <Completer />
        <FormShell fetchImpl={fetch} />
      </FormProvider>,
    );

    await user.click(screen.getByRole("button", { name: /complete/i }));
    expect(await screen.findByTestId("success-email")).toHaveTextContent("jane@example.test");
    expect(screen.getByRole("link", { name: "sales@example.test" })).toHaveAttribute(
      "href",
      "mailto:sales@example.test",
    );
  });

  it("starts a new request from the success view, keeping contact info but dropping the old session", async () => {
    const user = userEvent.setup();

    function Probe() {
      const { sessionId, parts, setContact, setParts, setReceiptNumber, contact, setExportControlAcknowledged } =
        useForm();
      return (
        <>
          <button
            onClick={() => {
              setContact({ ...contact, first_name: "Jane", last_name: "Doe", email: "jane@example.test" });
              setParts([
                {
                  part_id: "part-1",
                  partFile: null,
                  drawings: [],
                  material: "",
                  tolerance: "standard",
                  tolerance_detail: null,
                  threads_features: null,
                  quantity: 1,
                  target_unit_price: null,
                  notes: null,
                },
              ]);
              setExportControlAcknowledged(true);
              setReceiptNumber("RFQ-20260626-000001");
            }}
            type="button"
          >
            Complete
          </button>
          <output data-testid="probe">{`${sessionId ?? "no-session"}|${parts.length}`}</output>
        </>
      );
    }

    render(
      <FormProvider config={config} sessionId="session-1" token="jwt">
        <Probe />
        <FormShell fetchImpl={vi.fn()} />
      </FormProvider>,
    );

    await user.click(screen.getByRole("button", { name: /complete/i }));
    expect(screen.getByTestId("probe")).toHaveTextContent("session-1|1");

    await user.click(await screen.findByRole("button", { name: /start a new quote request/i }));

    expect(await screen.findByRole("heading", { name: /contact information/i })).toBeInTheDocument();
    expect(screen.getByLabelText(/first name/i)).toHaveValue("Jane");
    expect(screen.getByLabelText(/last name/i)).toHaveValue("Doe");
    expect(screen.getByLabelText(/^email/i)).toHaveValue("jane@example.test");
    expect(screen.getByTestId("probe")).toHaveTextContent("no-session|0");
    expect(screen.queryByText(/RFQ-20260626-000001/)).not.toBeInTheDocument();
  });

  // @covers AC-WP-036
  it("asks before leaving the page only while an RFQ is in progress", async () => {
    const user = userEvent.setup();
    const leaveIsBlocked = () => {
      const event = new Event("beforeunload", { cancelable: true });
      window.dispatchEvent(event);
      return event.defaultPrevented;
    };

    function Probe() {
      const { setSession, setReceiptNumber } = useForm();
      return (
        <>
          <button onClick={() => setSession("session-1", "jwt")} type="button">Start</button>
          <button onClick={() => setReceiptNumber("RFQ-20260626-000001")} type="button">Finish</button>
        </>
      );
    }

    render(
      <FormProvider config={config}>
        <Probe />
        <FormShell fetchImpl={vi.fn()} />
      </FormProvider>,
    );

    await user.type(screen.getByLabelText(/first name/i), "Jane");
    expect(leaveIsBlocked()).toBe(false);
    await user.click(screen.getByRole("button", { name: /^start$/i }));
    expect(leaveIsBlocked()).toBe(true);
    // Editing contact after the session exists marks it unsaved, but the RFQ is still in progress.
    await user.type(screen.getByLabelText(/first name/i), "t");
    expect(leaveIsBlocked()).toBe(true);
    await user.click(screen.getByRole("button", { name: /^finish$/i }));
    expect(leaveIsBlocked()).toBe(false);
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

  function formCard() {
    const card = screen.getByRole("navigation", { name: /rfq progress/i }).parentElement;
    if (!card) {
      throw new Error("form card missing");
    }
    return card;
  }

  it("leaves the page where it is when the form is already on screen", async () => {
    const user = userEvent.setup();
    const scrollBy = vi.spyOn(window, "scrollBy").mockImplementation(() => undefined);

    function StepNavigator() {
      const { setStep } = useForm();
      return (
        <button onClick={() => setStep("global")} type="button">
          Go global
        </button>
      );
    }

    render(
      <FormProvider config={config} sessionId="session-1" token="jwt">
        <StepNavigator />
        <FormShell fetchImpl={vi.fn()} />
      </FormProvider>,
    );

    vi.spyOn(formCard(), "getBoundingClientRect").mockReturnValue({ top: 240 } as DOMRect);

    expect(scrollBy).not.toHaveBeenCalled();
    await user.click(screen.getByRole("button", { name: /go global/i }));
    expect(await screen.findByRole("heading", { name: /rfq details/i })).toBeInTheDocument();
    expect(scrollBy).not.toHaveBeenCalled();
    scrollBy.mockRestore();
  });

  it("brings the form back into view when a step change leaves it above the window", async () => {
    const user = userEvent.setup();
    document.documentElement.style.scrollBehavior = "smooth";
    let behaviorDuringScroll = "";
    const scrollBy = vi.spyOn(window, "scrollBy").mockImplementation(() => {
      behaviorDuringScroll = document.documentElement.style.scrollBehavior;
    });

    function StepNavigator() {
      const { setStep } = useForm();
      return (
        <button onClick={() => setStep("global")} type="button">
          Go global
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

      vi.spyOn(formCard(), "getBoundingClientRect").mockReturnValue({ top: -400 } as DOMRect);

      await user.click(screen.getByRole("button", { name: /go global/i }));
      expect(await screen.findByRole("heading", { name: /rfq details/i })).toBeInTheDocument();
      // 400px above the window, plus a 16px margin.
      expect(scrollBy).toHaveBeenCalledWith(0, -416);
      expect(behaviorDuringScroll).toBe("auto");
      expect(document.documentElement.style.scrollBehavior).toBe("smooth");
    } finally {
      document.documentElement.style.scrollBehavior = "";
      scrollBy.mockRestore();
    }
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
