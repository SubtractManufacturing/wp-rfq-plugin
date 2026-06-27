import AxeBuilder from "@axe-core/playwright";
import { expect, test } from "@playwright/test";

// @covers AC-WP-027
test("AC-WP-027 contact step has no serious accessibility violations", async ({ page }) => {
  await page.route("https://wp.test/wp-json/rfq/v1/health", (route) =>
    route.fulfill({ status: 200, body: JSON.stringify({ status: "ok" }) }),
  );
  await page.route("https://wp.test/wp-json/rfq/v1/sessions", (route) =>
    route.fulfill({ status: 200, body: JSON.stringify({ session_id: "session-1", token: "jwt" }) }),
  );

  await page.goto("/");
  await expect(page.getByRole("heading", { name: "Contact information" })).toBeVisible();

  const results = await new AxeBuilder({ page }).analyze();
  expect(results.violations.filter((violation) => ["critical", "serious"].includes(violation.impact ?? ""))).toEqual([]);
});
