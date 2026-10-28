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
  let sessionCreates = 0;

  await page.route("https://wp.test/wp-json/rfq/v1/health", (route) =>
    route.fulfill({ status: 200, body: JSON.stringify({ status: "ok" }) }),
  );
  await page.route("https://wp.test/wp-json/rfq/v1/sessions", (route) => {
    sessionCreates += 1;
    route.fulfill({ status: 200, body: JSON.stringify({ session_id: "session-1", token: "jwt" }) });
  });
  await page.route("https://wp.test/wp-json/rfq/v1/sessions/session-1/contact", (route) =>
    route.fulfill({ status: 200, body: JSON.stringify({ first_name: "Jane" }) }),
  );
  await page.route("https://wp.test/wp-json/rfq/v1/sessions/session-1/upload-urls", (route) =>
    route.fulfill({ status: 200, body: JSON.stringify({ upload_url: "https://s3.test/part", file_key: "intake/session-1/parts/file.step" }) }),
  );
  await page.route("https://s3.test/part", async (route) => {
    if (route.request().method() === "OPTIONS") {
      await route.fulfill({
        status: 200,
        headers: {
          "Access-Control-Allow-Origin": "*",
          "Access-Control-Allow-Methods": "PUT, OPTIONS",
          "Access-Control-Allow-Headers": "Content-Type",
        },
      });
      return;
    }
    await route.fulfill({ status: 200, body: "" });
  });
  await page.route("https://wp.test/wp-json/rfq/v1/sessions/session-1/draft", (route) =>
    route.fulfill({ status: 200, body: JSON.stringify({ saved: true }) }),
  );
  await page.route("https://wp.test/wp-json/rfq/v1/sessions/session-1/submit", (route) =>
    route.fulfill({ status: 200, body: JSON.stringify({ receipt_number: "RFQ-20260626-000001" }) }),
  );

  await page.goto("/");
  expect(sessionCreates).toBe(0);

  await page.getByLabel("First name").fill("Jane");
  await page.getByLabel("Last name").fill("Smith");
  await page.getByLabel("Email").fill("jane@example.com");
  await page.getByRole("button", { name: "Continue to uploads" }).click();
  await expect.poll(() => sessionCreates).toBe(1);

  const exportControlDialog = page.getByRole("dialog", { name: "Do not upload controlled files" });
  await expect(exportControlDialog).toBeVisible();
  await expect(exportControlDialog.getByRole("button", { name: "Agree and continue" })).toBeDisabled();
  await exportControlDialog.getByRole("checkbox").check();
  await exportControlDialog.getByRole("button", { name: "Agree and continue" }).click();
  await expect(exportControlDialog).toBeHidden();

  await page.getByLabel("Part files").setInputFiles([
    {
      name: "part.step",
      mimeType: "application/octet-stream",
      buffer: Buffer.from("cad"),
    },
    {
      name: "part-2.step",
      mimeType: "application/octet-stream",
      buffer: Buffer.from("cad"),
    },
  ]);
  await expect(page.getByText("Uploaded")).toHaveCount(2);
  await expect(page.getByTitle("part.step", { exact: true })).toBeVisible();
  await page.getByRole("button", { name: "Continue to part details" }).click();
  await page.getByLabel(/Know the exact material/).nth(0).fill("1018");
  await page.locator("ul[role='listbox'] button").first().click();
  await page.getByLabel(/Know the exact material/).nth(1).fill("6061");
  await page.locator("ul[role='listbox'] button").first().click();
  await page.getByRole("button", { name: "Continue to RFQ details" }).click();
  await page.getByLabel("Shipping ZIP or postal code").fill("90210");
  await page.getByLabel("Lead time preference").selectOption("standard");
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

// @covers AC-WP-036
test("AC-WP-036 browser Back moves between steps and leaving the page asks first", async ({ page }) => {
  await page.route("https://wp.test/wp-json/rfq/v1/health", (route) =>
    route.fulfill({ status: 200, body: JSON.stringify({ status: "ok" }) }),
  );
  await page.route("https://wp.test/wp-json/rfq/v1/sessions", (route) =>
    route.fulfill({ status: 200, body: JSON.stringify({ session_id: "session-1", token: "jwt" }) }),
  );
  await page.route("https://wp.test/wp-json/rfq/v1/sessions/session-1/contact", (route) =>
    route.fulfill({ status: 200, body: JSON.stringify({ first_name: "Jane" }) }),
  );
  await page.route("https://wp.test/wp-json/rfq/v1/sessions/session-1/draft", (route) =>
    route.fulfill({ status: 200, body: JSON.stringify({ saved: true }) }),
  );

  await page.goto("/");
  await page.getByLabel("First name").fill("Jane");
  await page.getByLabel("Last name").fill("Smith");
  await page.getByLabel("Email").fill("jane@example.com");
  await page.getByRole("button", { name: "Continue to uploads" }).click();
  await expect(page.getByRole("heading", { name: "Part uploads" })).toBeVisible();

  await page.goBack();
  await expect(page.getByRole("heading", { name: "Contact information" })).toBeVisible();
  await expect(page.getByLabel("First name")).toHaveValue("Jane");

  await page.goForward();
  await expect(page.getByRole("heading", { name: "Part uploads" })).toBeVisible();

  const dialog = page.waitForEvent("dialog");
  await page.close({ runBeforeUnload: true });
  const prompt = await dialog;
  expect(prompt.type()).toBe("beforeunload");
  await prompt.accept();
});

test("AC-WP-013 accepts an international phone number on contact step", async ({ page }) => {
  let contactBody = "";

  await page.route("https://wp.test/wp-json/rfq/v1/health", (route) =>
    route.fulfill({ status: 200, body: JSON.stringify({ status: "ok" }) }),
  );
  await page.route("https://wp.test/wp-json/rfq/v1/sessions", (route) =>
    route.fulfill({ status: 200, body: JSON.stringify({ session_id: "session-1", token: "jwt" }) }),
  );
  await page.route("https://wp.test/wp-json/rfq/v1/sessions/session-1/contact", async (route) => {
    contactBody = route.request().postData() ?? "";
    await route.fulfill({ status: 200, body: JSON.stringify({ first_name: "Jane" }) });
  });

  await page.goto("/");
  await page.getByLabel("First name").fill("Jane");
  await page.getByLabel("Last name").fill("Smith");
  await page.getByLabel("Email").fill("jane@example.com");
  await page.getByLabel("Country code").selectOption("GB");
  await page.getByLabel("Phone").fill("07911123456");
  await page.getByRole("button", { name: "Continue to uploads" }).click();

  await expect(page.getByRole("heading", { name: "Part uploads" })).toBeVisible();
  expect(contactBody).toContain('"phone":"7911123456"');
  expect(contactBody).toContain('"phone_country_code":"44"');
});
