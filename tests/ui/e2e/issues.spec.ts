/**
 * Issues fixed in their row (IssueFixes.tsx), in a real browser, WebKit first, with the REST routes mocked:
 * a missing brand created with its logo and website and mapped for the supplier's other products, a bundle
 * filed with one click, a title flag that opens the title editor, and a results-table health chip.
 */
import { expect, test, type Page } from "@playwright/test";
import { MockBackend } from "./mock-backend";
import { openSection } from "./shell";
import { TitleRulesMock } from "./title-rules-mock";

async function open(page: Page, key = "titles") {
  const errors: string[] = [];
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("console", (m) => { if (m.type() === "error" && !/status of 40[0349]/.test(m.text())) errors.push(m.text()); });
  page.on("dialog", (d) => d.accept());
  await page.route("**/mmi-data-pipeline/build/workbench.js*", (r) => r.fulfill({ path: "build/workbench.js", contentType: "text/javascript" }));
  const backend = new MockBackend();
  await backend.attach(page);
  const api = new TitleRulesMock();
  await api.attach(page);
  await page.goto("/");
  await openSection(page, key);
  if (key === "titles") await expect(page.locator("#mmi-wb-pr-rows tr[data-id]")).toHaveCount(22);
  // WordPress's media library (the harness loads the real one): picking resolves at once with one image.
  await page.evaluate(() => {
    // Core's media scripts keep using wp.media's parts (wp.media.view…) after load, so the stub keeps them.
    const w = window as unknown as { wp: { media: object } };
    w.wp.media = Object.assign(() => {
      let done: () => void = () => {};
      return {
        on: (_ev: string, fn: () => void) => { done = fn; },
        open: () => setTimeout(done, 0),
        state: () => ({ get: () => ({ toJSON: () => [{ id: 99, url: "/wp-includes/images/w-logo-blue.png" }] }) }),
      };
    }, w.wp.media);
  });
  return { api, backend, errors };
}
const row = (page: Page, id: number) => page.locator(`#mmi-wb-pr-rows tr[data-id="${id}"]`);
const panel = (page: Page) => page.locator("#mmi-wb-pr-rows .mmi-wb-issue-row .mmi-wb-issue-panel");

test("one chip per issue: a stored No brand flag never doubles the live one", async ({ page }) => {
  const { errors } = await open(page);
  await expect(row(page, 2012).locator(".mmi-wb-issue")).toHaveText(["No brand"]);
  await expect(row(page, 2012).locator(".mmi-wb-pr-flags")).toHaveCount(0);
  expect(errors).toEqual([]);
});

test("a missing brand is created in the row: name, logo, website, and mapped for the supplier's other products", async ({ page }) => {
  const { api, errors } = await open(page);
  await row(page, 2012).locator(".mmi-wb-issue", { hasText: "No brand" }).click();
  await expect(panel(page)).toContainText("Xchange says “Freqport” (not mapped to any brand).");
  // Nothing close exists, so the panel opens on creating it, named as the supplier sends it.
  await expect(panel(page).getByRole("tab", { name: "Create a new brand" })).toHaveAttribute("aria-selected", "true");
  await expect(page.locator(".mmi-wb-new-brand-name")).toHaveValue("Freqport");
  await panel(page).getByRole("button", { name: "Choose or upload…" }).click();
  await expect(panel(page).locator(".mmi-wb-brand-logo-row img")).toHaveAttribute("src", "/wp-includes/images/w-logo-blue.png");
  await page.locator(".mmi-wb-new-brand-site").fill("https://freqport.example");
  const remember = panel(page).locator(".mmi-wb-issue-remember input");
  await expect(remember).toBeChecked();
  await expect(panel(page).locator(".mmi-wb-issue-remember")).toContainText("2 other products from Xchange sending it get it now");
  await panel(page).getByRole("button", { name: "Create Freqport and set it" }).click();
  await expect(page.locator(".mmi-wb-pr-row-notice")).toHaveText("Created and set brand Freqport. Mapped Xchange “Freqport” to it; 2 other products got it too.");
  expect(api.callsTo("products/2012/fix")[0]!.body).toEqual({
    kind: "brand", create: { name: "Freqport", slug: "", description: "", logo_id: 99, website: "https://freqport.example" }, remember: ["xchange|brand"],
  });
  await expect(row(page, 2012).locator(".mmi-wb-pr-termcell").first()).toContainText("Freqport");
  await expect(row(page, 2012).locator(".mmi-wb-issue")).toHaveCount(0);
  expect(errors).toEqual([]);
});

