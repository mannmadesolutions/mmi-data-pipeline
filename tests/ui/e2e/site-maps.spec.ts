/**
 * The Workbench's Site maps section in a real browser, WebKit first, with the REST routes mocked
 * (maker-pages-mock.ts); the workspace's fresh build.
 */
import { expect, test, type Page } from "@playwright/test";
import { MockBackend } from "./mock-backend";
import { TitleRulesMock } from "./title-rules-mock";

async function open(page: Page) {
  const errors: string[] = [];
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("console", (m) => { if (m.type() === "error" && !/status of 40[034]/.test(m.text())) errors.push(m.text()); });
  page.on("dialog", (d) => d.accept());
  await page.route("**/mmi-data-pipeline/build/workbench.js*", (r) => r.fulfill({ path: "build/workbench.js", contentType: "text/javascript" }));
  await new MockBackend().attach(page);
  const api = new TitleRulesMock();
  await api.attach(page);
  await page.goto("/");
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
