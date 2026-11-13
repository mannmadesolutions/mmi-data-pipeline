/**
 * Needs a decision (Decide.tsx) and the product drawer (ProductDrawer.tsx): one list of every product something
 * waits on, errors first, with the drawer beside it deciding in place. The mock derives the list from its title
 * proposals: 22 open titles, #2012 with no brand, #2007 with no SKU.
 */
import { expect, test, type Page } from "@playwright/test";
import { MockBackend } from "./mock-backend";
import { openSection } from "./shell";
import { TitleRulesMock } from "./title-rules-mock";

async function open(page: Page, o: { waiting?: boolean; query?: string } = {}) {
  const errors: string[] = [];
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("console", (m) => { if (m.type() === "error" && !/status of 40[0349]/.test(m.text())) errors.push(m.text()); });
  page.on("dialog", (d) => d.accept());
  await page.route("**/mmi-data-pipeline/build/workbench.js*", (r) => r.fulfill({ path: "build/workbench.js", contentType: "text/javascript" }));
  const backend = new MockBackend();
  await backend.attach(page);
  const api = new TitleRulesMock();
  api.waitingOn = o.waiting ?? true;
  await api.attach(page);
  await page.goto(`/${o.query ?? ""}`);
  await page.evaluate(() => { // WordPress's media library: picking resolves at once with one image (as issues.spec)
    (window as unknown as { wp: { media: unknown } }).wp.media = () => {
      let done: () => void = () => {};
      return { on: (_e: string, fn: () => void) => { done = fn; }, open: () => setTimeout(done, 0), state: () => ({ get: () => ({ toJSON: () => [{ id: 99, url: "/wp-includes/images/w-logo-blue.png" }] }) }) };
    };
  });
  return { api, backend, errors };
}

const current = (page: Page) => page.locator('#mmi-wb-menu [aria-current="page"]');
const rows = (page: Page) => page.locator("#mmi-wb-dc-rows tr[data-id]");
const row = (page: Page, id: number) => page.locator(`#mmi-wb-dc-rows tr[data-id="${id}"]`);
const drawer = (page: Page) => page.locator("#mmi-wb-drawer");

test("opens on Needs a decision when something waits: errors first, counts per chip, chips narrow it", async ({ page }) => {
  const { errors } = await open(page);
  await expect(current(page)).toHaveAttribute("data-sec", "decide");
  await expect(rows(page)).toHaveCount(22);
  await expect(rows(page).first()).toHaveAttribute("data-id", "2012"); // no brand: an error, so first
  await expect(row(page, 2012).locator("[data-wait]")).toHaveText(["No brand", "Title"]);
  await expect(page.locator('#mmi-wb-decide [data-kind="all"] .mmi-pill-count')).toHaveText("22");
  // One pill per alert, errors first: No brand, then the warnings, commonest first.
  await expect(page.locator("#mmi-wb-decide .mmi-wb-dc-alert-pill")).toHaveText(["No brand 1", "No picture 2", "No SKU 1"]);
  await page.locator('#mmi-wb-decide [data-kind="key:no_sku"]').click();
  await expect(rows(page)).toHaveCount(1);
  await expect(rows(page).first()).toHaveAttribute("data-id", "2007");
  expect(new URL(page.url()).searchParams.get("wb_kind")).toBe("key:no_sku");
  await page.locator('#mmi-wb-decide [data-kind="key:no_sku"]').click(); // a second click goes back to every product
  await expect(rows(page)).toHaveCount(22);
  await page.locator("#mmi-wb-dc-q").fill("Model 12");
  await expect(rows(page)).toHaveCount(1);
  expect(errors).toEqual([]);
});

test("nothing waits: the Workbench opens on Find and change", async ({ page }) => {
  await open(page, { waiting: false });
  await expect(current(page)).toHaveAttribute("data-sec", "data");
  await expect(page.locator("#mmi-wb-find")).toBeVisible();
});

test("a row opens the drawer beside the list; J and K step; Escape closes; the address keeps it", async ({ page }) => {
  const { errors } = await open(page);
  await row(page, 2012).click();
  const d = drawer(page);
  await expect(d).toHaveAttribute("data-id", "2012");
  await expect(d.locator("h2")).toContainText("Model 12");
  await expect(d.locator('[data-part="issues"] .mmi-wb-issue')).toHaveText(["No brand"]);
  await expect(d.locator('[data-part="title"]')).toContainText("Proposed:");
  await expect(d.locator('[data-part="terms"]')).toContainText("Xchange sends “Freqport”, not mapped");
  expect(new URL(page.url()).searchParams.get("wb_product")).toBe("2012");
  // Side by side at 1440 px.
  const list = await page.locator(".mmi-wb-dc-list").boundingBox();
  const box = await d.boundingBox();
  expect(box!.x).toBeGreaterThan(list!.x + list!.width - 1);
  await page.keyboard.press("j");
  await expect(d).toHaveAttribute("data-id", "2001");
  await expect(row(page, 2001)).toHaveClass(/is-open/);
  await page.keyboard.press("k");
  await expect(d).toHaveAttribute("data-id", "2012");
  await page.keyboard.press("Escape");
  await expect(d).toHaveCount(0);
  expect(new URL(page.url()).searchParams.get("wb_product")).toBeNull();
  expect(errors).toEqual([]);
});

