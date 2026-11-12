/**
 * The Workbench's Site maps section in a real browser, WebKit first, with the REST routes mocked
 * (maker-pages-mock.ts); the workspace's fresh build.
 */
import { expect, test, type Page } from "@playwright/test";
import { MockBackend } from "./mock-backend";
import { openSection } from "./shell";
import { TitleRulesMock } from "./title-rules-mock";

async function open(page: Page, key = "maps") {
  const errors: string[] = [];
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("console", (m) => { if (m.type() === "error" && !/status of 40[034]/.test(m.text())) errors.push(m.text()); });
  page.on("dialog", (d) => d.accept());
  await page.route("**/mmi-data-pipeline/build/workbench.js*", (r) => r.fulfill({ path: "build/workbench.js", contentType: "text/javascript" }));
  await new MockBackend().attach(page);
  const api = new TitleRulesMock();
  await api.attach(page);
  await page.goto("/");
  await openSection(page, key);
  const sec = page.locator("#mmi-wb-site-maps");
  await expect(sec.locator("#mmi-wb-sm-rows tr[data-host]")).toHaveCount(1);
  return { api, errors, sec };
}
const panel = (page: Page) => page.locator("#mmi-wb-sm-panel");

test("the site list: gaps to fill first, by map status", async ({ page }) => {
  const { sec, errors } = await open(page);
  await expect(sec.locator('[data-host="acme.example"]')).toContainText("Draft map");
  await expect(sec.locator('[data-host="acme.example"]')).toContainText("3 on 2 products");
  await sec.locator('[data-sview="all"]').click();
  await expect(sec.locator("#mmi-wb-sm-rows tr[data-host]")).toHaveCount(3);
  await expect(sec.locator('[data-host="wall.example"]')).toContainText("2 refused");
  await expect(sec.locator('[data-sview="refused"]')).toContainText("1");
  expect(errors).toEqual([]);
});

test("a site's pages, its gaps part by part, and reading the unread ones", async ({ page }) => {
  const { api, sec } = await open(page);
  await sec.locator('[data-host="acme.example"] button').click();
  const p = panel(page);
  await expect(p.locator("#mmi-wb-sm-ready")).toContainText("2 readable");
  await expect(p.locator('#mmi-wb-sm-gaps tr[data-id="4001"] [data-code="A"]')).toHaveCount(2);
  await expect(p.locator('#mmi-wb-sm-gaps tr[data-id="4002"] td[data-code="G"]')).toHaveAttribute("title", /Gap left/);
  await p.getByRole("button", { name: "Read them" }).click();
  await expect(p.locator("#mmi-wb-sm-notice")).toHaveText("1 page queued to read.");
  expect(api.callsTo("pages/queue")[0]!.body).toEqual({ ids: [4003], mode: "read" });
});

test("Fill: reviewed first, a product left out, then what it wrote and Undo", async ({ page }) => {
  const { api, sec } = await open(page);
  await sec.locator('[data-host="acme.example"] button').click();
  const p = panel(page);
  await expect(p.locator("#mmi-wb-sm-fill")).toHaveText("Confirm map & fill 3 gaps on 2 products");
  await p.locator("#mmi-wb-sm-fill").click();
  await expect(p.locator("#mmi-wb-sm-review li")).toHaveCount(2);
  await expect(p.locator('#mmi-wb-sm-review li[data-id="4001"]')).toContainText("gets Pictures, Manual");
  await p.locator('#mmi-wb-sm-review li[data-id="4002"] input').uncheck();
  await p.locator("#mmi-wb-sm-fill-go").click();
  await expect(p.locator("#mmi-wb-sm-notice")).toContainText("Map confirmed. 1 product queued to read and write; 1 queued for pictures.");
  expect(api.callsTo("maps/acme.example/fill")[0]!.body).toEqual({ fields: 3, skip: [4002] });
  await expect(p.locator("#mmi-wb-sm-why")).toHaveText("Nothing to fill: every gain the pages offer is written.");
  await expect(p.locator(".mmi-wb-sm-run")).toContainText("written: manual; pictures 2 added");
  await p.locator(".mmi-wb-sm-run").getByRole("button", { name: "Undo" }).click();
  await expect(p.locator("#mmi-wb-sm-notice")).toHaveText("Undone on 1 product.");
  expect(api.callsTo("maps/acme.example/fills/undo")[0]!.body).toEqual({ run: "20261109230000-abcd" });
});

