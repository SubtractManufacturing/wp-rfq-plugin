import { afterEach, describe, expect, it, vi } from "vitest";
import { randomUuid } from "./uuid";

describe("randomUuid", () => {
  afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  it("builds a version-4 UUID the server accepts when random bytes are all ones", () => {
    vi.spyOn(crypto, "getRandomValues").mockImplementation((bytes) => {
      (bytes as Uint8Array).fill(0xff);
      return bytes;
    });

    expect(randomUuid()).toBe("ffffffff-ffff-4fff-bfff-ffffffffffff");
  });

  it("builds a version-4 UUID when random bytes are all zeros", () => {
    vi.spyOn(crypto, "getRandomValues").mockImplementation((bytes) => {
      (bytes as Uint8Array).fill(0);
      return bytes;
    });

    expect(randomUuid()).toBe("00000000-0000-4000-8000-000000000000");
  });

  it("does not call crypto.randomUUID", () => {
    const randomUUID = vi.fn(() => {
      throw new Error("randomUUID should not be used");
    });
    vi.stubGlobal("crypto", {
      getRandomValues: crypto.getRandomValues.bind(crypto),
      randomUUID,
    });

    expect(randomUuid()).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    );
    expect(randomUUID).not.toHaveBeenCalled();
  });
});
