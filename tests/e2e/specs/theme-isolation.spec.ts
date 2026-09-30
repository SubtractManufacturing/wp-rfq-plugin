import { expect, test, type Page } from "@playwright/test";

/**
 * The form is embedded in arbitrary WordPress themes, which routinely restyle
 * bare inputs, buttons, headings, paragraphs and lists. These tests load the form
 * with deliberately hostile theme CSS and require every element to render exactly
 * as it does without it.
 */

// @covers AC-WP-008
const HOSTILE_THEME_CSS = `
  body { font-family: Georgia, serif; font-size: 22px; line-height: 2; color: #c00; letter-spacing: 1px;
         text-transform: uppercase; text-align: center; word-spacing: 6px; }
  .entry-content h1, .entry-content h2, .entry-content h3 { font-size: 3rem; margin: 3rem 0; font-family: serif;
         text-transform: uppercase; color: purple; font-weight: 900; line-height: 1; }
  .entry-content p, .entry-content ul, .entry-content li { margin: 2rem 0; padding-left: 2rem; list-style: disc;
         font-size: 20px; color: teal; }
  .entry-content span { font-size: 26px; font-weight: 900; letter-spacing: 3px; }
  .entry-content label { display: inline; font-weight: 900; font-size: 20px; color: red; margin: 12px; }
  .entry-content input, .entry-content select, .entry-content textarea,
  .entry-content input[type="text"], .entry-content input[type="email"], .entry-content input[type="tel"],
  .entry-content input[type="number"], .entry-content input[type="date"] {
         font-size: 20px; padding: 18px; border: 4px solid #000; border-radius: 0; background: #ffc;
         box-shadow: inset 0 0 0 3px blue; min-height: 60px; height: 60px; width: 50%; margin: 10px; color: #000;
         letter-spacing: 2px; text-transform: uppercase; font-family: monospace; line-height: 3; }
  .entry-content textarea { height: 200px; }
  .entry-content button, .entry-content .wp-element-button { font-size: 20px; padding: 18px 40px; background: #f0f;
         color: #fff; border-radius: 99px; border: 3px dashed #000; text-transform: uppercase;
         box-shadow: 4px 4px 0 #000; min-height: 60px; font-family: cursive; letter-spacing: 2px; }
  .entry-content a { color: red; text-decoration: underline; }
  .entry-content svg, .entry-content img { width: 60px; height: 60px; margin: 10px; }
  .entry-content input:focus, .entry-content select:focus, .entry-content button:focus { outline: 5px solid orange; }
  .entry-content * { transition: none; animation: none; }
`;

