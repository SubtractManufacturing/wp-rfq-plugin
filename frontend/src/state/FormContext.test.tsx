import { render, renderHook, screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { FormProvider, useForm } from "./FormContext";
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

function SessionReader() {
  const { sessionId, token, setSession } = useForm();

  return (
    <div>
      <span data-testid="session-id">{sessionId ?? "none"}</span>
      <span data-testid="token">{token ?? "none"}</span>
      <button onClick={() => setSession("session-1", "jwt")} type="button">
        Set session
      </button>
    </div>
  );
}

describe("FormProvider", () => {
  it("starts without a session and updates session state", async () => {
    render(
      <FormProvider config={config}>
        <SessionReader />
      </FormProvider>,
    );

    expect(screen.getByTestId("session-id")).toHaveTextContent("none");
    expect(screen.getByTestId("token")).toHaveTextContent("none");

    await screen.getByRole("button", { name: /set session/i }).click();

    expect(screen.getByTestId("session-id")).toHaveTextContent("session-1");
    expect(screen.getByTestId("token")).toHaveTextContent("jwt");
  });

  it("throws when useForm is called outside the provider", () => {
    expect(() => renderHook(() => useForm())).toThrow(/FormProvider/);
  });
});
