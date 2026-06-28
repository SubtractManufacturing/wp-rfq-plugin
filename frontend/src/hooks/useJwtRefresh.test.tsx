import { render, waitFor } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { apiFetch } from "../api/client";
import { useJwtRefresh } from "./useJwtRefresh";

vi.mock("../api/client", () => ({
  apiFetch: vi.fn(),
}));

function RefreshHarness({
  token,
  sessionId,
  onWarning = vi.fn(),
}: {
  token: string | null;
  sessionId: string | null;
  onWarning?: (warning: boolean) => void;
}) {
  useJwtRefresh({
    token,
    sessionId,
    onToken: vi.fn(),
    onWarning,
  });
  return null;
}

describe("useJwtRefresh", () => {
  it("does nothing when session credentials are missing", () => {
    render(<RefreshHarness sessionId={null} token={null} />);
    expect(apiFetch).not.toHaveBeenCalled();
  });

  it("does nothing when the token has no expiration claim", () => {
    render(<RefreshHarness sessionId="session-1" token="invalid.token" />);
    expect(apiFetch).not.toHaveBeenCalled();
  });

  it("refreshes the token before it expires", async () => {
    const exp = Math.floor(Date.now() / 1000) + 600;
    const payload = btoa(JSON.stringify({ exp }));
    const token = `header.${payload}.signature`;
    vi.mocked(apiFetch).mockResolvedValue({ session_id: "session-1", token: "jwt-2" });

    render(<RefreshHarness sessionId="session-1" token={token} />);

    await waitFor(() => {
      expect(apiFetch).toHaveBeenCalledWith("/sessions/session-1/refresh", {
        method: "POST",
        token,
      });
    });
  });

  it("sets a warning when refresh fails", async () => {
    const exp = Math.floor(Date.now() / 1000) + 600;
    const payload = btoa(JSON.stringify({ exp }));
    const token = `header.${payload}.signature`;
    const onWarning = vi.fn();
    vi.mocked(apiFetch).mockRejectedValue(new Error("expired"));

    render(<RefreshHarness onWarning={onWarning} sessionId="session-1" token={token} />);

    await waitFor(() => {
      expect(onWarning).toHaveBeenCalledWith(true);
    });
  });
});