test("a brand created in the drawer redraws that row only", async ({ page }) => {
  const { api, errors } = await open(page);
  await row(page, 2012).click();
  await drawer(page).locator(".mmi-wb-issue", { hasText: "No brand" }).click();
  await expect(drawer(page).locator(".mmi-wb-new-brand-name")).toHaveValue("Freqport");
  await drawer(page).getByRole("button", { name: "Create Freqport and set it" }).click();
  await expect(drawer(page).locator(".mmi-wb-dr-note")).toContainText("Created and set brand Freqport.");
  await expect(drawer(page).locator('[data-part="terms"]')).toContainText("Freqport");
  await expect(drawer(page).locator('[data-part="issues"]')).toHaveCount(0);
  await expect(row(page, 2012).locator("[data-wait]")).toHaveText(["Title"]); // the row redrawn from the drawer's reply
  expect(api.callsTo("waiting")).toHaveLength(1); // the list itself wasn't loaded again
  expect(errors).toEqual([]);
});

test("a brand created and mapped in the drawer redraws the supplier's other products on the page", async ({ page }) => {
  const { api, errors } = await open(page);
  api.freqportSiblings = [2004];
  await expect(row(page, 2004).locator("td").nth(2)).not.toContainText("Freqport");
  await row(page, 2012).click();
  await drawer(page).locator(".mmi-wb-issue", { hasText: "No brand" }).click();
  await expect(drawer(page).locator(".mmi-wb-issue-remember input")).toBeChecked();
  await drawer(page).getByRole("button", { name: "Create Freqport and set it" }).click();
  await expect(drawer(page).locator(".mmi-wb-dr-note")).toContainText("Created and set brand Freqport.");
  await expect(row(page, 2004).locator("td").nth(2)).toContainText("Freqport"); // read again: it is on this page
  expect(api.callsTo("waiting")).toHaveLength(2);
  expect(errors).toEqual([]);
});

test("accepting the title in the drawer: nothing else waits, so the row leaves the list", async ({ page }) => {
  const { api } = await open(page);
  await row(page, 2001).click();
  await drawer(page).getByRole("button", { name: "Accept" }).click();
  await expect(drawer(page).locator(".mmi-wb-dr-note")).toContainText("Title accepted");
  await expect(drawer(page)).toContainText("Nothing waits on this product now.");
  await expect(row(page, 2001)).toHaveCount(0);
  await expect(rows(page)).toHaveCount(21);
  expect(api.callsTo("decide")[0]!.body).toEqual({ action: "accept", ids: [2001] });
  // Title proposals, opened afterwards, shows it accepted.
  await openSection(page, "titles");
  await expect(page.locator('#mmi-wb-pr-rows tr[data-id="2001"]')).toHaveCount(0);
});

test("the title is edited in the drawer", async ({ page }) => {
  const { api } = await open(page);
  await row(page, 2003).click();
  await drawer(page).getByRole("button", { name: "Edit" }).click();
  await page.locator("#mmi-wb-dr-title-input").fill("Acme Audio Synth Model 3 (Download)");
  await page.locator("#mmi-wb-dr-title-input").press("Enter");
  await expect(drawer(page).locator(".mmi-wb-dr-note")).toContainText("Your title is saved");
  expect(api.callsTo("proposals/2003/edit")[0]!.body).toEqual({ title: "Acme Audio Synth Model 3 (Download)", rules: [] });
});

test("wb_product opens the drawer on that product", async ({ page }) => {
  await open(page, { query: "?wb_product=2005" });
  await expect(current(page)).toHaveAttribute("data-sec", "decide");
  await expect(drawer(page)).toHaveAttribute("data-id", "2005");
});

test("below 1200 px the drawer opens under the list", async ({ page }) => {
  await page.setViewportSize({ width: 1100, height: 900 });
  await open(page);
  await row(page, 2012).click();
  const list = await page.locator(".mmi-wb-dc-list").boundingBox();
  const box = await drawer(page).boundingBox();
  expect(box!.y).toBeGreaterThan(list!.y + list!.height - 1);
});

