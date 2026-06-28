import { describe, expect, it } from "vitest";
import { resolveDrawingContentType } from "./drawingContentType";

describe("resolveDrawingContentType", () => {
  it("maps drawing extensions to MIME types", () => {
    expect(resolveDrawingContentType(new File(["x"], "drawing.pdf"))).toBe("application/pdf");
    expect(resolveDrawingContentType(new File(["x"], "drawing.png"))).toBe("image/png");
    expect(resolveDrawingContentType(new File(["x"], "drawing.jpg"))).toBe("image/jpeg");
  });

  it("rejects unsupported files", () => {
    expect(resolveDrawingContentType(new File(["x"], "drawing.step"))).toBeNull();
  });
});
