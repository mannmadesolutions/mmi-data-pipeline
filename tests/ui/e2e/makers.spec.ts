/**
 * Makers (owner 2026-11-14): Brands & sites and Site maps as one Workbench entry, in a real browser, WebKit first,
 * with the REST routes mocked (maker-pages-mock.ts). A brand lists its maker sites under it with their map's state;
 * an open brand's Map tab is its maker site's map; an open site names the brands it serves and opens them in place.
 */
import { expect, test, type Page } from "@playwright/test";
import { MockBackend } from "./mock-backend";
import { openSection } from "./shell";
import { TitleRulesMock } from "./title-rules-mock";

async function open(page: Page, search = "") {
  const errors: string[] = [];
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("console", (m) => { if (m.type() === "error" && !/status of 40[034]/.test(m.text())) errors.push(m.text()); });
  page.on("dialog", (d) => { errors.push(`dialog: ${d.message()}`); void d.dismiss(); });
  await page.route("**/mmi-data-pipeline/build/workbench.js*", (r) => r.fulfill({ path: "build/workbench.js", contentType: "text/javascript" }));
  await new MockBackend().attach(page);
  const api = new TitleRulesMock();
  await api.attach(page);
  await page.goto(`/${search}`);
  if (!search) await openSection(page, "makers");
  const sec = page.locator("#mmi-wb-makers");
  await expect(sec).toBeVisible();
  return { api, errors, sec };
}
const drawer = (page: Page) => page.locator("#mmi-wb-bs-drawer");
const param = (page: Page, k: string) => new URL(page.url()).searchParams.get(k);

test("one menu entry: Brands & sites and Site maps are gone from the menu, Makers lists by brand first", async ({ page }) => {
  const { sec, errors } = await open(page);
  await expect(page.locator('#mmi-wb-menu [data-sec="sites"], #mmi-wb-menu [data-sec="maps"]')).toHaveCount(0);
  await expect(page.locator('#mmi-wb-menu [data-sec="makers"]')).toHaveText(/Makers/);
  await expect(sec.locator("#mmi-wb-mk-by-brand")).toHaveAttribute("aria-pressed", "true");
  await expect(sec.locator("#mmi-wb-bs-rows tr[data-brand]")).toHaveCount(2);
  expect(errors).toEqual([]);
});

test("a brand shows its maker sites under it; a site opens the brand on that site's map", async ({ page }) => {
  const { sec, errors } = await open(page);
  const acme = sec.locator('#mmi-wb-bs-rows [data-brand="20"]');
  // Only the maker's own sites are listed (shop.example has no role, old.example is a reseller), with what waits on the map.
  await expect(acme.locator(".mmi-wb-mk-sites li")).toHaveCount(1);
  await expect(acme.locator('li[data-site="acme.example"]')).toContainText("2 parts");
  await expect(sec.locator('#mmi-wb-bs-rows [data-brand="21"] .mmi-wb-mk-sites')).toHaveCount(0);
  await acme.locator('li[data-site="acme.example"] button').click();
  await expect(drawer(page)).toHaveAttribute("data-brand", "20");
  await expect(drawer(page).locator('[data-stab="map"]')).toHaveAttribute("aria-selected", "true");
  await expect(drawer(page).locator('[data-stab="map"]')).toContainText("2 to approve");
  await expect(drawer(page).locator('[data-stab="map"] .mmi-wb-split-dot')).toHaveCount(1);
  await expect(page.locator("#mmi-wb-sm-panel")).toHaveAttribute("data-host", "acme.example");
  await expect(page.locator("#mmi-wb-sm-parts")).toBeVisible();
  expect(param(page, "wb_brand")).toBe("20");
  expect(param(page, "wb_site")).toBe("acme.example");
  // The brand's tabs run in the order the work goes, and Products is called Pages.
  await expect(drawer(page).locator(":scope > .mmi-wb-split-tabs [data-stab]")).toHaveText([/^Pages/, /^Map/, /^Sites/]);
  await drawer(page).locator('[data-stab="products"]').click();
  expect(param(page, "wb_site")).toBeNull();
  expect(errors).toEqual([]);
});

