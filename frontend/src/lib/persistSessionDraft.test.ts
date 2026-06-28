import { describe, expect, it, vi } from "vitest";
import { emptyContact, emptyGlobal } from "../types/manifest";
import { persistSessionDraft } from "./persistSessionDraft";

describe("persistSessionDraft", () => {
  it("returns without calling the API when session credentials are missing", async () => {
    const fetchMock = vi.fn();
    const setDraftStatus = vi.fn();

    await persistSessionDraft({
      sessionId: null,
      token: "jwt",
      contact: emptyContact,
      parts: [],
      global: emptyGlobal,
      fetchImpl: fetchMock,
      setDraftStatus,
    });

    await persistSessionDraft({
      sessionId: "session-1",
      token: null,
      contact: emptyContact,
      parts: [],
      global: emptyGlobal,
      fetchImpl: fetchMock,
      setDraftStatus,
    });

    expect(fetchMock).not.toHaveBeenCalled();
    expect(setDraftStatus).not.toHaveBeenCalled();
  });

  it("marks the draft saved after a successful PUT", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({ saved: true })));
    const setDraftStatus = vi.fn();

    await persistSessionDraft({
      sessionId: "session-1",
      token: "jwt",
      contact: emptyContact,
      parts: [],
      global: emptyGlobal,
      fetchImpl: fetchMock,
      setDraftStatus,
    });

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(setDraftStatus).toHaveBeenNthCalledWith(1, "saving");
    expect(setDraftStatus).toHaveBeenLastCalledWith("saved");
  });

  it("retries once before marking the draft as failed", async () => {
    const fetchMock = vi.fn().mockRejectedValue(new Error("draft failed"));
    const setDraftStatus = vi.fn();

    await persistSessionDraft({
      sessionId: "session-1",
      token: "jwt",
      contact: emptyContact,
      parts: [],
      global: emptyGlobal,
      fetchImpl: fetchMock,
      setDraftStatus,
    });

    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(setDraftStatus).toHaveBeenLastCalledWith("error");
  });

  it("recovers when the retry succeeds", async () => {
    const fetchMock = vi
      .fn()
      .mockRejectedValueOnce(new Error("draft failed"))
      .mockResolvedValueOnce(new Response(JSON.stringify({ saved: true })));
    const setDraftStatus = vi.fn();

    await persistSessionDraft({
      sessionId: "session-1",
      token: "jwt",
      contact: emptyContact,
      parts: [],
      global: emptyGlobal,
      fetchImpl: fetchMock,
      setDraftStatus,
    });

    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(setDraftStatus).toHaveBeenLastCalledWith("saved");
  });
});
