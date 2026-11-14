/**
 * Makers listed by brand (the Workbench's Brands & sites until 2026-11-14) in a real browser, WebKit first, with the REST routes mocked
 * (maker-pages-mock.ts); the workspace's fresh build. The brand list stays on the left and the open brand beside it
 * (owner 2026-11-14, the same split view as Site maps), its products and sites in tabs.
 */
import { expect, test, type Page } from "@playwright/test";
import { MockBackend } from "./mock-backend";
import { openSection } from "./shell";
import { TitleRulesMock } from "./title-rules-mock";

async function open(page: Page, key = "makers") {
  const errors: string[] = [];
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("console", (m) => { if (m.type() === "error" && !/status of 40[034]/.test(m.text())) errors.push(m.text()); });
  page.on("dialog", (d) => { errors.push(`dialog: ${d.message()}`); void d.dismiss(); }); // decisions are asked in place
  await page.route("**/mmi-data-pipeline/build/workbench.js*", (r) => r.fulfill({ path: "build/workbench.js", contentType: "text/javascript" }));
  await new MockBackend().attach(page);
  const api = new TitleRulesMock();
  await api.attach(page);
  await page.goto("/");
  await openSection(page, key);
  const sec = page.locator("#mmi-wb-makers");
  await expect(sec.locator("#mmi-wb-bs-rows tr[data-brand]")).toHaveCount(2);
  return { api, errors, sec };
}
const drawer = (page: Page) => page.locator("#mmi-wb-bs-drawer");
const openBrand = async (page: Page, id: number) => {
  await page.locator(`#mmi-wb-bs-rows [data-brand="${id}"] .mmi-wb-split-name`).click();
  await expect(drawer(page)).toHaveAttribute("data-brand", String(id));
};
const tab = (page: Page, key: string) => drawer(page).locator(`[data-stab="${key}"]`).click();

test("the brand list stays beside the open brand: work pills, more lists, arrow keys", async ({ page }) => {
  const { sec, errors } = await open(page);
  await expect(sec.locator("#mmi-wb-bs-empty")).toContainText("Choose a brand on the left");
  await expect(sec.locator("#mmi-wb-bs-rows tr[data-brand]").first()).toContainText("Acme Audio");
  const acme = sec.locator('[data-brand="20"]');
  await expect(acme).toContainText("acme.example");
  await expect(acme.locator(".mmi-wb-split-num")).toHaveText("4");
  await expect(acme.locator(".mmi-wb-split-num")).toHaveAttribute("title", "2 proposed pages to decide, 1 page gone or moved, 1 product with no page");
  await expect(sec.locator('[data-brand="21"]')).toContainText("None saved");
  await expect(sec.locator('[data-bview="nomaker"]')).toContainText("1");
  await sec.locator("#mmi-wb-bs-view").selectOption("gone");
  await expect(sec.locator("#mmi-wb-bs-rows tr[data-brand]")).toHaveCount(1);
  await expect(sec.locator('[data-brand="22"]')).toContainText("set aside");
  await sec.locator("#mmi-wb-bs-q").fill("resell");
  await sec.locator("#mmi-wb-bs-view").selectOption("all");
  await expect(sec.locator("#mmi-wb-bs-rows tr[data-brand]")).toHaveCount(1);
  await sec.locator("#mmi-wb-bs-q").fill("");
  await sec.locator('[data-bview="work"]').click();

  // The list and the open brand sit side by side; ↓ moves to the next brand.
  await openBrand(page, 20);
  const [list, work] = await Promise.all([sec.locator("#mmi-wb-bs-list").boundingBox(), sec.locator(".mmi-wb-split-work").boundingBox()]);
  expect(work!.x).toBeGreaterThan(list!.x + list!.width - 1);
  expect(new URL(page.url()).searchParams.get("wb_brand")).toBe("20");
  await page.keyboard.press("ArrowDown");
  await expect(drawer(page)).toHaveAttribute("data-brand", "21");
  await expect(sec.locator('#mmi-wb-bs-rows [data-brand="21"] .mmi-wb-split-name')).toBeFocused();
  expect(errors).toEqual([]);
});

