/** Left alone by Product Data in the Workbench (migration phase 8), REST mocked. */
import { expect, test, type Page } from "@playwright/test";
import { MockBackend } from "./mock-backend";
import { openSection } from "./shell";
import { TitleRulesMock } from "./title-rules-mock";

async function open(page: Page, key = "left") {
  const errors: string[] = [];
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("console", (m) => { if (m.type() === "error" && !/status of 40[034]/.test(m.text())) errors.push(m.text()); });
  await page.route("**/mmi-data-pipeline/build/workbench.js*", (r) => r.fulfill({ path: "build/workbench.js", contentType: "text/javascript" }));
  await new MockBackend().attach(page);
  const api = new TitleRulesMock();
  await api.attach(page);
  await page.goto("/");
  await openSection(page, key);
  await expect(page.locator("#mmi-wb-la-table tbody tr[data-key]")).toHaveCount(3);
  return { api, errors };
}

test("lists what is left alone; the store's own brand can't be taken back", async ({ page }) => {
  const { errors } = await open(page);
  await expect(page.locator("#mmi-wb-la-state")).toHaveText("51 products left alone. Products no supplier sells are skipped too.");
  await expect(page.locator('tr[data-key="b30"]')).toContainText("Always: the store’s own products");
  await expect(page.locator('tr[data-key="b31"]')).toContainText("38");
  await expect(page.locator("#mmi-wb-la-brand option")).toHaveText(["Choose a brand…", "Acme Audio", "Example Sound"]);
  expect(errors).toEqual([]);
});

test("leaving a brand alone asks first; taking one back is a second click", async ({ page }) => {
  const { api } = await open(page);
  await page.locator("#mmi-wb-la-brand").selectOption({ label: "Acme Audio" });
  await page.locator("#mmi-wb-la-brand-add").click();
  await expect(page.locator("#mmi-wb-la-ask")).toContainText("Leave every Acme Audio product alone?");
  expect(api.calls.filter((c) => c.path === "exclusions" && c.method === "POST")).toHaveLength(0);
  await page.locator("#mmi-wb-la-ask").getByRole("button", { name: "Leave it alone" }).click();
  await expect(page.locator('tr[data-key="b20"]')).toBeVisible();
  expect(api.calls.find((c) => c.path === "exclusions" && c.method === "POST")?.body).toEqual({ brands: [31, 20], products: [2005] });
  await expect(page.locator("#mmi-wb-la-notice")).toContainText("66 products left alone; 15 open proposals removed.");
  const back = page.locator('tr[data-key="b31"] button');
  await back.click();
  await expect(back).toHaveText(/Click again to take it back/);
  await back.click();
  await expect(page.locator('tr[data-key="b31"]')).toHaveCount(0);
  await expect(page.locator("#mmi-wb-la-notice")).toContainText("38 products back in Product Data");
});

test("a product by SKU; one not found is said and kept in the box", async ({ page }) => {
  await open(page);
  await page.locator("#mmi-wb-la-product").fill("SKU-2009");
  await page.locator("#mmi-wb-la-product-add").click();
  await expect(page.locator('tr[data-key="p2009"]')).toBeVisible();
  await expect(page.locator("#mmi-wb-la-product")).toHaveValue("");
  await page.locator("#mmi-wb-la-product").fill("NOPE-1");
  await page.locator("#mmi-wb-la-product").press("Enter");
  await expect(page.locator("#mmi-wb-la-notice")).toContainText("Not found: NOPE-1.");
  await expect(page.locator("#mmi-wb-la-product")).toHaveValue("NOPE-1");
});
