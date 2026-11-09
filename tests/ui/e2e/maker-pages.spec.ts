/**
 * The Workbench's Maker pages section (Product pages) in a real browser, WebKit first, with the REST routes
 * mocked (maker-pages-mock.ts); the workspace's fresh build.
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
  const sec = page.locator("#mmi-wb-maker-pages");
  await expect(sec.locator("#mmi-wb-mp-rows tr[data-id]")).toHaveCount(5);
  return { api, errors, sec };
}
const row = (page: Page, id: number) => page.locator(`#mmi-wb-mp-rows tr[data-id="${id}"]`);

test("waiting products with their page now and the page proposed; counts per group", async ({ page }) => {
  const { sec, errors } = await open(page);
  await expect(sec.locator('[data-group="waiting"]')).toContainText("5");
  await expect(sec.locator('[data-group="open"]')).toContainText("2");
  await expect(sec.locator('[data-group="every"]')).toContainText("7");
  await expect(sec.locator('[data-conf="high"]')).toContainText("1");
  const r = row(page, 4001);
  await expect(r.locator(".mmi-wb-mp-proposed")).toContainText("https://maker.example/synth-1");
  await expect(r.locator(".mmi-wb-mp-proposed")).toContainText("Confidence 92");
  await expect(r.locator(".mmi-wb-mp-proposed")).toContainText("Would add: Images.");
  await expect(r.locator(".mmi-wb-mp-now")).toContainText("No maker page yet.");
  await expect(r.locator('[data-action]')).toHaveText(["Use proposed URL", "Not this page", "Set the page", "Maker doesn't publish it"]);
  await expect(row(page, 4005).locator(".mmi-wb-mp-warn")).toHaveText(/home page/);
  await sec.locator('[data-conf="high"]').click();
  await expect(sec.locator("#mmi-wb-mp-rows tr[data-id]")).toHaveCount(1);
  expect(errors).toEqual([]);
});

test("use a proposed page: decided in place, the row leaves the list, counts follow; Why? shows the evidence", async ({ page }) => {
  const { api, sec } = await open(page);
  await row(page, 4002).getByRole("button", { name: "Why?" }).click();
  await expect(row(page, 4002).locator(".mmi-wb-mp-evidence")).toContainText("Its title names it (+25)");
  await expect(row(page, 4002).locator(".mmi-wb-mp-evidence")).toContainText("Confidence 60");
  await row(page, 4001).getByRole("button", { name: "Use proposed URL" }).click();
  await expect(row(page, 4001)).toHaveClass(/mmi-wb-mp-gone/);
  await expect(row(page, 4001).locator(".notice")).toHaveText("Approved: now its maker page; reading it. It leaves this list.");
  await expect(row(page, 4001).locator("[data-action]")).toHaveCount(0);
  expect(api.callsTo("urls/decide")[0]!.body).toEqual({ action: "approve", ids: [4001] });
  await expect(sec.locator('[data-group="open"]')).toContainText("1");
  await expect(sec.locator('[data-group="approved"]')).toContainText("2");
});

test("a moved page, a home page, a product with none: new address, keep, set with checks", async ({ page }) => {
  const { api } = await open(page);
  await expect(row(page, 4003).locator("[data-action]").first()).toHaveText("Use its new address");
  await row(page, 4003).getByRole("button", { name: "Use its new address" }).click();
  await expect(row(page, 4003).locator(".notice")).toContainText("Saved its new address");
  expect(api.callsTo("urls/4003/set")[0]!.body).toEqual({ url: "https://www.maker.example/drum-1" });

  await row(page, 4005).getByRole("button", { name: "Keep this page" }).click();
  await expect(row(page, 4005).locator(".notice")).toHaveText("Kept: this is its page. It leaves this list.");
  expect(api.callsTo("page-list/4005/keep")).toHaveLength(1);

  await row(page, 4004).getByRole("button", { name: "Set the page" }).click();
  const input = row(page, 4004).getByRole("combobox");
  await input.fill("maker.example/cable");
  await row(page, 4004).getByRole("button", { name: "Save" }).click();
  await expect(row(page, 4004).getByRole("alert")).toHaveText("That isn't a web address.");
  expect(api.callsTo("urls/4004/set")).toHaveLength(0);
  await input.fill("https://maker.example/cable-10");
  await input.press("Enter");
  await expect(row(page, 4004).locator(".notice")).toContainText("Saved: now its maker page");
  expect(api.callsTo("urls/4004/set")[0]!.body).toEqual({ url: "https://maker.example/cable-10" });
});

test("Maker doesn't publish it asks for a second click, and Look again undoes it", async ({ page }) => {
  const { api, sec } = await open(page);
  const btn = row(page, 4004).getByRole("button", { name: "Maker doesn't publish it" });
  await btn.click();
  expect(api.callsTo("urls/4004/none")).toHaveLength(0);
  await row(page, 4004).getByRole("button", { name: "Click again: the maker doesn't publish it" }).click();
  await expect(row(page, 4004).locator(".notice")).toContainText("Marked: the maker doesn't publish a page for it.");
  expect(api.callsTo("urls/4004/none")).toHaveLength(1);
  await sec.locator('[data-group="every"]').click();
  await expect(row(page, 4004).locator(".mmi-wb-mp-now")).toContainText("The maker doesn't publish a page for it.");
  await row(page, 4004).getByRole("button", { name: "Look for its page again" }).click();
  await expect(row(page, 4004).locator(".notice")).toContainText("The finder will look for its page again.");
  expect(api.callsTo("urls/4004/look")).toHaveLength(1);
});

test("bulk: Use proposed URL never replaces a verified page; Read again queues the ticked", async ({ page }) => {
  const { api } = await open(page);
  await row(page, 4001).getByRole("checkbox").check();
  await row(page, 4002).getByRole("checkbox").check();
  await row(page, 4003).getByRole("checkbox").check();
  await expect(page.locator("#mmi-wb-mp-use-ticked")).toHaveText("Use proposed URL (2)");
  await page.locator("#mmi-wb-mp-use-ticked").click();
  await expect(page.locator("#mmi-wb-mp-notice")).toHaveText("1 page approved and being read. 1 left as they are: they have a different verified page, so decide those one at a time.");
  expect(api.callsTo("urls/decide")[0]!.body).toEqual({ action: "approve", ids: [4001, 4002] });
  await expect(page.locator("#mmi-wb-mp-rows tr[data-id]")).toHaveCount(4);
  await row(page, 4003).getByRole("checkbox").check();
  await page.locator("#mmi-wb-mp-read-ticked").click();
  await expect(page.locator("#mmi-wb-mp-notice")).toHaveText("1 page queued to read again.");
  expect(api.callsTo("pages/queue")[0]!.body).toEqual({ ids: [4003], mode: "read" });
});

test("Stored copy opens the page's stored copy with the REST nonce", async ({ page, context }) => {
  await context.route("**/wp-admin/admin-ajax.php?action=mmpt_url_page*", (r) => r.fulfill({ body: "<html><body>stored copy</body></html>", contentType: "text/html" }));
  await open(page);
  const [popup] = await Promise.all([page.waitForEvent("popup"), row(page, 4001).locator(".mmi-wb-mp-proposed").getByRole("button", { name: "Stored copy" }).click()]);
  await expect.poll(() => popup.url()).toContain("action=mmpt_url_page");
  const u = new URL(popup.url());
  expect([u.searchParams.get("id"), u.searchParams.get("which"), u.searchParams.get("_wpnonce")]).toEqual(["4001", "proposed", "rn-1"]);
});