test("the open brand: what waits on it said under its header, the tab kept between brands", async ({ page }) => {
  await open(page);
  await openBrand(page, 20);
  const d = drawer(page);
  await expect(d.locator("#mmi-wb-bs-waits")).toContainText("2 proposed pages to decide, 1 page gone or moved, 1 product with no page");
  await expect(d.locator('[data-stab="products"]')).toContainText("4 to do");
  await expect(d.locator('[data-stab="sites"] .mmi-wb-split-dot')).toHaveCount(1); // its pages moved (an undecided role alone asks nothing)
  await tab(page, "sites");
  await openBrand(page, 21);
  await expect(d.locator('[data-stab="sites"]')).toHaveAttribute("aria-selected", "true");
  await expect(d.locator("#mmi-wb-bs-why")).toContainText("No maker site is saved");
  await expect(d.locator('[data-stab="sites"] .mmi-wb-split-dot')).toHaveCount(1);
  await openBrand(page, 20);
  await d.getByRole("button", { name: "Move them to acme-audio.example" }).click();
  await expect(d.locator(".mmi-wb-bs-move")).toHaveCount(0);
  await expect(d.locator('[data-stab="sites"] .mmi-wb-split-dot')).toHaveCount(0); // shop.example's role is still unset: no nag
});

test("a brand's sites: what each is, a site added, the Brand website", async ({ page }) => {
  const { api } = await open(page);
  await openBrand(page, 20);
  await tab(page, "sites");
  const d = drawer(page);
  await expect(d.locator("#mmi-wb-bs-sites tr[data-host]")).toHaveCount(3);
  await d.locator('[data-host="shop.example"] select').selectOption("reseller");
  await expect(d.locator("#mmi-wb-bs-notice")).toHaveText("shop.example: A reseller.");
  expect(api.callsTo("brand-sites/20")[0]!.body).toEqual({ roles: { "shop.example": "reseller" } });
  await d.locator("#mmi-wb-bs-add").fill("https://www.acme-support.example/help");
  await d.getByRole("button", { name: "Add", exact: true }).click();
  await expect(d.locator("#mmi-wb-bs-notice")).toHaveText("acme-support.example added: The maker's own site.");
  await expect(d.locator('[data-host="acme-support.example"]')).toHaveCount(1);
  await d.locator("#mmi-wb-bs-website").fill("https://www.acme.example");
  await d.locator("#mmi-wb-bs-website").press("Enter");
  await expect(d.locator("#mmi-wb-bs-notice")).toHaveText("Brand website saved.");
  expect(api.callsTo("brand-sites/20").at(-1)!.body).toEqual({ website: "https://www.acme.example" });
});

test("Find their pages reads the maker's list, ticks the sure matches, and saves them together", async ({ page }) => {
  const { api } = await open(page);
  await openBrand(page, 20);
  const d = drawer(page);
  await expect(d.locator("#mmi-wb-bs-products li[data-id]")).toHaveCount(5);
  await d.locator("#mmi-wb-bs-find").click();
  await expect(d.getByRole("status").filter({ hasText: "page 2" })).toBeVisible();
  await expect(d.locator("#mmi-wb-bs-notice")).toContainText("1 of 5 products have a sure match, ticked", { timeout: 10_000 });
  // The matches are a batch of their own, above the others: the sure one ticked, a product no page names left out.
  const f = d.locator('[data-batch="found"]');
  await expect(d.locator("#mmi-wb-bs-products > section").first()).toHaveAttribute("data-batch", "found");
  await expect(f.locator('li[data-id="4004"] input')).toBeChecked();
  await expect(f.locator('li[data-id="4001"] input')).not.toBeChecked();
  await expect(f.locator('li[data-id="4002"]')).toHaveCount(0);
  await d.locator("#mmi-wb-bs-save-found").click();
  await expect(d.locator("#mmi-wb-bs-notice")).toHaveText("1 page saved and being read.");
  expect(api.callsTo("urls/set-many")[0]!.body).toEqual({ items: [{ id: 4004, url: "https://acme.example/cable-10" }] });
  await expect(d.locator('li[data-id="4004"]')).toHaveCount(0);
});

