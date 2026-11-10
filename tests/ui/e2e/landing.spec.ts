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

test("wb_q starts the search; wb_brand opens the brand; wb_site opens the site's map", async ({ page }) => {
  const { backend, errors } = await open(page, "?wb_q=Synth&wb_brand=20&wb_site=acme.example");
  await expect(page.locator("#mmi-wb-q")).toHaveValue("Synth");
  await expect.poll(() => backend.callsTo("mmi_workbench_search").some((c) => c.body.get("filter[q]") === "Synth")).toBe(true);
  await expect(page.locator("#mmi-wb-bs-drawer")).toHaveAttribute("data-brand", "20");
  await expect(page.locator("#mmi-wb-sm-panel")).toHaveAttribute("data-host", "acme.example");
  expect(errors).toEqual([]);
});

test("wb_sec scrolls to its section once it is drawn", async ({ page }) => {
  await open(page, "?wb_sec=maps");
  await expect(page.locator("#mmi-wb-site-maps")).toBeInViewport();
});