test("a brand created and mapped from one proposal shows on the supplier's other proposals at once", async ({ page }) => {
  const { api, errors } = await open(page);
  api.freqportSiblings = [2004];
  await expect(row(page, 2004).locator(".mmi-wb-pr-termcell").first()).not.toContainText("Freqport");
  await row(page, 2012).locator(".mmi-wb-issue", { hasText: "No brand" }).click();
  await panel(page).getByRole("button", { name: "Create Freqport and set it" }).click();
  await expect(page.locator(".mmi-wb-pr-row-notice")).toContainText("Created and set brand Freqport.");
  await expect(row(page, 2004).locator(".mmi-wb-pr-termcell").first()).toContainText("Freqport");
  expect(errors).toEqual([]);
});

test("alerts alike are fixed all at once from the strip above the list", async ({ page }) => {
  const { api, errors } = await open(page);
  // Two more bundles filed by XChange but not in the bundles category, and one more title too long.
  for (const id of [2005, 2008]) api.proposals.find((r) => r.id === id)!.flags.push("bundle_not_in_category");
  api.proposals.find((r) => r.id === 2009)!.flags.push("over_length");
  await page.locator('#mmi-wb-proposals [data-view="open"]').click();
  const strip = page.locator("#mmi-wb-alert-groups");
  const bundles = strip.locator('[data-group="bundle_not_in_category"]');
  await expect(bundles).toContainText("Not in Software Bundles");
  await expect(bundles.locator(".mmi-wb-alert-n")).toHaveText("3");
  await expect(strip.locator(".mmi-wb-alert-group").first()).toHaveAttribute("data-group", "brand"); // errors first
  // The one-click fix runs for all three after a second click, and the alert leaves the strip.
  await bundles.click();
  const panel = strip.locator('[data-group-panel="bundle_not_in_category"]');
  await expect(panel).toContainText("3 products on this page");
  const add = panel.locator('[data-fix="move_cat:11:3"]');
  await expect(add).toHaveText("Move to Software Bundles (all 3)");
  await add.click();
  await expect(add).toHaveText("Click again: move to Software Bundles for all 3");
  expect(api.callsTo("products/2005/fix")).toHaveLength(0);
  await add.click();
  await expect(page.locator("#mmi-wb-pr-notice")).toHaveText("Move to Software Bundles: done for 3 of 3 products; their titles were recomposed.");
  for (const id of [2005, 2008, 2014]) expect(api.callsTo(`products/${id}/fix`).at(-1)!.body).toEqual({ kind: "action", action: "move_cat:11:3" });
  await expect(bundles).toHaveCount(0);
  // A fix done product by product: show only those rows, then every row again.
  await strip.locator('[data-group="over_length"]').click();
  await expect(strip.locator('[data-group-panel="over_length"]')).toContainText("“Edit the title” is done product by product");
  await strip.locator('[data-group-panel="over_length"] [data-fix="only"]').click();
  await expect(page.locator("#mmi-wb-pr-rows tr[data-id]")).toHaveCount(2);
  await expect(strip.locator(".mmi-wb-alert-only")).toContainText("Showing only the 2 products with “Too long”");
  await page.locator("#mmi-wb-alert-show-all").click();
  await expect(page.locator("#mmi-wb-pr-rows tr[data-id]")).toHaveCount(22);
  // A brand or category fix opens the list's bulk picker with those products ticked.
  await strip.locator('[data-group="brand"]').click();
  await strip.locator('[data-group-panel="brand"] [data-fix="brand"]').click();
  await expect(page.locator(".mmi-wb-pr-bulk .mmi-wb-selected")).toHaveText("1 ticked");
  await expect(page.locator('#mmi-wb-pr-rows tr[data-id="2012"] input[type="checkbox"]').first()).toBeChecked();
  expect(errors).toEqual([]);
});