test("a brand with no maker site says so on its Map tab", async ({ page }) => {
  await open(page);
  await page.locator('#mmi-wb-bs-rows [data-brand="21"] .mmi-wb-split-name').click();
  await expect(drawer(page)).toHaveAttribute("data-brand", "21");
  await drawer(page).locator('[data-stab="map"]').click();
  await expect(page.locator("#mmi-wb-mk-nomap")).toContainText("No maker site is saved");
  await expect(page.locator("#mmi-wb-sm-panel")).toHaveCount(0);
});

test("by site: an open site names the brands it is the maker's site of, and opens one in place", async ({ page }) => {
  const { sec, errors } = await open(page);
  await sec.locator("#mmi-wb-mk-by-site").click();
  await expect(sec.locator("#mmi-wb-sm-rows tr[data-host]")).toHaveCount(1);
  await sec.locator('#mmi-wb-sm-rows [data-host="acme.example"] button').click();
  await expect(page.locator("#mmi-wb-sm-panel")).toHaveAttribute("data-host", "acme.example");
  expect(param(page, "wb_site")).toBe("acme.example");
  expect(param(page, "wb_brand")).toBeNull();
  await expect(sec.locator("#mmi-wb-mk-owners")).toHaveText("The maker's site of Acme Audio.");
  await sec.locator('#mmi-wb-mk-owners [data-owner="20"]').click();
  await expect(sec.locator("#mmi-wb-mk-by-brand")).toHaveAttribute("aria-pressed", "true");
  await expect(drawer(page)).toHaveAttribute("data-brand", "20");
  await expect(drawer(page).locator('[data-stab="map"]')).toHaveAttribute("aria-selected", "true");
  await expect(page.locator("#mmi-wb-sm-panel")).toHaveAttribute("data-host", "acme.example");
  expect(errors).toEqual([]);
});

test("old links land: Site maps' and Brands & sites' keys, and a brand on its site's map", async ({ page }) => {
  await open(page, "?wb_sec=maps&wb_site=acme.example");
  await expect(page.locator('#mmi-wb-menu [data-sec="makers"]')).toHaveAttribute("aria-current", "page");
  await expect(page.locator("#mmi-wb-mk-by-site")).toHaveAttribute("aria-pressed", "true");
  await expect(page.locator("#mmi-wb-sm-panel")).toHaveAttribute("data-host", "acme.example");
  await page.goto("/?wb_sec=sites&wb_brand=20&wb_site=acme.example");
  await expect(drawer(page)).toHaveAttribute("data-brand", "20");
  await expect(drawer(page).locator('[data-stab="map"]')).toHaveAttribute("aria-selected", "true");
  await expect(page.locator("#mmi-wb-sm-panel")).toHaveAttribute("data-host", "acme.example");
});

test("an approval on a brand's Map tab updates the site under the brand", async ({ page }) => {
  const { sec, errors } = await open(page, "?wb_brand=20&wb_site=acme.example");
  await expect(page.locator("#mmi-wb-sm-panel")).toHaveAttribute("data-host", "acme.example");
  await page.locator('#mmi-wb-sm-proposed [data-proposal="27"] [data-action="approve"]').click();
  await expect(page.locator("#mmi-wb-sm-notice")).toHaveText("Pictures: approved as this site's part.");
  await page.locator('#mmi-wb-sm-proposed [data-proposal="28"] [data-action="approve"]').click();
  await expect(sec.locator('#mmi-wb-bs-rows li[data-site="acme.example"]')).toContainText("Draft map");
  await expect(drawer(page).locator('[data-stab="map"] .mmi-wb-split-dot')).toHaveCount(0);
  expect(errors).toEqual([]);
});
