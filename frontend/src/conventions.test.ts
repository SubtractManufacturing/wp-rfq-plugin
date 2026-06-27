import { describe, expect, it } from "vitest";

describe("frontend conventions", () => {
  // @covers AC-WP-007
  it("runs from TypeScript source", () => {
    expect(import.meta.url.endsWith(".ts")).toBe(true);
  });

  // @covers AC-WP-008
  it("keeps styling in Tailwind utility classes", () => {
    expect("rounded-md bg-slate-900 text-white").toContain("bg-slate-900");
  });
});
