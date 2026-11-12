/**
 * How Product Data writes to products, set in the Workbench (migration phase 8):
 * the titles writer's settings in Title proposals, the description and picture
 * sources in Description and picture proposals, and Product Data runs.
 */
import { expect, test, type Page } from "@playwright/test";
import { MockBackend } from "./mock-backend";
import { openSection } from "./shell";
import { TitleRulesMock } from "./title-rules-mock";

async function open(page: Page, key = "titles") {
  const errors: string[] = [];
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("console", (m) => { if (m.type() === "error" && !/status of 40[034]/.test(m.text())) errors.push(m.text()); });
  await page.route("**/mmi-data-pipeline/build/workbench.js*", (r) => r.fulfill({ path: "build/workbench.js", contentType: "text/javascript" }));
  await new MockBackend().attach(page);
  const api = new TitleRulesMock();
  await api.attach(page);
  await page.goto("/");
  await openSection(page, key);
  if (key === "titles") await expect(page.locator("#mmi-wb-settings-titles")).toBeAttached();
  return { api, errors };
}

test("titles: the state in one line; starting to write asks in place, stopping doesn't", async ({ page }) => {
  const { api, errors } = await open(page);
  const box = page.locator("#mmi-wb-settings-titles");
  await expect(box.locator("summary")).toContainText("Writing titles Off · auto-apply Off at confidence 85+ · 25 per batch");
  await box.locator("summary").click();
  await page.locator("label[for=mmi-wb-writing-titles]").click();
  await expect(box.locator(".mmi-wb-writing-ask")).toContainText("Start writing titles to products?");
  expect(api.calls.filter((c) => c.path === "settings")).toHaveLength(0);
  await box.getByRole("button", { name: "Start writing" }).click();
  await expect(box.locator("summary")).toContainText("Writing titles On");
  await expect(box).toContainText("Saved.");
  expect(api.calls.find((c) => c.path === "settings")?.body).toMatchObject({ enabled: true, auto: false, batch_size: 25, min_confidence: 85 });
  await page.locator("label[for=mmi-wb-writing-titles]").click();
  await expect(box.locator("summary")).toContainText("Writing titles Off");
  expect(errors).toEqual([]);
});

test("titles: numbers are checked before saving, and the floor moves what would auto-apply", async ({ page }) => {
  const { api } = await open(page);
  const box = page.locator("#mmi-wb-settings-titles");
  await box.locator("summary").click();
  await page.locator("#mmi-wb-writing-batch").fill("80");
  await page.locator("#mmi-wb-writing-save").click();
  await expect(box).toContainText("Titles per batch is 1 to 50");
  expect(api.calls.filter((c) => c.path === "settings")).toHaveLength(0);
  await page.locator("#mmi-wb-writing-batch").fill("10");
  await page.locator("#mmi-wb-writing-floor").fill("96");
  await page.locator("#mmi-wb-writing-save").click();
  await expect(box.locator("summary")).toContainText("at confidence 96+ · 10 per batch");
  await expect(page.locator('#mmi-wb-proposals [data-view="auto"]')).toContainText("0");
  await expect(page.locator("#mmi-wb-writing-gate")).toContainText("None would auto-apply now.");
});

test("titles: Show the ones that would auto-apply opens that view", async ({ page }) => {
  await open(page);
  await page.locator("#mmi-wb-settings-titles summary").click();
  await expect(page.locator("#mmi-wb-writing-gate")).toContainText("(2 of the 3 rules in use may)");
  await page.locator("#mmi-wb-writing-show-auto").click();
  await expect(page.locator('#mmi-wb-proposals [data-view="auto"]')).toHaveAttribute("aria-pressed", "true");
  // tidy-only rows at 85+ with no flag: 2001, 2007, 2010 (2004 carries a flag)
  await expect(page.locator("#mmi-wb-pr-rows tr[data-id]")).toHaveCount(3);
});

test("descriptions and pictures: write to products asks first; a source switches on its own", async ({ page }) => {
  const { api, errors } = await open(page, "descriptions");
  const box = page.locator("#mmi-wb-settings-enrich");
  await expect(box.locator("summary")).toContainText("Writing to products Off · 1 source writes without review");
  await box.locator("summary").click();
  await expect(box.locator(".mmi-wb-writing-switch")).toHaveCount(3); // the retired source is left out
  await box.locator("label[for=mmi-wb-writing-src-content-web-assets]").click();
  await expect(box.locator("summary")).toContainText("2 sources write without review");
  expect(api.calls.find((c) => c.path === "enrich/rules")?.body).toEqual({ rule: "content:web_assets", approved: true });
  await box.locator("label[for=mmi-wb-writing-enrich]").click();
  await box.getByRole("button", { name: "Start writing" }).click();
  await expect(box.locator("summary")).toContainText("Writing to products On");
  await expect(page.locator("#mmi-wb-writing-today")).toHaveText("Today: 2 descriptions, 0 pictures written. Last batch: 2026-11-10 05:00:00");
  expect(errors).toEqual([]);
});

test("Product Data runs lists what ran", async ({ page }) => {
  await open(page, "writing");
  const t = page.locator("#mmi-wb-pd-runs-table");
  await expect(t.locator("tr", { hasText: "Last composition" })).toContainText("complete 2026-11-10 09:09:24");
  await expect(t.locator("tr", { hasText: "Titles written in total" })).toContainText("7,142");
  await expect(t.locator("tr", { hasText: "Maker page fetch" })).toContainText("read 40");
});