test("a product row in the brand: one line with Use and Not this, More opens it as Maker pages decides it", async ({ page }) => {
  const { api } = await open(page);
  await openBrand(page, 20);
  const r = drawer(page).locator('[data-batch="why:sku_slug"] li[data-id="4001"]');
  await expect(r.locator(".mmi-wb-bt-move")).toHaveText("→ maker.example/synth-1");
  await expect(r.locator(".mmi-wb-bt-move")).toHaveAttribute("title", "No page → https://maker.example/synth-1");
  await expect(r.locator(".mmi-wb-bt-what .mmi-badge")).toHaveText("92");
  await r.locator(".mmi-wb-bt-more").click();
  await expect(r.locator(".mmi-wb-bt-detail [data-action]")).toHaveText(["Use proposed URL", "Not this page", "Set the page", "Maker doesn't publish it"]);
  const moved = drawer(page).locator('[data-batch="broken"] li[data-id="4003"]');
  await moved.locator(".mmi-wb-bt-more").click();
  await expect(moved.locator(".mmi-wb-bt-detail [data-action]").first()).toHaveText("Use its new address");
  await r.locator(".mmi-wb-bt-more").click(); // closed again: the outcome shows under the line
  await r.locator('.mmi-wb-bt-line [data-action="use"]').click();
  await expect(r.locator(".notice")).toHaveText("Approved: now its maker page; reading it.");
  expect(api.callsTo("urls/decide")[0]!.body).toEqual({ action: "approve", ids: [4001] });
  await r.locator(".mmi-wb-bt-more").click();
  await expect(r.locator(".mmi-wb-mp-now")).toContainText("https://maker.example/synth-1");
});

test("the brand's products as batches: one per why, the sure ones ticked, each decided once", async ({ page }) => {
  const { api, errors } = await open(page);
  await openBrand(page, 20);
  const d = drawer(page);
  await expect(d.locator("#mmi-wb-bs-products > section")).toHaveCount(5);
  expect(await d.locator("#mmi-wb-bs-products > section").evaluateAll((els) => els.map((e) => e.getAttribute("data-batch"))))
    .toEqual(["why:sitemap_slug", "why:sku_slug", "broken", "none", "has"]);
  const sure = d.locator('[data-batch="why:sku_slug"]');
  await expect(sure.locator(".mmi-wb-bt-why")).toContainText("The page's address is the product's own part number");
  await expect(sure.locator(".mmi-wb-bt-why")).toContainText("no page → maker.example · all 1 sure (70+) · would add Images");
  await expect(sure.locator('li[data-id="4001"] input')).toBeChecked();
  const weak = d.locator('[data-batch="why:sitemap_slug"]');
  await expect(weak.locator(".mmi-wb-bt-why")).toContainText("none sure: look, then tick");
  await expect(weak.locator('[data-batch-action="use"]')).toHaveText("Use the 0 ticked");
  await expect(weak.locator('[data-batch-action="use"]')).toBeDisabled();
  await expect(d.locator('[data-batch="has"] .mmi-wb-bt-why')).toHaveText("Their maker page"); // the maker's home page: More says why

  // A turn-down asks again in place before anything is sent.
  await sure.locator('[data-batch-action="reject"]').click();
  await expect(sure.locator('[data-batch-action="reject"]')).toHaveText("Click again: not these 1");
  expect(api.callsTo("urls/decide")).toHaveLength(0);
  await sure.locator('[data-batch-action="reject"]').click();
  await expect(d.locator("#mmi-wb-bs-notice")).toHaveText("1 page turned down: not proposed again.");
  expect(api.callsTo("urls/decide")[0]!.body).toEqual({ action: "reject", ids: [4001] });
  await expect(d.locator('[data-batch="why:sku_slug"]')).toHaveCount(0);

  // Tick the weak one by hand and use it.
  await weak.locator('li[data-id="4002"] input').check();
  await weak.locator('[data-batch-action="use"]').click();
  await expect(d.locator("#mmi-wb-bs-notice")).toHaveText("1 page approved and being read.");
  expect(api.callsTo("urls/decide")[1]!.body).toEqual({ action: "approve", ids: [4002] });

  // A batch can be shut.
  const broken = d.locator('[data-batch="broken"]');
  await broken.locator('[data-batch-action="toggle"]').click();
  await expect(broken.locator('[data-batch-action="toggle"]')).toHaveText("Show 1");
  await expect(broken.locator("li")).toHaveCount(0);
  expect(errors).toEqual([]);
});

