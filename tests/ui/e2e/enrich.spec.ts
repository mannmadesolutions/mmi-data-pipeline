/**
 * The Workbench's Description and picture proposals section in a real browser,
 * WebKit first, with the REST routes mocked; the workspace's fresh build.
 */
import { expect, test, type Page } from "@playwright/test";
import { MockBackend } from "./mock-backend";
import { openSection } from "./shell";
import { TitleRulesMock } from "./title-rules-mock";

async function open(page: Page, key = "descriptions") {
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
  const sec = page.locator("#mmi-wb-enrich");
  await expect(sec.locator("#mmi-wb-en-rows tr[data-id]")).toHaveCount(6);
  return { api, errors, sec };
}
const row = (page: Page, id: number) => page.locator(`#mmi-wb-en-rows tr[data-id="${id}"]`);
/** A product's pictures and actions: the sub-row under its row. */
const pics = (page: Page, id: number) => page.locator(`#mmi-wb-en-rows tr[data-pics-for="${id}"]`);

test("descriptions to review: now, proposed, where from; counts per kind and source", async ({ page }) => {
  const { sec, errors } = await open(page);
  await expect(sec.locator('[data-kind="content"]')).toContainText("6");
  await expect(sec.locator('[data-kind="image"]')).toContainText("3");
  await expect(row(page, 3001).locator(".mmi-wb-en-now")).toHaveText("No description now.");
  await expect(row(page, 3001).locator(".mmi-wb-en-new")).toHaveText("A full description of Acme Synth 1.");
  await expect(row(page, 3001).getByRole("link", { name: "Maker's page" })).toHaveAttribute("href", "https://maker.example/p");
  await expect(row(page, 3001).getByRole("link", { name: "Maker's page" })).toHaveAttribute("title", "Description from the maker's page");
  await page.locator("#mmi-wb-en-source").selectOption("web_assets");
  await expect(sec.locator("#mmi-wb-en-rows tr[data-id]")).toHaveCount(3);
  await expect(sec.locator('[data-view="proposed"]')).toContainText("3");
  expect(errors).toEqual([]);
});

test("accept one, reject ticked ones, write now and put back", async ({ page }) => {
  const { api, sec } = await open(page);
  await row(page, 3001).getByRole("button", { name: "Accept" }).click();
  await expect(page.locator("#mmi-wb-en-notice")).toHaveText("1 description accepted: written in the next batch.");
  await row(page, 3002).getByRole("checkbox").check();
  await row(page, 3003).getByRole("checkbox").check();
  await page.locator("#mmi-wb-en-reject-ticked").click();
  await expect(page.locator("#mmi-wb-en-notice")).toHaveText("2 descriptions rejected.");
  expect(api.callsTo("enrich/decide")[1]!.body).toEqual({ action: "reject", kind: "content", ids: [3002, 3003] });
  await sec.locator('[data-view="accepted"]').click();
  await row(page, 3001).getByRole("button", { name: "Write now" }).click();
  await expect(row(page, 3001)).toHaveCount(0);
  await sec.locator('[data-view="applied"]').click();
  await row(page, 3001).getByRole("button", { name: "Put back" }).click();
  await expect(row(page, 3001)).toHaveCount(0);
  await sec.locator('[data-view="reverted"]').click();
  await expect(row(page, 3001)).toContainText("Put back");
});

test("pictures are picked under the row: own ones kept, the first is featured, ordered by dragging, saved as you go", async ({ page }) => {
  const { api, sec } = await open(page);
  await sec.locator('[data-kind="image"]').click();
  await expect(sec.locator("#mmi-wb-en-rows tr[data-id]")).toHaveCount(3);
  const r = pics(page, 4001);
  const logo = '.mmi-wb-en-pic[data-token="/wp-includes/images/w-logo-blue.png"]';
  const own = '.mmi-wb-en-pic[data-token="att:77"]';
  const chose = () => api.callsTo("enrich/4001/image/choose").at(-1)!.body;
  await expect(r.locator(".mmi-wb-en-pic")).toHaveCount(3);
  await expect(r.locator(".mmi-wb-en-pic-own")).toHaveText("now");
  await expect(r.locator(".mmi-wb-en-star")).toHaveCount(0); // ordering is by dragging now
  // Nothing saved yet: the product's own picture starts picked, so adding one keeps it.
  await expect(r.locator(`${own} .mmi-wb-en-pic-badge`)).toHaveText("Featured");
  await r.locator(`${logo} .mmi-wb-en-pic-img`).click();
  await expect(r.locator(`${logo} .mmi-wb-en-pic-badge`)).toHaveText("1");
  expect(chose()).toEqual({ images: ["att:77", "/wp-includes/images/w-logo-blue.png"] });
  await expect(row(page, 4001).locator(".mmi-wb-en-used")).toHaveText("2 of 3 used");
  // Dragged before the first tile, it takes the featured place, and the tiles follow the pick.
  await r.locator(logo).dragTo(r.locator(own), { targetPosition: { x: 6, y: 40 } });
  await expect(r.locator(`${logo} .mmi-wb-en-pic-badge`)).toHaveText("Featured");
  expect(chose()).toEqual({ images: ["/wp-includes/images/w-logo-blue.png", "att:77"] });
  await expect(r.locator(".mmi-wb-en-pic").first()).toHaveAttribute("data-token", "/wp-includes/images/w-logo-blue.png");
  // Alt+Left moves a focused picture one place: the product's own is featured again.
  await r.locator(`${own} .mmi-wb-en-pic-img`).focus();
  await page.keyboard.press("Alt+ArrowLeft");
  await expect(r.locator(`${own} .mmi-wb-en-pic-badge`)).toHaveText("Featured");
  expect(chose()).toEqual({ images: ["att:77", "/wp-includes/images/w-logo-blue.png"] });
  // Dropped on a picture left out, it is left out too.
  await r.locator(logo).dragTo(r.locator(".mmi-wb-en-pic.is-out").first());
  expect(chose()).toEqual({ images: ["att:77"] });
  await expect(r.locator(`${logo}.is-out`)).toHaveCount(1);
  await r.locator(`${own} .mmi-wb-en-pic-img`).click();
  await expect(r.getByRole("button", { name: "Accept" })).toBeDisabled(); // nothing picked
  // The picture size is the person's: the slider resizes every tile, and is kept for the next visit.
  await sec.locator("#mmi-wb-en-size").fill("200");
  await expect(r.locator(`${own} .mmi-wb-en-pic-img`)).toHaveCSS("width", "200px");
  expect(await page.evaluate(() => localStorage.getItem("mmi-wb-en-pic-size"))).toBe("200");
  // A row with no pictures now starts with the first candidates; Accept saves the shown pick before deciding.
  const n = api.callsTo("enrich/4002/image/choose").length;
  await pics(page, 4002).getByRole("button", { name: "Accept" }).click();
  await expect(page.locator("#mmi-wb-en-notice")).toHaveText("1 picture proposal accepted: written in the next batch.");
  expect(api.callsTo("enrich/4002/image/choose").length).toBe(n + 1);
  expect(api.callsTo("enrich/4002/image/choose").at(-1)!.body).toEqual({ images: ["/wp-includes/images/w-logo-blue.png", "/wp-includes/images/wpicons.png"] });
});

test("a picture smaller than the standard is marked", async ({ page }) => {
  const { sec } = await open(page);
  await sec.locator('[data-kind="image"]').click();
  await expect(pics(page, 4002).locator(".mmi-wb-en-pic-low")).toHaveText("small");
  await expect(row(page, 4002).locator(".mmi-wb-en-used-low")).toContainText("1 small");
});