test("each row starts with the product's picture; one with none shows an empty box", async ({ page }) => {
  const { errors } = await open(page);
  await expect(row(page, 2001).locator(".mmi-wb-dc-product .mmi-wb-thumb img")).toHaveAttribute("src", "/wp-includes/images/w-logo-blue.png");
  await expect(row(page, 2002).locator(".mmi-wb-thumb-n")).toHaveText("3");
  await expect(row(page, 2003).locator(".mmi-wb-thumb.is-none")).toHaveAttribute("title", /No picture/);
  await expect(row(page, 2003).locator("[data-wait]")).toHaveText(["No picture", "Title"]);
  // The picture sits before the title and its line, in the Product cell.
  const thumb = await row(page, 2001).locator(".mmi-wb-thumb").boundingBox();
  const title = await row(page, 2001).locator(".mmi-wb-dc-title").boundingBox();
  expect(thumb!.x + thumb!.width).toBeLessThanOrEqual(title!.x);
  expect(errors).toEqual([]);
});

test("an alert's pill lists its products and opens its fixes for all of them: Find pictures for both", async ({ page }) => {
  const { api, errors } = await open(page);
  await page.locator('#mmi-wb-decide [data-kind="key:no_picture"]').click();
  await expect(rows(page)).toHaveCount(2);
  const panel = page.locator('[data-group-panel="no_picture"]');
  await expect(panel).toBeVisible();
  await expect(panel).toContainText("2 products on this page");
  const find = panel.getByRole("button", { name: "Find pictures (all 2)" });
  await find.click();
  await expect(panel.getByRole("button", { name: "Click again: find pictures for all 2" })).toBeVisible();
  await panel.getByRole("button", { name: "Click again: find pictures for all 2" }).click();
  await expect(page.locator("#mmi-wb-dc-notice")).toContainText("Find pictures: done for 2 of 2 products.");
  expect(api.callsTo("products/2003/fix")[0]!.body).toEqual({ kind: "action", action: "pictures:find" });
  expect(api.callsTo("products/2009/fix")).toHaveLength(1);
  // Queued, so the alert stays and offers the media library, product by product, in the drawer.
  await expect(page.locator('[data-group="no_picture"]')).toBeVisible();
  await row(page, 2009).click();
  await drawer(page).locator(".mmi-wb-issue", { hasText: "No picture" }).click();
  await expect(drawer(page).locator('.mmi-wb-issue-panel[data-issue="no_picture"]')).toContainText("queued for the picture runner");
  // Queued, the media library is its first fix, so the panel opens on it.
  await expect(drawer(page).getByRole("button", { name: "Choose from the media library…" })).toHaveAttribute("aria-pressed", "true");
  await drawer(page).getByRole("button", { name: "Choose the featured picture…" }).click();
  await expect(drawer(page).locator(".mmi-wb-dr-note")).toContainText("Featured picture set.");
  expect(api.callsTo("products/2009/fix").at(-1)!.body).toEqual({ kind: "image", attachments: [99] });
  await expect(drawer(page).locator(".mmi-wb-dr-head .mmi-wb-thumb img")).toBeVisible();
  // It has a picture now, so it leaves the No picture list when the list is read again, and the pill counts one.
  await expect(row(page, 2009)).toHaveCount(0);
  await expect(page.locator('#mmi-wb-decide [data-kind="key:no_picture"] .mmi-pill-count')).toHaveText("1");
  expect(errors).toEqual([]);
});

test("the review queues are pills: Title proposals' own tools open under its pill; old links land there", async ({ page }) => {
  const { errors } = await open(page);
  await expect(page.locator("#mmi-wb-menu")).not.toContainText("Title proposals");
  await expect(page.locator('#mmi-wb-menu [data-sec="titles"]')).toHaveCount(0);
  await page.locator('#mmi-wb-decide .mmi-wb-dc-pills [data-kind="titles"]').click();
  const queue = page.locator("#mmi-wb-decide #mmi-wb-proposals");
  await expect(queue).toBeVisible();
  await expect(queue.locator("#mmi-wb-pr-write")).toBeVisible(); // its writer, beside its description
  await expect(page.locator("#mmi-wb-dc-table")).toBeHidden();
  await expect(page.locator("#mmi-wb-dc-q")).toHaveCount(0); // the queue has its own search
  expect(new URL(page.url()).searchParams.get("wb_kind")).toBe("titles");
  await page.locator('#mmi-wb-decide .mmi-wb-dc-pills [data-kind="all"]').click();
  await expect(page.locator("#mmi-wb-dc-table")).toBeVisible();
  await expect(queue).toBeHidden();
  expect(errors).toEqual([]);
});

test("a link to an old review section opens Needs a decision on that pill", async ({ page }) => {
  await open(page, { query: "?wb_sec=titles" });
  await expect(current(page)).toHaveAttribute("data-sec", "decide");
  await expect(page.locator('#mmi-wb-decide .mmi-wb-dc-pills [data-kind="titles"]')).toHaveAttribute("aria-pressed", "true");
  await expect(page.locator("#mmi-wb-decide #mmi-wb-proposals")).toBeVisible();
});
