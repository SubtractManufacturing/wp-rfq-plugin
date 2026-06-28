import { expect, test } from "@playwright/test";

// @covers AC-WP-002
test("AC-WP-002 shows Airtable fallback when health fails", async ({ page }) => {
  await page.route("https://wp.test/wp-json/rfq/v1/health", (route) =>
    route.fulfill({ status: 503, body: JSON.stringify({ status: "fail" }) }),
  );

  await page.goto("/");

  await expect(page.getByTitle("RFQ fallback form")).toHaveAttribute("src", "https://airtable.com/embed/app");
});

// @covers AC-WP-013
// @covers AC-WP-014
test("AC-WP-013 completes mocked RFQ happy path", async ({ page }) => {
  await page.route("https://wp.test/wp-json/rfq/v1/health", (route) =>
    route.fulfill({ status: 200, body: JSON.stringify({ status: "ok" }) }),
  );
  await page.route("https://wp.test/wp-json/rfq/v1/sessions", (route) =>
    route.fulfill({ status: 200, body: JSON.stringify({ session_id: "session-1", token: "jwt" }) }),
  );
  await page.route("https://wp.test/wp-json/rfq/v1/sessions/session-1/contact", (route) =>
    route.fulfill({ status: 200, body: JSON.stringify({ first_name: "Jane" }) }),
  );
  await page.route("https://wp.test/wp-json/rfq/v1/sessions/session-1/upload-urls", (route) =>
    route.fulfill({ status: 200, body: JSON.stringify({ upload_url: "https://s3.test/part", file_key: "intake/session-1/parts/file.step" }) }),
  );
  await page.route("https://s3.test/part", (route) => route.fulfill({ status: 200, body: "" }));
  await page.route("https://wp.test/wp-json/rfq/v1/sessions/session-1/draft", (route) =>
    route.fulfill({ status: 200, body: JSON.stringify({ saved: true }) }),
  );
  await page.route("https://wp.test/wp-json/rfq/v1/sessions/session-1/submit", (route) =>
    route.fulfill({ status: 200, body: JSON.stringify({ receipt_number: "RFQ-20260626-000001" }) }),
  );

  await page.goto("/");
  await page.getByLabel("First name").fill("Jane");
  await page.getByLabel("Last name").fill("Smith");
  await page.getByLabel("Email").fill("jane@example.com");
  await page.getByRole("button", { name: "Continue to uploads" }).click();
  await page.getByLabel("Part file").setInputFiles({
    name: "part.step",
    mimeType: "application/octet-stream",
    buffer: Buffer.from("cad"),
  });
  await expect(page.getByText("Uploaded: part.step")).toBeVisible();
  await page.getByRole("button", { name: "Continue to part details" }).click();
  await page.getByLabel("Material").fill("1018 Steel");
  await page.getByRole("button", { name: "Continue to RFQ details" }).click();
  await page.getByLabel("Required delivery date").fill("2026-08-01");
  await page.getByLabel("Lead time preference").selectOption("standard");
  await page.getByLabel("Shipping ZIP or postal code").fill("90210");
  await page.getByRole("button", { name: "Continue to review" }).click();
  await page.getByRole("button", { name: "Edit" }).first().click();
  await expect(page.getByRole("heading", { name: "Contact information" })).toBeVisible();
  await page.getByRole("button", { name: "Continue to uploads" }).click();
  await page.getByRole("button", { name: "Continue to part details" }).click();
  await page.getByRole("button", { name: "Continue to RFQ details" }).click();
  await page.getByRole("button", { name: "Continue to review" }).click();
  await page.getByRole("button", { name: "Submit RFQ" }).click();

  await expect(page.getByText("Ref: RFQ-20260626-000001")).toBeVisible();
});