test("a brand that exists already says so; choosing one instead sets it", async ({ page }) => {
  const { api } = await open(page);
  await row(page, 2012).locator(".mmi-wb-issue").click();
  await page.locator(".mmi-wb-new-brand-name").fill("Acme Audio");
  await panel(page).locator(".mmi-wb-issue-save").click();
  await expect(panel(page).locator(".notice-error")).toHaveText("A brand named “Acme Audio” already exists. Choose it instead.");
  await panel(page).getByRole("tab", { name: "Choose a brand" }).click();
  await panel(page).locator(".mmi-wb-issue-search").fill("acme");
  await panel(page).getByRole("radio", { name: "Acme Audio" }).check();
  await panel(page).locator(".mmi-wb-issue-remember input").uncheck();
  await panel(page).getByRole("button", { name: "Set Acme Audio" }).click();
  await expect(page.locator(".mmi-wb-pr-row-notice")).toHaveText("Set brand Acme Audio.");
  expect(api.callsTo("products/2012/fix").at(-1)!.body).toEqual({ kind: "brand", ids: [20], remember: [] });
});

test("the Brand cell's picker offers creating a brand that isn't listed", async ({ page }) => {
  await open(page);
  await row(page, 2001).locator(".mmi-wb-pr-term-edit").first().click();
  await page.locator(".mmi-wb-pr-terms-create").click();
  await expect(panel(page)).toContainText("Choose another brand, or create the maker as a new brand.");
  await expect(panel(page).getByRole("tab", { name: "Create a new brand" })).toBeVisible();
});

test("a bundle not in the bundles category is filed with one click", async ({ page }) => {
  const { api } = await open(page);
  await row(page, 2014).locator(".mmi-wb-issue", { hasText: "Not in Software Bundles" }).click();
  await expect(panel(page)).toContainText("XChange files it as a bundle, but our category doesn’t say so.");
  await panel(page).getByRole("button", { name: "Move to Software Bundles" }).click();
  await expect(page.locator(".mmi-wb-pr-row-notice")).toHaveText("Moved to Software Bundles, out of Virtual Processor Plug-Ins.");
  expect(api.callsTo("products/2014/fix")[0]!.body).toEqual({ kind: "action", action: "move_cat:11:3" });
  await expect(row(page, 2014).locator(".mmi-wb-pr-termcell").nth(1)).toContainText("Bundles");
});

test("a title flag's fix opens the title editor; Escape closes a panel", async ({ page }) => {
  await open(page);
  await row(page, 2004).locator(".mmi-wb-issue").click();
  await panel(page).getByRole("button", { name: "Edit the title" }).click();
  await expect(page.locator("#mmi-wb-pr-edit-input")).toBeVisible();
  await expect(panel(page)).toHaveCount(0);
  await page.locator("#mmi-wb-pr-edit-cancel").click();
  await row(page, 2014).locator(".mmi-wb-issue").click();
  await panel(page).getByRole("button", { name: "Change the category…" }).click();
  await expect(panel(page)).toContainText("Xchange says “Software / Effects” (not mapped to any category).");
  await panel(page).locator(".mmi-wb-issue-search").press("Escape");
  await expect(panel(page)).toHaveCount(0);
});

test("results table: a No brand chip opens the brand panel in its row", async ({ page }) => {
  const { api, backend } = await open(page, "data");
  await page.locator("#mmi-wb-q").fill("test plug-in 2");
  const r = page.locator("#mmi-wb-rows tr[data-id='1002']");
  await expect(r).toBeVisible();
  await r.locator('.mmi-health-chip[data-check="no_brand"]').click();
  const p = page.locator("#mmi-wb-rows .mmi-wb-issue-row .mmi-wb-issue-panel");
  await expect(p.locator(".mmi-wb-new-brand-name")).toHaveValue("Freqport");
  await p.locator(".mmi-wb-issue-save").click();
  await expect(page.locator("#mmi-wb-rows .mmi-wb-row-notice")).toContainText("Created and set brand Freqport.");
  expect(api.callsTo("products/1002/fix")).toHaveLength(1);
  expect(backend.callsTo("mmi_workbench_row")).toHaveLength(1);
});
