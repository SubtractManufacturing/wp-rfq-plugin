import { describe, expect, it, vi } from "vitest";
import { uploadFile } from "./uploadFile";

describe("uploadFile", () => {
  it("uses fetch when fetchImpl is provided", async () => {
    const onProgress = vi.fn();
    const fetchImpl = vi.fn().mockResolvedValue(new Response("", { status: 200 }));
    const file = new File(["cad"], "part.step", { type: "application/octet-stream" });

    await uploadFile("https://s3.test/part", file, "application/octet-stream", onProgress, fetchImpl);

    expect(fetchImpl).toHaveBeenCalledWith(
      "https://s3.test/part",
      expect.objectContaining({
        method: "PUT",
        body: file,
        headers: { "Content-Type": "application/octet-stream" },
      }),
    );
    expect(onProgress).toHaveBeenCalledWith(0);
    expect(onProgress).toHaveBeenCalledWith(100);
  });

  it("throws when fetch upload fails", async () => {
    const fetchImpl = vi.fn().mockResolvedValue(new Response("", { status: 500 }));
    const file = new File(["cad"], "part.step", { type: "application/octet-stream" });

    await expect(
      uploadFile("https://s3.test/part", file, "application/octet-stream", () => undefined, fetchImpl),
    ).rejects.toThrow("S3 upload failed: 500");
  });
});
