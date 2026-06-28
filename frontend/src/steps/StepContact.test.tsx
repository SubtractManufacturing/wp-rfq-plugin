import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it, vi } from "vitest";
import { FormProvider } from "../state/FormContext";
import { StepContact } from "./StepContact";
import type { RfqFormConfig } from "../types/config";

const config: RfqFormConfig = {
  restBase: "https://example.test/wp-json/rfq/v1",
  nonce: "nonce",
  airtableEmbedUrl: "https://airtable.com/embed/app",
  internationalRfqEmail: "rfq@example.test",
  salesContactEmail: "sales@example.test",
  maxParts: 20,
  materials: [],
};

function renderStep(fetchImpl: typeof fetch) {
  return render(
    <FormProvider config={config}>
      <StepContact fetchImpl={fetchImpl} />
    </FormProvider>,
  );
}

describe("StepContact", () => {
  it("shows a validation error for an invalid phone number", async () => {
    const user = userEvent.setup();
    const fetchMock = vi.fn();

    renderStep(fetchMock);

    await user.type(screen.getByLabelText(/first name/i), "Jane");
    await user.type(screen.getByLabelText(/last name/i), "Smith");
    await user.type(screen.getByLabelText(/email/i), "jane@example.com");
    await user.type(screen.getByRole("textbox", { name: "Phone" }), "5555550100");
    await user.click(screen.getByRole("button", { name: /continue to uploads/i }));

    expect(await screen.findByText(/valid phone number/i)).toBeInTheDocument();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("creates a session and saves international contact details on continue", async () => {
    const user = userEvent.setup();
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(new Response(JSON.stringify({ session_id: "session-1", token: "jwt" })))
      .mockResolvedValueOnce(new Response(JSON.stringify({ first_name: "Jane" })));

    renderStep(fetchMock);

    await user.type(screen.getByLabelText(/first name/i), "Jane");
    await user.type(screen.getByLabelText(/last name/i), "Smith");
    await user.type(screen.getByLabelText(/email/i), "jane@example.com");
    await user.type(screen.getByLabelText(/company/i), "Acme Corp");
    await user.selectOptions(screen.getByRole("combobox", { name: "Country code" }), "GB");
    await user.type(screen.getByRole("textbox", { name: "Phone" }), "07911123456");
    await user.click(screen.getByRole("button", { name: /continue to uploads/i }));

    await waitFor(() => {
      expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    const contactCall = fetchMock.mock.calls.find(([url]) => String(url).includes("/contact"));
    expect(contactCall?.[1]?.body).toContain("7911123456");
    expect(contactCall?.[1]?.body).toContain('"phone_country_code":"44"');
    expect(contactCall?.[1]?.body).toContain('"company":"Acme Corp"');
  });

  it("sends null company values for blank optional input", async () => {
    const user = userEvent.setup();
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(new Response(JSON.stringify({ session_id: "session-1", token: "jwt" })))
      .mockResolvedValueOnce(new Response(JSON.stringify({ first_name: "Jane" })));

    renderStep(fetchMock);

    await user.type(screen.getByLabelText(/first name/i), "Jane");
    await user.type(screen.getByLabelText(/last name/i), "Smith");
    await user.type(screen.getByLabelText(/email/i), "jane@example.com");
    await user.type(screen.getByLabelText(/company/i), "   ");
    await user.click(screen.getByRole("button", { name: /continue to uploads/i }));

    await waitFor(() => {
      expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    const contactCall = fetchMock.mock.calls.find(([url]) => String(url).includes("/contact"));
    expect(contactCall?.[1]?.body).toContain('"company":null');
  });

  it("shows Starting while creating a session", async () => {
    const user = userEvent.setup();
    let resolveSession: (value: Response) => void = () => undefined;
    const fetchMock = vi.fn().mockImplementation((url: string) => {
      if (String(url).endsWith("/sessions")) {
        return new Promise<Response>((resolve) => {
          resolveSession = resolve;
        });
      }
      return Promise.resolve(new Response(JSON.stringify({ first_name: "Jane" })));
    });

    renderStep(fetchMock);

    await user.type(screen.getByLabelText(/first name/i), "Jane");
    await user.type(screen.getByLabelText(/last name/i), "Smith");
    await user.type(screen.getByLabelText(/email/i), "jane@example.com");
    await user.click(screen.getByRole("button", { name: /continue to uploads/i }));

    expect(await screen.findByRole("button", { name: /starting/i })).toBeDisabled();

    resolveSession(new Response(JSON.stringify({ session_id: "session-1", token: "jwt" })));
    await waitFor(() => {
      expect(screen.getByRole("button", { name: /continue to uploads/i })).toBeInTheDocument();
    });
  });

  it("shows retry after a contact save failure", async () => {
    const user = userEvent.setup();
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(new Response(JSON.stringify({ session_id: "session-1", token: "jwt" })))
      .mockRejectedValueOnce(new Error("network down"))
      .mockResolvedValueOnce(new Response(JSON.stringify({ first_name: "Jane" })));

    renderStep(fetchMock);

    await user.type(screen.getByLabelText(/first name/i), "Jane");
    await user.type(screen.getByLabelText(/last name/i), "Smith");
    await user.type(screen.getByLabelText(/email/i), "jane@example.com");
    await user.click(screen.getByRole("button", { name: /continue to uploads/i }));

    expect(await screen.findByRole("button", { name: /retry save/i })).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /retry save/i }));

    await waitFor(() => {
      expect(fetchMock).toHaveBeenCalledTimes(3);
    });
  });

  it("surfaces generic contact save failures", async () => {
    const user = userEvent.setup();
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(new Response(JSON.stringify({ session_id: "session-1", token: "jwt" })))
      .mockRejectedValueOnce("network down");

    renderStep(fetchMock);

    await user.type(screen.getByLabelText(/first name/i), "Jane");
    await user.type(screen.getByLabelText(/last name/i), "Smith");
    await user.type(screen.getByLabelText(/email/i), "jane@example.com");
    await user.click(screen.getByRole("button", { name: /continue to uploads/i }));

    expect(await screen.findByText(/could not save contact information/i)).toBeInTheDocument();
  });

  it("updates optional company information", async () => {
    const user = userEvent.setup();
    renderStep(vi.fn());

    await user.type(screen.getByLabelText(/company/i), "Acme Corp");
    expect(screen.getByLabelText(/company/i)).toHaveValue("Acme Corp");
  });
});