test("a site's map switched off and back on", async ({ page }) => {
  const { api, sec } = await open(page);
  await sec.locator('[data-sview="all"]').click();
  await sec.locator('[data-host="fine.example"] button').click();
  const p = panel(page);
  await p.getByRole("button", { name: "Switch the map off" }).click();
  await expect(p.locator("#mmi-wb-sm-notice")).toHaveText("Map switched off: the site is read without it.");
  await expect(p.getByRole("button", { name: "Turn the map back on" })).toBeVisible();
  await p.getByRole("button", { name: "Turn the map back on" }).click();
  await expect(p.locator("#mmi-wb-sm-notice")).toHaveText("The map is back as a draft.");
  expect(api.callsTo("maps/fine.example/off")).toHaveLength(1);
});

test("pictures in the Fill review: veto on one product, make one featured, undo, and veto a picture on every product", async ({ page }) => {
  const { api, sec } = await open(page);
  await sec.locator('[data-host="acme.example"] button').click();
  const p = panel(page);
  await p.locator("#mmi-wb-sm-fill").click();
  await p.locator('[data-pics="4001"]').click();
  const pics = p.locator('[data-pictures="4001"]');
  await expect(pics.locator(".mmi-wb-pc-tile")).toHaveCount(3);
  await expect(pics.locator(".mmi-wb-pc-tile").first()).toContainText("Featured · New");
  await pics.locator('.mmi-wb-pc-tile[data-kind="kept"]').getByRole("button", { name: "Make featured" }).click();
  await expect(pics.getByRole("status")).toHaveText("Featured on this product.");
  await expect(pics.locator(".mmi-wb-pc-tile").first()).toContainText("Featured · Kept");
  expect(api.callsTo("pictures/choices")[0]!.body).toEqual({ kind: "featured", scope: "product", host: "acme.example", product: 4001, att: 4501, url: "" });
  await pics.locator(".mmi-wb-pc-tile").first().getByRole("button", { name: "Undo featured" }).click();
  await expect(pics.getByRole("status")).toHaveText("Choice taken back.");
  await expect(pics.locator(".mmi-wb-pc-tile").first()).toContainText("Featured · New");
  await pics.locator(".mmi-wb-pc-tile").nth(1).getByRole("button", { name: "Veto", exact: true }).click();
  await expect(pics.locator('.mmi-wb-pc-tile[data-kind="vetoed"]')).toHaveCount(1);
  await pics.locator('.mmi-wb-pc-tile[data-kind="vetoed"]').getByRole("button", { name: "Undo veto" }).click();
  await expect(pics.locator('.mmi-wb-pc-tile[data-kind="vetoed"]')).toHaveCount(0);

  await p.locator("#mmi-wb-sm-site-pics").click();
  const site = p.locator("#mmi-wb-sm-site-pictures");
  await expect(site.locator('[data-group="logo"]')).toContainText("2 products");
  await site.locator('[data-group="logo"]').getByRole("button", { name: "Veto on every product" }).click();
  await expect(site.getByRole("status")).toHaveText("Vetoed on every product of this site.");
  await expect(site.locator('[data-group="logo"]')).toContainText("Vetoed · 2 products");
  expect(api.callsTo("pictures/choices").at(-1)!.body).toEqual({ kind: "veto", scope: "site", host: "acme.example", product: 4001, att: 0, url: "https://acme.example/img/logo.png" });
});

