/**
 * The Workbench's Brands & sites section in a real browser, WebKit first, with the REST routes mocked
 * (maker-pages-mock.ts); the workspace's fresh build.
 */
import { expect, test, type Page } from "@playwright/test";
import { MockBackend } from "./mock-backend";
import { TitleRulesMock } from "./title-rules-mock";

async function open(page: Page) {
  const errors: string[] = [];
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("console", (m) => { if (m.type() === "error" && !/status of 40[034]/.test(m.text())) errors.push(m.text()); });
  await page.route("**/mmi-data-pipeline/build/workbench.js*", (r) => r.fulfill({ path: "build/workbench.js", contentType: "text/javascript" }));
  await new MockBackend().attach(page);
  const api = new TitleRulesMock();
  await api.attach(page);
  await page.goto("/");
  const sec = page.locator("#mmi-wb-brand-sites");
  await expect(sec.locator("#mmi-wb-bs-rows tr[data-brand]")).toHaveCount(2);
  return { api, errors, sec };
}
const drawer = (page: Page) => page.locator("#mmi-wb-bs-drawer");

test("the brand list: needs work first, no maker site, set aside", async ({ page }) => {
  const { sec, errors } = await open(page);
  await expect(sec.locator("#mmi-wb-bs-rows tr[data-brand]").first()).toContainText("Acme Audio");
  await expect(sec.locator('[data-brand="20"]')).toContainText("2 proposed");
  await expect(sec.locator('[data-brand="20"]')).toContainText("acme.example");
  await expect(sec.locator('[data-brand="21"]')).toContainText("None saved");
  await expect(sec.locator('[data-bview="nomaker"]')).toContainText("1");
  await sec.locator('[data-bview="gone"]').click();
  await expect(sec.locator("#mmi-wb-bs-rows tr[data-brand]")).toHaveCount(1);
  await expect(sec.locator('[data-brand="22"]')).toContainText("set aside");
  await sec.locator("#mmi-wb-bs-q").fill("resell");
  await sec.locator('[data-bview="all"]').click();
  await expect(sec.locator("#mmi-wb-bs-rows tr[data-brand]")).toHaveCount(1);
  expect(errors).toEqual([]);
});

test("a brand's sites: what each is, a site added, the Brand website", async ({ page }) => {
  const { api, sec } = await open(page);
  await sec.locator('[data-brand="20"] button').click();
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
  const { api, sec } = await open(page);
  await sec.locator('[data-brand="20"] button').click();
  const d = drawer(page);
  await expect(d.locator("#mmi-wb-bs-products tbody tr[data-id]")).toHaveCount(5);
  await d.locator("#mmi-wb-bs-find").click();
  await expect(d.getByRole("status").filter({ hasText: "page 2" })).toBeVisible();
  await expect(d.locator("#mmi-wb-bs-notice")).toContainText("1 of 5 products have a sure match, ticked", { timeout: 10_000 });
  await expect(d.locator('tr[data-id="4004"] .mmi-wb-bs-found input')).toBeChecked();
  await expect(d.locator('tr[data-id="4001"] .mmi-wb-bs-found input')).not.toBeChecked();
  await expect(d.locator('tr[data-id="4002"] .mmi-wb-bs-found')).toHaveText("No page names it");
  await d.locator("#mmi-wb-bs-save-found").click();
  await expect(d.locator("#mmi-wb-bs-notice")).toHaveText("1 page saved and being read.");
  expect(api.callsTo("urls/set-many")[0]!.body).toEqual({ items: [{ id: 4004, url: "https://acme.example/cable-10" }] });
  await expect(d.locator('tr[data-id="4004"]')).toHaveCount(0);
});

test("a product row in the drawer decides as in Maker pages", async ({ page }) => {
  const { api, sec } = await open(page);
  await sec.locator('[data-brand="20"] button').click();
  const r = drawer(page).locator('tr[data-id="4001"]');
  await expect(r.locator("[data-action]")).toHaveText(["Use proposed URL", "Not this page", "Set the page", "Maker doesn't publish it"]);
  await expect(drawer(page).locator('tr[data-id="4003"] [data-action]').first()).toHaveText("Use its new address");
  await r.getByRole("button", { name: "Use proposed URL" }).click();
  await expect(r.locator(".notice")).toHaveText("Approved: now its maker page; reading it.");
  expect(api.callsTo("urls/decide")[0]!.body).toEqual({ action: "approve", ids: [4001] });
  await expect(r.locator(".mmi-wb-mp-now")).toContainText("https://maker.example/synth-1");
});

test("a brand with no maker site can be set aside, and brought back", async ({ page }) => {
  const { api, sec } = await open(page);
  await sec.locator('[data-brand="21"] button').click();
  const d = drawer(page);
  await expect(d.locator("#mmi-wb-bs-find")).toBeDisabled();
  await d.locator("#mmi-wb-bs-gone").click();
  await expect(d.locator("#mmi-wb-bs-notice")).toHaveText("Set aside: its maker has no site.");
  expect(api.callsTo("brand-sites/21")[0]!.body).toEqual({ gone: true });
  await expect(d.locator("#mmi-wb-bs-gone")).toHaveText("Bring it back");
});

test("a brand's site-wide fixes: pages moved, a reseller's products to the maker's pages, bundles, an unused site", async ({ page }) => {
  const { api, sec } = await open(page);
  await sec.locator('[data-brand="20"] button').click();
  const d = drawer(page);
  await d.getByRole("button", { name: "Move them to acme-audio.example" }).click();
  await expect(d.locator("#mmi-wb-bs-notice")).toHaveText("2 pages moved to acme-audio.example; reading them.");
  expect(api.callsTo("brand-sites/20/move")[0]!.body).toEqual({ from: "acme.example", to: "acme-audio.example" });
  await expect(d.locator(".mmi-wb-bs-move")).toHaveCount(0);

  await d.locator('[data-host="shop.example"] [data-site-action="to-own"]').click();
  await expect(d.locator("#mmi-wb-bs-notice")).toHaveText("1 product moved to the maker's own pages; reading them.");
  expect(api.callsTo("brand-sites/20/to-own")[0]!.body).toEqual({ from: "shop.example" });

  await d.locator("#mmi-wb-bs-bundles-none").click();
  expect(api.callsTo("brand-sites/20/bundles-none")).toHaveLength(0);
  await d.locator("#mmi-wb-bs-bundles-none").click();
  await expect(d.locator("#mmi-wb-bs-notice")).toHaveText("2 bundles marked: the maker doesn't publish them.");

  await d.locator('[data-host="old.example"] [data-site-action="remove"]').click();
  await expect(d.locator('[data-host="old.example"] [data-site-action="remove"]')).toHaveText("Click again to remove");
  await d.locator('[data-host="old.example"] [data-site-action="remove"]').click();
  await expect(d.locator("#mmi-wb-bs-notice")).toHaveText("old.example taken off the list.");
  expect(api.callsTo("brand-sites/20").at(-1)!.body).toEqual({ remove: "old.example", bundles_none: false });
  await expect(d.locator('[data-host="old.example"]')).toHaveCount(0);
});
