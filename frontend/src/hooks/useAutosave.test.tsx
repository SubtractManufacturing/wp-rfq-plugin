import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { App } from "../App";
import { FormProvider, useForm } from "../state/FormContext";
import { useAutosave } from "./useAutosave";
import type { RfqFormConfig } from "../types/config";

const config: RfqFormConfig = {
  restBase: "https://example.test/wp-json/rfq/v1",
  nonce: "nonce",
  airtableEmbedUrl: "https://airtable.com/embed/app",
  internationalRfqEmail: "large-rfq@example.test",
  salesContactEmail: "sales@example.test",
  maxParts: 20,
  materials: [],
};

function AutosaveHarness({ fetchImpl }: { fetchImpl: typeof fetch }) {
  const { setStep } = useForm();
  useAutosave({ fetchImpl });
  return (
    <button onClick={() => setStep("uploads")} type="button">
      Advance step
    </button>
  );
}

describe("useAutosave", () => {
  it("does not save a draft before a session exists", async () => {
    const fetchMock = vi.fn().mockResolvedValueOnce(new Response(JSON.stringify({ status: "ok" })));

    render(<App config={config} fetchImpl={fetchMock} />);
    await screen.findByRole("heading", { name: /contact information/i });

    await waitFor(() => {
      expect(fetchMock.mock.calls.some(([url]) => String(url).includes("/draft"))).toBe(false);
    });
  });

  it("saves draft immediately when the step changes after contact is saved", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(new Response(JSON.stringify({ status: "ok" })))
      .mockResolvedValueOnce(new Response(JSON.stringify({ session_id: "session-1", token: "jwt" })))
      .mockResolvedValueOnce(new Response(JSON.stringify({ first_name: "Jane" })))
      .mockResolvedValue(new Response(JSON.stringify({ saved: true })));

    const user = userEvent.setup();
    render(<App config={config} fetchImpl={fetchMock} />);

    await screen.findByRole("heading", { name: /contact information/i });
    await user.type(screen.getByLabelText(/first name/i), "Jane");
    await user.type(screen.getByLabelText(/last name/i), "Smith");
    await user.type(screen.getByLabelText(/email/i), "jane@example.com");
    await user.click(screen.getByRole("button", { name: /continue to uploads/i }));

    await waitFor(() => {
      const draftCalls = fetchMock.mock.calls.filter(([url]) => String(url).includes("/draft"));
      expect(draftCalls.length).toBeGreaterThan(0);
    });
  });

  it("marks draft save errors after both attempts fail", async () => {
    const fetchMock = vi.fn().mockRejectedValue(new Error("draft failed"));
    const user = userEvent.setup();

    render(
      <FormProvider config={config} sessionId="session-1" token="jwt">
        <AutosaveHarness fetchImpl={fetchMock} />
      </FormProvider>,
    );

    await user.click(screen.getByRole("button", { name: /advance step/i }));

    await waitFor(() => {
      expect(fetchMock).toHaveBeenCalledTimes(2);
    });
  });

  it("recovers when the second draft save attempt succeeds", async () => {
    const fetchMock = vi
      .fn()
      .mockRejectedValueOnce(new Error("draft failed"))
      .mockResolvedValueOnce(new Response(JSON.stringify({ saved: true })));
    const user = userEvent.setup();

    render(
      <FormProvider config={config} sessionId="session-1" token="jwt">
        <AutosaveHarness fetchImpl={fetchMock} />
      </FormProvider>,
    );

    await user.click(screen.getByRole("button", { name: /advance step/i }));

    await waitFor(() => {
      expect(fetchMock).toHaveBeenCalledTimes(2);
    });
  });

  it("skips autosave while a token warning is active", async () => {
    function WarningHarness({ fetchImpl }: { fetchImpl: typeof fetch }) {
      const { setTokenWarning, setStep } = useForm();
      useAutosave({ fetchImpl });
      return (
        <>
          <button onClick={() => setTokenWarning(true)} type="button">
            Warn
          </button>
          <button onClick={() => setStep("uploads")} type="button">
            Advance step
          </button>
        </>
      );
    }

    const fetchMock = vi.fn();
    const user = userEvent.setup();

    render(
      <FormProvider config={config} sessionId="session-1" token="jwt">
        <WarningHarness fetchImpl={fetchMock} />
      </FormProvider>,
    );

    await user.click(screen.getByRole("button", { name: /warn/i }));
    await user.click(screen.getByRole("button", { name: /advance step/i }));

    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("schedules a delayed draft save while the customer stays on a step", async () => {
    vi.useFakeTimers();
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ saved: true })));

    render(
      <FormProvider config={config} sessionId="session-1" token="jwt">
        <AutosaveHarness fetchImpl={fetchMock} />
      </FormProvider>,
    );

    await vi.advanceTimersByTimeAsync(30_000);

    expect(fetchMock).toHaveBeenCalled();
    vi.useRealTimers();
  });
});