test("cells decided in place, text left out, and the site's settings", async ({ page }) => {
  const { api, sec } = await open(page);
  await sec.locator('[data-host="acme.example"] button').click();
  const p = panel(page);
  await p.locator('#mmi-wb-sm-gaps tr[data-id="4001"] td[data-field="os"] button').click();
  await expect(p.locator("#mmi-wb-sm-cellbar")).toContainText("Acme Synth 1 (Download) · Requirements: To review");
  await p.getByRole("button", { name: "Use the page's requirements" }).click();
  await expect(p.locator("#mmi-wb-sm-notice")).toHaveText("The page's requirements are written.");
  expect(api.callsTo("maps/acme.example/requirements")[0]!.body).toEqual({ id: 4001, choice: "use" });
  await expect(p.locator('#mmi-wb-sm-gaps tr[data-id="4001"] td[data-field="os"]')).toHaveAttribute("data-code", "H");

  await p.locator('#mmi-wb-sm-gaps tr[data-id="4002"] td[data-field="description"] button').click();
  await p.getByRole("button", { name: "Its page has none of this" }).click();
  await expect(p.locator("#mmi-wb-sm-notice")).toHaveText("Closed: its page has none of this.");
  expect(api.callsTo("maps/acme.example/none")[0]!.body).toEqual({ id: 4002, field: "description", undo: false });
  await p.locator('#mmi-wb-sm-gaps tr[data-id="4002"] td[data-field="description"] button').click();
  await p.getByRole("button", { name: 'Take back "its page has none"' }).click();
  await expect(p.locator("#mmi-wb-sm-notice")).toHaveText("Taken back: the gap is open again.");

  await p.getByText("Text left out on this site").click();
  await expect(p.locator("#mmi-wb-sm-skips")).toContainText("None yet.");
  await p.getByRole("button", { name: "Suggest lines" }).click();
  await p.locator(".mmi-wb-sm-suggest li", { hasText: "Add to cart" }).getByRole("button", { name: "Leave it out" }).click();
  await expect(p.locator("#mmi-wb-sm-skips")).toContainText("Add to cart");
  expect(api.callsTo("maps/acme.example/text-skip")[0]!.body).toEqual({ add: ["Add to cart"], remove: [] });

  await p.getByText("Site settings").click();
  await p.locator("#mmi-wb-sm-pattern").fill("/products/{slug}/");
  await p.locator(".mmi-wb-sm-settings").getByRole("checkbox").check();
  await p.locator(".mmi-wb-sm-settings").getByRole("button", { name: "Save" }).click();
  await expect(p.locator("#mmi-wb-sm-notice")).toHaveText("Settings saved.");
  expect(api.callsTo("maps/acme.example/settings")[0]!.body).toEqual({ pattern: "/products/{slug}/", render: true });
});

test("proposed map parts: a site's list, approve one, reject one, approve all", async ({ page }) => {
  const { api, sec } = await open(page);
  await expect(sec.locator('[data-sview="proposals"]')).toContainText("1");
  await sec.locator('[data-sview="proposals"]').click();
  await expect(sec.locator("#mmi-wb-sm-rows tr[data-host]")).toHaveCount(1);
  await sec.locator('[data-host="acme.example"] button').click();
  const p = panel(page);
  await expect(p.locator("#mmi-wb-sm-proposals .mmi-wb-sm-proposal")).toHaveCount(2);
  await expect(p.locator('[data-proposal="27"]')).toContainText("Pictures · found on 3 of 3 pages");
  await expect(p.locator('[data-proposal="27"]')).toContainText("Reviewed: Checked: the product's banner picture.");
  await expect(p.locator('[data-proposal="27"] img')).toHaveCount(1);
  await p.locator('[data-proposal="28"]').getByRole("button", { name: "Reject" }).click();
  await expect(p.locator("#mmi-wb-sm-notice")).toHaveText("Manual: rejected.");
  expect(api.callsTo("map-proposals/28")[0]!.body).toEqual({ decision: "reject" });
  await expect(p.locator("#mmi-wb-sm-proposals .mmi-wb-sm-proposal")).toHaveCount(1);
  await p.getByRole("button", { name: "Approve all 1" }).click();
  await expect(p.locator("#mmi-wb-sm-notice")).toHaveText("1 part approved.");
  expect(api.callsTo("map-proposals/site")[0]!.body).toEqual({ host: "acme.example" });
  await expect(p.locator("#mmi-wb-sm-proposals")).toHaveCount(0);
});
