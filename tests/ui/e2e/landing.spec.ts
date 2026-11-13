/** A link into the Workbench (wb_* parameters, e.g. from the old Product Data page's address): search, brand, site, section. */
import { expect, test, type Page } from "@playwright/test";
import { MockBackend } from "./mock-backend";
import { TitleRulesMock } from "./title-rules-mock";

async function open(page: Page, query: string) {
  const errors: string[] = [];
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("console", (m) => { if (m.type() === "error" && !/status of 40[034]/.test(m.text())) errors.push(m.text()); });
  await page.route("**/mmi-data-pipeline/build/workbench.js*", (r) => r.fulfill({ path: "build/workbench.js", contentType: "text/javascript" }));
  const backend = new MockBackend();
  await backend.attach(page);
  await new TitleRulesMock().attach(page);
  await page.goto(`/${query}`);
  return { backend, errors };
}

const current = (page: Page) => page.locator("#mmi-wb-menu [aria-current=page]");

test("wb_q opens Find and change on the search", async ({ page }) => {
  const { backend, errors } = await open(page, "?wb_q=Synth");
  await expect(current(page)).toHaveAttribute("data-sec", "data");
  await expect(page.locator("#mmi-wb-q")).toHaveValue("Synth");
  await expect.poll(() => backend.callsTo("mmi_workbench_search").some((c) => c.body.get("filter[q]") === "Synth")).toBe(true);
  expect(errors).toEqual([]);
});

test("wb_brand alone opens Brands & sites on the brand", async ({ page }) => {
  const { errors } = await open(page, "?wb_brand=20");
  await expect(current(page)).toHaveAttribute("data-sec", "sites");
  await expect(page.locator("#mmi-wb-bs-drawer")).toHaveAttribute("data-brand", "20");
  expect(errors).toEqual([]);
});

test("wb_site alone opens Site maps on the site", async ({ page }) => {
  const { errors } = await open(page, "?wb_site=acme.example");
  await expect(current(page)).toHaveAttribute("data-sec", "maps");
  await expect(page.locator("#mmi-wb-sm-panel")).toHaveAttribute("data-host", "acme.example");
  expect(errors).toEqual([]);
});

test("wb_sec opens its section alone; sections not opened yet aren't drawn", async ({ page }) => {
  await open(page, "?wb_sec=maps");
  await expect(current(page)).toHaveAttribute("data-sec", "maps");
  await expect(page.locator("#mmi-wb-site-maps")).toBeVisible();
  await expect(page.locator("#mmi-wb-find")).toBeHidden(); // mounted with the page (it hosts the shared components), hidden
  await expect(page.locator("#mmi-wb-proposals")).toHaveCount(0);
  await expect(page.locator("#mmi-wb-maker-pages")).toHaveCount(0);
});

test("older keys and section hashes still land: proposals, parts, #mmi-wb-rules", async ({ page }) => {
  await open(page, "?wb_sec=proposals"); // the review queues are Needs a decision's pills now
  await expect(current(page)).toHaveAttribute("data-sec", "decide");
  await expect(page.locator('#mmi-wb-decide .mmi-wb-dc-pills [data-kind="descriptions"]')).toHaveAttribute("aria-pressed", "true");
  await expect(page.locator("#mmi-wb-decide #mmi-wb-enrich")).toBeVisible();
  await open(page, "?page=x#mmi-wb-rules");
  await expect(current(page)).toHaveAttribute("data-sec", "standing");
  await expect(page.locator("#mmi-wb-rules")).toBeVisible();
});