test("a brand with no maker site can be set aside from its header, and brought back", async ({ page }) => {
  const { api } = await open(page);
  await openBrand(page, 21);
  const d = drawer(page);
  await expect(d.locator("#mmi-wb-bs-find")).toBeDisabled();
  await d.locator(".mmi-wb-split-head #mmi-wb-bs-gone").click();
  await expect(d.locator("#mmi-wb-bs-notice")).toHaveText("Set aside: its maker has no site.");
  expect(api.callsTo("brand-sites/21")[0]!.body).toEqual({ gone: true });
  await expect(d.locator("#mmi-wb-bs-gone")).toHaveText("Bring it back");
});

test("a brand's site-wide fixes: pages moved, a reseller's products to the maker's pages, bundles, an unused site", async ({ page }) => {
  const { api, errors } = await open(page);
  await openBrand(page, 20);
  const d = drawer(page);
  // Moves and bundles are brand-wide: their lines show whichever tab is open.
  await d.getByRole("button", { name: "Move them to acme-audio.example" }).click();
  await expect(d.locator("#mmi-wb-bs-notice")).toHaveText("2 pages moved to acme-audio.example; reading them.");
  expect(api.callsTo("brand-sites/20/move")[0]!.body).toEqual({ from: "acme.example", to: "acme-audio.example" });
  await expect(d.locator(".mmi-wb-bs-move")).toHaveCount(0);

  await d.locator("#mmi-wb-bs-bundles-none").click();
  expect(api.callsTo("brand-sites/20/bundles-none")).toHaveLength(0);
  await d.locator("#mmi-wb-bs-bundles-none").click();
  await expect(d.locator("#mmi-wb-bs-notice")).toHaveText("2 bundles marked: the maker doesn't publish them.");

  await tab(page, "sites");
  await d.locator('[data-host="shop.example"] [data-site-action="to-own"]').click();
  await expect(d.locator("#mmi-wb-bs-notice")).toHaveText("1 product moved to the maker's own pages; reading them.");
  expect(api.callsTo("brand-sites/20/to-own")[0]!.body).toEqual({ from: "shop.example" });

  await d.locator('[data-host="old.example"] [data-site-action="remove"]').click();
  await expect(d.locator('[data-host="old.example"] [data-site-action="remove"]')).toHaveText("Click again to remove");
  await d.locator('[data-host="old.example"] [data-site-action="remove"]').click();
  await expect(d.locator("#mmi-wb-bs-notice")).toHaveText("old.example taken off the list.");
  expect(api.callsTo("brand-sites/20").at(-1)!.body).toEqual({ remove: "old.example", bundles_none: false });
  await expect(d.locator('[data-host="old.example"]')).toHaveCount(0);
  expect(errors).toEqual([]);
});