async function mockApi(page: Page) {
  // Snapshots must not catch a hover/transition mid-flight.
  await page.addInitScript(() => {
    document.addEventListener("DOMContentLoaded", () => {
      const style = document.createElement("style");
      style.textContent = "*, *::before, *::after { transition: none !important; animation: none !important; }";
      document.head.appendChild(style);
    });
  });
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
  await page.route("https://wp.test/wp-json/rfq/v1/sessions/session-1/upload-urls", (route) =>
    route.fulfill({
      status: 200,
      body: JSON.stringify({ upload_url: "https://s3.test/part", file_key: "intake/session-1/parts/file.step" }),
    }),
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
}

const STYLE_PROPS = [
  "display", "position", "width", "height", "min-height", "margin-top", "margin-right", "margin-bottom",
  "margin-left", "padding-top", "padding-right", "padding-bottom", "padding-left", "border-top-width",
  "border-top-style", "border-top-color", "border-bottom-width", "border-left-width", "border-right-width",
  "border-top-left-radius", "background-color", "box-shadow", "color", "font-family", "font-size",
  "font-weight", "line-height", "letter-spacing", "word-spacing", "text-transform", "text-align",
  "text-decoration-line", "list-style-type", "opacity", "cursor",
];

/** Computed style of every element in the form, keyed by DOM position. */
async function snapshotForm(page: Page): Promise<string[]> {
  return page.evaluate((props) => {
    const root = document.getElementById("rfq-form-root");
    if (!root) {
      throw new Error("form root missing");
    }
    const skip = new Set(["SCRIPT", "STYLE", "PATH"]);
    return [root, ...Array.from(root.querySelectorAll("*"))]
      .filter((el) => !skip.has(el.tagName))
      .map((el, index) => {
        const cs = getComputedStyle(el);
        const label = el.getAttribute("aria-label") ?? el.getAttribute("role") ?? "";
        const declared = props.map((prop) => `${prop}:${cs.getPropertyValue(prop)}`).join(";");
        return `#${index} <${el.tagName.toLowerCase()} ${label}> ${declared}`;
      });
  }, STYLE_PROPS);
}

async function walkSteps(page: Page): Promise<Record<string, string[]>> {
  const shots: Record<string, string[]> = {};

  await page.goto("/");
  await page.getByLabel("First name").fill("Jane");
  await page.getByLabel("Last name").fill("Smith");
  await page.getByLabel("Email").fill("jane@example.com");
  await page.getByLabel("Phone").fill("2125550100");
  shots.contact = await snapshotForm(page);

  await page.getByRole("button", { name: "Continue to uploads" }).click();
  await page.getByRole("heading", { name: "Part uploads" }).waitFor();
  shots.uploadsEmpty = await snapshotForm(page);

  await page.getByLabel("Part files").setInputFiles([
    { name: "part.step", mimeType: "application/octet-stream", buffer: Buffer.from("cad") },
    { name: "part-2.step", mimeType: "application/octet-stream", buffer: Buffer.from("cad") },
  ]);
  await expect(page.getByText("Uploaded")).toHaveCount(2);
  await page.getByLabel("Supporting files for part.step").setInputFiles({
    name: "drawing.pdf",
    mimeType: "application/pdf",
    buffer: Buffer.from("pdf"),
  });
  await expect(page.getByText("Uploaded")).toHaveCount(3);
  shots.uploadsList = await snapshotForm(page);

  await page.getByRole("button", { name: "Continue to part details" }).click();
  await page.getByRole("heading", { name: "Part details" }).waitFor();
  shots.partDetails = await snapshotForm(page);

  await page.getByRole("button", { name: "Show common options" }).first().click();
  await expect(page.getByRole("listbox")).toBeVisible();
  shots.materialOpen = await snapshotForm(page);
  await page.getByRole("option").first().click();
  await page.getByLabel("Material").nth(1).fill("6061 Aluminum");
  await page.getByRole("button", { name: "Continue to RFQ details" }).click();
  await page.getByRole("heading", { name: "RFQ details" }).waitFor();
  shots.rfqDetails = await snapshotForm(page);

  await page.getByLabel("Shipping ZIP or postal code").fill("90210");
  await page.getByLabel("Lead time preference").selectOption("standard");
  await page.getByRole("button", { name: "Continue to review" }).click();
  await page.getByRole("heading", { name: "Review" }).first().waitFor();
  shots.review = await snapshotForm(page);

  return shots;
}

test("form renders identically under an aggressive theme stylesheet", async ({ browser }) => {
  const clean = await browser.newPage();
  await mockApi(clean);
  const baseline = await walkSteps(clean);
  await clean.close();

  const hostile = await browser.newPage();
  await mockApi(hostile);
  await hostile.addInitScript((css) => {
    document.addEventListener("DOMContentLoaded", () => {
      document.body.classList.add("entry-content", "wp-block-post-content");
      const style = document.createElement("style");
      style.textContent = css;
      document.head.appendChild(style);
    });
  }, HOSTILE_THEME_CSS);
  const themed = await walkSteps(hostile);
  await hostile.close();

  const differences: string[] = [];
  for (const step of Object.keys(baseline)) {
    const before = baseline[step] ?? [];
    const after = themed[step] ?? [];
    if (before.length !== after.length) {
      differences.push(`${step}: element count ${before.length} -> ${after.length}`);
      continue;
    }
    before.forEach((line, index) => {
      const other = after[index] ?? "";
      if (line === other) {
        return;
      }
      const [head = "", declared = ""] = line.split("> ");
      const themedDeclared = other.split("> ")[1] ?? "";
      const a = declared.split(";");
      const b = themedDeclared.split(";");
      a.forEach((entry, entryIndex) => {
        if (entry !== b[entryIndex]) {
          differences.push(`${step} ${head}> ${entry}  ->  ${b[entryIndex]}`);
        }
      });
    });
  }

  expect(differences.slice(0, 25), `${differences.length} style differences under theme CSS`).toEqual([]);
});

test("form stylesheet does not restyle the host page", async ({ page }) => {
  await mockApi(page);
  await page.goto("/");
  await page.getByLabel("First name").waitFor();

  const leaks = await page.evaluate(() => {
    const found: string[] = [];
    const inspect = (rules: CSSRuleList) => {
      for (const rule of Array.from(rules)) {
        if (rule instanceof CSSStyleRule) {
          const scoped = rule.selectorText.split(",").every((part) => part.includes("#rfq-form-root"));
          if (scoped) {
            continue;
          }
          // Tailwind's --tw-* custom-property defaults are inert; anything else on the page is a leak.
          const declarations = Array.from(rule.style);
          if (declarations.length > 0 && !declarations.every((name) => name.startsWith("--tw-"))) {
            found.push(rule.selectorText);
          }
        } else if ("cssRules" in rule) {
          inspect((rule as CSSGroupingRule).cssRules);
        }
      }
    };
    for (const sheet of Array.from(document.styleSheets)) {
      const owner = sheet.ownerNode as HTMLElement | null;
      const isFormSheet = owner?.getAttribute("data-vite-dev-id")?.endsWith("index.css") ?? false;
      if (isFormSheet) {
        inspect(sheet.cssRules);
      }
    }
    return found;
  });

  expect(leaks).toEqual([]);
});