test("evidence refused while the server is busy says so and asks again", async ({ page }) => {
  const { api } = await open(page);
  api.makerPages.busyOnce = true;
  await row(page, 4001).getByRole("button", { name: "Why?" }).click();
  await expect(row(page, 4001).locator(".mmi-wb-mp-evidence")).toContainText("The server is busy right now.");
  await row(page, 4001).getByRole("button", { name: "Try again" }).click();
  await expect(row(page, 4001).locator(".mmi-wb-mp-notes li").first()).toHaveText(/Its title names it/);
  expect(api.callsTo("urls/evidence")).toHaveLength(2);
});

test("the address editor offers the maker site's pages and the one that names the product best", async ({ page }) => {
  const { api } = await open(page);
  await row(page, 4004).getByRole("button", { name: "Set the page" }).click();
  await expect(row(page, 4004).locator(".mmi-wb-mp-pick")).toContainText("Reading acme.example's product list (page 2)");
  await expect(row(page, 4004).locator(".mmi-wb-mp-best")).toContainText("Names it best: Cable 10 ft (88%)", { timeout: 10_000 });
  await expect(row(page, 4004).locator(".mmi-wb-mp-pick")).toContainText("Type to pick from the 3 pages on acme.example");
  await expect(row(page, 4004).locator("datalist option")).toHaveCount(3);
  await row(page, 4004).getByRole("button", { name: "Use this page" }).click();
  await expect(row(page, 4004).getByRole("combobox")).toHaveValue("https://acme.example/cable-10");
  await row(page, 4004).getByRole("button", { name: "Save" }).click();
  await expect(row(page, 4004).locator(".notice")).toContainText("Saved: now its maker page");
  expect(api.callsTo("urls/4004/set")[0]!.body).toEqual({ url: "https://acme.example/cable-10" });
});
