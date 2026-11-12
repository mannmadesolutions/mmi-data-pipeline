/** Product pictures in the Workbench, and queueing from Maker pages (migration phase 8), REST mocked. */
import { expect, test, type Page } from "@playwright/test";
import { MockBackend } from "./mock-backend";
import { openSection } from "./shell";
import { TitleRulesMock } from "./title-rules-mock";

async function open(page: Page, key = "pictures") {
  const errors: string[] = [];
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("console", (m) => { if (m.type() === "error" && !/status of 40[034]/.test(m.text())) errors.push(m.text()); });
  await page.route("**/mmi-data-pipeline/build/workbench.js*", (r) => r.fulfill({ path: "build/workbench.js", contentType: "text/javascript" }));
  await new MockBackend().attach(page);
  const api = new TitleRulesMock();
  await api.attach(page);
  await page.goto("/");
  await openSection(page, key);
  if (key === "pictures") await expect(page.locator("#mmi-wb-px-rows tr[data-id]")).toHaveCount(50);
  return { api, errors };
}

test("the runner's state, outcomes with totals, own pictures called out", async ({ page }) => {
  const { errors } = await open(page);
  await expect(page.locator("#mmi-wb-px-status")).toContainText("Runner idle. 12 of 300 products changed in the last 24 hours.");
  await expect(page.locator("#mmi-wb-px-groups tbody tr")).toHaveCount(4);
  await expect(page.locator("#mmi-wb-px-groups tbody tr").first()).toContainText("Vendor pictures");
  await expect(page.locator(".mmi-wb-px-total")).toContainText("60");
  await expect(page.locator("#mmi-wb-px-own")).toContainText("9 products did not get vendor pictures");
  await expect(page.locator("#mmi-wb-px-stop")).toBeDisabled();
  await expect(page.locator("#mmi-wb-px-count")).toHaveText("60 products");
  expect(errors).toEqual([]);
});

test("Show them filters the ledger; pages; Put back a product", async ({ page }) => {
  const { api } = await open(page);
  await page.locator("#mmi-wb-px-own").getByRole("button", { name: "Show them" }).click();
  await expect(page.locator("#mmi-wb-px-show")).toHaveValue("in_place");
  await expect(page.locator("#mmi-wb-px-rows tr[data-id]")).toHaveCount(9); // 5051–5059; 5060 is an error
  await page.locator("#mmi-wb-px-show").selectOption("");
  await page.locator("#mmi-wb-px-pager").getByRole("button", { name: "Next ›" }).click();
  await expect(page.locator("#mmi-wb-px-pager")).toContainText("Page 2 of 2");
  await expect(page.locator("#mmi-wb-px-rows tr[data-id]")).toHaveCount(10);
  const r = page.locator('#mmi-wb-px-rows tr[data-id="5051"]');
  await r.getByRole("button", { name: "Put back" }).click();
  await expect(r).toContainText("Old pictures restored (3 → 1).");
  expect(api.calls.some((c) => c.path === "pictures/5051/revert" && c.method === "POST")).toBe(true);
});

test("Pictures on ticked Maker pages rows queues them; the section follows and can stop", async ({ page }) => {
  const { api } = await open(page, "pages");
  api.pxWaiting = 2;
  await page.locator('#mmi-wb-mp-rows tr[data-id="4003"]').getByRole("checkbox").check();
  await page.locator("#mmi-wb-mp-pictures-ticked").click();
  await expect(page.locator("#mmi-wb-mp-notice")).toHaveText("1 product queued for new pictures. Product pictures shows how it goes.");
  expect(api.callsTo("pages/queue")[0]!.body).toEqual({ ids: [4003], mode: "pictures" });
  await openSection(page, "pictures");
  await expect(page.locator("#mmi-wb-px-status")).toContainText("Runner working.");
  await expect(page.locator("#mmi-wb-px-status")).toContainText("2 waiting.");
  await page.locator("#mmi-wb-px-stop").click();
  await expect(page.locator("#mmi-wb-px-notice")).toHaveText("2 waiting products removed.");
  await expect(page.locator("#mmi-wb-px-stop")).toBeDisabled();
});

test("Scrape again on ticked Maker pages rows", async ({ page }) => {
  const { api } = await open(page, "pages");
  await page.locator('#mmi-wb-mp-rows tr[data-id="4003"]').getByRole("checkbox").check();
  await page.locator("#mmi-wb-mp-scrape-ticked").click();
  await expect(page.locator("#mmi-wb-mp-notice")).toHaveText("1 product queued to scrape again.");
  expect(api.callsTo("pages/queue")[0]!.body).toEqual({ ids: [4003], mode: "scrape" });
});