test("nothing in the open brand runs past its box, in either tab; the list meets the table standard", async ({ page }) => {
  await open(page);
  await openBrand(page, 20);
  const over = () => page.evaluate(() => {
    const box = document.querySelector<HTMLElement>(".mmi-wb-split-work")!.getBoundingClientRect();
    return Array.from(document.querySelectorAll<HTMLElement>("#mmi-wb-bs-drawer button, #mmi-wb-bs-drawer select, #mmi-wb-bs-drawer table"))
      .filter((el) => el.getClientRects().length && el.getBoundingClientRect().right > box.right + 1).map((el) => el.id || el.textContent!.trim().slice(0, 30) || el.tagName);
  });
  await expect(drawer(page).locator("#mmi-wb-bs-products li[data-id]")).toHaveCount(5);
  expect(await over()).toEqual([]);
  await drawer(page).locator('li[data-id="4001"] .mmi-wb-bt-more').click(); // a row opened in full fits too
  await expect(drawer(page).locator('li[data-id="4001"] .mmi-wb-bt-detail')).toBeVisible();
  expect(await over()).toEqual([]);
  await tab(page, "sites");
  await expect(drawer(page).locator("#mmi-wb-bs-sites tr[data-host]")).toHaveCount(3);
  expect(await over()).toEqual([]);
  const list = page.locator("#mmi-wb-bs-table");
  await expect(list.locator("thead .mmi-resize-handle")).toHaveCount(2);
  expect(await list.evaluate((t: HTMLTableElement) => Array.from(t.tHead!.rows[0]!.cells).every((c) => c.classList.contains("mmi-wb-job-sortable")))).toBe(true);
});

test("one at a time: the evidence read as it opens, J and K to move, A and R decide and move on, typing never decides", async ({ page }) => {
  const { api, errors } = await open(page);
  await openBrand(page, 20);
  const d = drawer(page);
  await d.locator("#mmi-wb-bs-deck-open").click();
  const deck = d.locator("#mmi-wb-bs-deck");
  await expect(deck).toHaveAttribute("data-id", "4002"); // the first batch's first product
  await expect(deck.locator(".mmi-wb-deck-pos")).toHaveText("1 of 5");
  await expect(deck.locator(".mmi-wb-deck-why")).toHaveText("Found by name in the maker's sitemap (weak match)");
  await expect(deck.locator(".mmi-wb-mp-evidence")).toBeVisible();
  expect(api.callsTo("urls/evidence")[0]!.body).toEqual({ ids: [4002] });
  await expect(deck).toBeFocused();
  await page.keyboard.press("j");
  await expect(deck).toHaveAttribute("data-id", "4001");
  await page.keyboard.press("k");
  await expect(deck).toHaveAttribute("data-id", "4002");
  await page.keyboard.press("a");
  await expect(deck.locator(".notice")).toHaveText("Approved: now its maker page; reading it.");
  expect(api.callsTo("urls/decide")[0]!.body).toEqual({ action: "approve", ids: [4002] });
  await expect(deck).toHaveAttribute("data-id", "4001"); // moved on by itself
  await deck.focus();
  await page.keyboard.press("r");
  expect(api.callsTo("urls/decide").length).toBeGreaterThan(0);
  await expect(deck).toHaveAttribute("data-id", "4003");
  await expect.poll(() => api.callsTo("urls/decide")[1]?.body).toEqual({ action: "reject", ids: [4001] });
  // E opens the address editor; letters typed there stay in it.
  await deck.focus();
  await page.keyboard.press("e");
  const input = deck.locator("#mmi-wb-mp-url-4003");
  await expect(input).toBeFocused();
  await page.keyboard.type("an");
  await expect(input).toHaveValue(/an$/);
  expect(api.callsTo("urls/decide")).toHaveLength(2);
  await deck.locator('[data-deck="close"]').click();
  await expect(deck).toHaveCount(0);
  expect(errors).toEqual([]);
});
