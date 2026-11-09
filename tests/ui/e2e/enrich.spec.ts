/**
 * The Workbench's Description and picture proposals section in a real browser,
 * WebKit first, with the REST routes mocked; the workspace's fresh build.
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
  const sec = page.locator("#mmi-wb-enrich");
  await expect(sec.locator("#mmi-wb-en-rows tr[data-id]")).toHaveCount(6);
  return { api, errors, sec };
}
const row = (page: Page, id: number) => page.locator(`#mmi-wb-en-rows tr[data-id="${id}"]`);

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

test("pictures are picked in the row: own ones kept, featured first, saved as you click", async ({ page }) => {
  const { api, sec } = await open(page);
  await sec.locator('[data-kind="image"]').click();
  await expect(sec.locator("#mmi-wb-en-rows tr[data-id]")).toHaveCount(3);
  const r = row(page, 4001);
  const logo = '.mmi-wb-en-pic[data-token="/wp-includes/images/w-logo-blue.png"]';
  await expect(r.locator(".mmi-wb-en-pic")).toHaveCount(3);
  await expect(r.locator(".mmi-wb-en-pic-own")).toHaveText("now");
  // Nothing saved yet: the product's own picture starts picked, so adding one keeps it.
  await expect(r.locator('.mmi-wb-en-pic[data-token="att:77"] .mmi-wb-en-pic-badge')).toHaveText("Featured");
  await r.locator(`${logo} .mmi-wb-en-pic-img`).click();
  await expect(r.locator(`${logo} .mmi-wb-en-pic-badge`)).toHaveText("1");
  expect(api.callsTo("enrich/4001/image/choose").at(-1)!.body).toEqual({ images: ["att:77", "/wp-includes/images/w-logo-blue.png"] });
  await r.locator(logo).getByRole("button", { name: "Make this the featured picture" }).click();
  await expect(r.locator(`${logo} .mmi-wb-en-pic-badge`)).toHaveText("Featured");
  expect(api.callsTo("enrich/4001/image/choose").at(-1)!.body).toEqual({ images: ["/wp-includes/images/w-logo-blue.png", "att:77"] });
  await r.locator('.mmi-wb-en-pic[data-token="att:77"] .mmi-wb-en-pic-img').click();
  await r.locator(`${logo} .mmi-wb-en-pic-img`).click();
  await expect(r.getByRole("button", { name: "Accept" })).toBeDisabled(); // nothing picked
  // A row with no pictures now starts with the first candidates; Accept saves the shown pick before deciding.
  const n = api.callsTo("enrich/4002/image/choose").length;
  await row(page, 4002).getByRole("button", { name: "Accept" }).click();
  await expect(page.locator("#mmi-wb-en-notice")).toHaveText("1 picture proposal accepted: written in the next batch.");
  expect(api.callsTo("enrich/4002/image/choose").length).toBe(n + 1);
  expect(api.callsTo("enrich/4002/image/choose").at(-1)!.body).toEqual({ images: ["/wp-includes/images/w-logo-blue.png", "/wp-includes/images/wpicons.png"] });
});

test("a picture smaller than the standard is marked", async ({ page }) => {
  const { sec } = await open(page);
  await sec.locator('[data-kind="image"]').click();
  await expect(row(page, 4002).locator(".mmi-wb-en-pic-low")).toHaveText("small");
});
