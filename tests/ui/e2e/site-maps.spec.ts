/**
 * The Workbench's Site maps section in a real browser, WebKit first, with the REST routes mocked
 * (maker-pages-mock.ts); the workspace's fresh build. The site list stays on the left and the open site beside it
 * (owner 2026-11-14), its parts one row each with their proposals; Map parts is a pill of Needs a decision.
 */
import { expect, test, type Page } from "@playwright/test";
import { MockBackend } from "./mock-backend";
import { openSection } from "./shell";
import { TitleRulesMock } from "./title-rules-mock";

async function open(page: Page, key = "maps") {
  const errors: string[] = [];
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("console", (m) => { if (m.type() === "error" && !/status of 40[034]/.test(m.text())) errors.push(m.text()); });
  page.on("dialog", (d) => { errors.push(`dialog: ${d.message()}`); void d.dismiss(); }); // decisions are asked in place, never in a browser dialog
  await page.route("**/mmi-data-pipeline/build/workbench.js*", (r) => r.fulfill({ path: "build/workbench.js", contentType: "text/javascript" }));
  await new MockBackend().attach(page);
  const api = new TitleRulesMock();
  await api.attach(page);
  await page.goto("/");
  await openSection(page, key);
  const sec = page.locator("#mmi-wb-site-maps");
  if (key === "maps") await expect(sec.locator("#mmi-wb-sm-rows tr[data-host]")).toHaveCount(1);
  return { api, errors, sec };
}
const panel = (page: Page) => page.locator("#mmi-wb-sm-panel");
const openSite = async (page: Page, host: string) => {
  await page.locator(`#mmi-wb-sm-rows [data-host="${host}"] button`).click();
  await expect(panel(page)).toHaveAttribute("data-host", host);
};
const tab = (page: Page, key: string) => page.locator(`#mmi-wb-sm-panel [data-stab="${key}"]`).click();
const part = (page: Page, field: string) => page.locator(`#mmi-wb-sm-parts tr[data-part="${field}"]`);

test("the site list stays beside the open site: work pills, map status, arrow keys", async ({ page }) => {
  const { sec, errors } = await open(page);
  await expect(sec.locator("#mmi-wb-sm-empty")).toContainText("Choose a site on the left");
  const acme = sec.locator('#mmi-wb-sm-rows [data-host="acme.example"]');
  await expect(acme).toContainText("Draft map");
  await expect(acme).toContainText("2 parts to approve");
  await expect(acme.locator(".mmi-wb-sm-gapcol")).toHaveText("3");
  await expect(acme.locator(".mmi-wb-sm-gapcol")).toHaveAttribute("title", "3 gaps on 2 products");
  await sec.locator("#mmi-wb-sm-status").selectOption("all");
  await expect(sec.locator("#mmi-wb-sm-rows tr[data-host]")).toHaveCount(3);
  await expect(sec.locator('#mmi-wb-sm-rows [data-host="wall.example"]')).toContainText("2 refused");
  await expect(sec.locator('#mmi-wb-sm-status option[value="refused"]')).toHaveText("Refuses the server (1)");

  // The list and the open site sit side by side; ↓ moves to the next site.
  await openSite(page, "acme.example");
  const [list, work] = await Promise.all([sec.locator("#mmi-wb-sm-rail").boundingBox(), sec.locator(".mmi-wb-sm-work").boundingBox()]);
  expect(work!.x).toBeGreaterThan(list!.x + list!.width - 1);
  expect(new URL(page.url()).searchParams.get("wb_site")).toBe("acme.example");
  await page.keyboard.press("ArrowDown");
  await expect(panel(page)).toHaveAttribute("data-host", "fine.example");
  await expect(sec.locator('#mmi-wb-sm-rows [data-host="fine.example"] button')).toBeFocused();
  expect(errors).toEqual([]);
});

test("Parts: one row per part with its bar, its proposals under it, and its products", async ({ page }) => {
  const { sec } = await open(page);
  await openSite(page, "acme.example");
  const p = panel(page);
  await expect(p.locator('[data-stab="parts"]')).toContainText("2 to approve");
  await expect(p.locator("#mmi-wb-sm-parts-hint")).toContainText("2 proposed parts wait under Parts");
  await expect(part(page, "images")).toContainText("Page adds 2 · Being checked 1");
  await expect(part(page, "images")).toContainText("1 proposed");
  await expect(part(page, "manual")).toContainText("Page adds 1");
  // The proposal sits under its part: Pictures' under Pictures.
  await expect(p.locator('tr.mmi-wb-sm-prop-row [data-proposal="27"]')).toContainText("Whole site · found on 3 of 3 pages");
  await expect(p.locator('[data-proposal="27"]')).toContainText("Reviewed: Checked: the product's banner picture.");
  await expect(p.locator('[data-proposal="27"] img')).toHaveCount(1);
  const order = await p.locator("#mmi-wb-sm-parts tbody tr").evaluateAll((trs) => trs.map((t) => t.getAttribute("data-part") ?? t.querySelector("[data-proposal]")?.getAttribute("data-proposal")));
  expect(order.indexOf("27")).toBe(order.indexOf("images") + 1);

  // A part's name opens its products, a slice at a time.
  await part(page, "images").getByRole("button", { name: /Pictures/ }).click();
  const drill = p.locator("#mmi-wb-sm-part-images");
  await expect(drill.locator("tbody tr")).toHaveCount(3);
  await expect(drill.locator('tr[data-id="4001"] td[data-code="A"]')).toContainText("Page adds");
  await p.locator('#mmi-wb-sm-drill-images button[data-slice="check"]').click();
  await expect(drill.locator("tbody tr")).toHaveCount(1);
  await expect(drill.locator('tr[data-id="4003"]')).toBeVisible();

  // Finding a product narrows every bar to it.
  await p.locator("#mmi-wb-sm-pq").fill("Synth 2");
  await expect(part(page, "images")).toContainText("Page adds 1");
  await expect(part(page, "images")).not.toContainText("Being checked");
  await expect(sec.locator("#mmi-wb-sm-parts > thead")).toContainText("Across 1 product");
});

test("proposed parts: reject one, approve the rest", async ({ page }) => {
  const { api } = await open(page);
  await expect(page.locator('#mmi-wb-site-maps [data-sview="proposals"]')).toContainText("1");
  await page.locator('#mmi-wb-site-maps [data-sview="proposals"]').click();
  await expect(page.locator("#mmi-wb-sm-rows tr[data-host]")).toHaveCount(1);
  await openSite(page, "acme.example");
  const p = panel(page);
  await expect(p.locator(".mmi-wb-sm-proposal")).toHaveCount(2);
  await p.locator('[data-proposal="28"]').getByRole("button", { name: "Reject" }).click();
  await expect(p.locator("#mmi-wb-sm-notice")).toHaveText("Manual: rejected.");
  expect(api.callsTo("map-proposals/28")[0]!.body).toEqual({ decision: "reject" });
  await expect(p.locator(".mmi-wb-sm-proposal")).toHaveCount(1);
  await p.locator("#mmi-wb-sm-approve-all").click();
  await expect(p.locator("#mmi-wb-sm-notice")).toHaveText("1 part approved.");
  expect(api.callsTo("map-proposals/site")[0]!.body).toEqual({ host: "acme.example" });
  await expect(p.locator(".mmi-wb-sm-proposal")).toHaveCount(0);
  await expect(p.locator("#mmi-wb-sm-parts-hint")).toHaveCount(0);
});

test("Pages: the site's pages and reading the unread ones", async ({ page }) => {
  const { api } = await open(page);
  await openSite(page, "acme.example");
  const p = panel(page);
  await expect(p.locator('[data-stab="pages"] .mmi-wb-sm-dot')).toHaveCount(1);
  await tab(page, "pages");
  await expect(p.locator("#mmi-wb-sm-ready")).toContainText("2 readable");
  await p.getByRole("button", { name: "Read them" }).click();
  await expect(p.locator("#mmi-wb-sm-notice")).toHaveText("1 page queued to read.");
  expect(api.callsTo("pages/queue")[0]!.body).toEqual({ ids: [4003], mode: "read" });
});

test("Fill: in the site's header, reviewed first, a product left out, then Last fills and Undo", async ({ page }) => {
  const { api, errors } = await open(page);
  await openSite(page, "acme.example");
  const p = panel(page);
  await expect(p.locator(".mmi-wb-sm-head #mmi-wb-sm-fill")).toHaveText("Confirm map & fill 3 gaps on 2 products");
  await p.locator("#mmi-wb-sm-fill").click();
  await expect(p.locator("#mmi-wb-sm-review li")).toHaveCount(2);
  await expect(p.locator('#mmi-wb-sm-review li[data-id="4001"]')).toContainText("gets Pictures, Manual");
  await p.locator('#mmi-wb-sm-review li[data-id="4002"] input').uncheck();
  await p.locator("#mmi-wb-sm-fill-go").click();
  await expect(p.locator("#mmi-wb-sm-notice")).toContainText("Map confirmed. 1 product queued to read and write; 1 queued for pictures.");
  expect(api.callsTo("maps/acme.example/fill")[0]!.body).toEqual({ fields: 3, skip: [4002] });
  // What the Fill now waits on is its own line under the header.
  await expect(p.locator("#mmi-wb-sm-why")).toHaveText("Nothing to fill: every gain the pages offer is written.");
  await tab(page, "fills");
  await expect(p.locator(".mmi-wb-sm-run")).toContainText("written: manual; pictures 2 added");
  // Undo asks in place (no browser dialog): Keep it backs out, the named button undoes.
  await p.locator(".mmi-wb-sm-run").getByRole("button", { name: "Undo", exact: true }).click();
  await p.locator(".mmi-wb-sm-run").getByRole("button", { name: "Keep it" }).click();
  expect(api.callsTo("maps/acme.example/fills/undo")).toHaveLength(0);
  await p.locator(".mmi-wb-sm-run").getByRole("button", { name: "Undo", exact: true }).click();
  await p.locator(".mmi-wb-sm-run").getByRole("button", { name: "Undo on 1 product" }).click();
  await expect(p.locator("#mmi-wb-sm-notice")).toHaveText("Undone on 1 product.");
  expect(api.callsTo("maps/acme.example/fills/undo")[0]!.body).toEqual({ run: "20261109230000-abcd" });
  expect(errors).toEqual([]);
});

test("a site's map switched off and back on; the tab stays as you move between sites", async ({ page }) => {
  const { api, sec } = await open(page);
  await sec.locator("#mmi-wb-sm-status").selectOption("all");
  await openSite(page, "acme.example");
  await tab(page, "settings");
  await openSite(page, "fine.example");
  const p = panel(page);
  await expect(p.locator('[data-stab="settings"]')).toHaveAttribute("aria-selected", "true");
  await p.getByRole("button", { name: "Switch the map off" }).click();
  await expect(p.locator("#mmi-wb-sm-notice")).toHaveText("Map switched off: the site is read without it.");
  await p.getByRole("button", { name: "Turn the map back on" }).click();
  await expect(p.locator("#mmi-wb-sm-notice")).toHaveText("The map is back as a draft.");
  expect(api.callsTo("maps/fine.example/off")).toHaveLength(1);
});

test("pictures in the Fill review: veto on one product, make one featured, undo, and veto a picture on every product", async ({ page }) => {
  const { api } = await open(page);
  await openSite(page, "acme.example");
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

test("cells decided in the part's products, text left out, and the site's settings", async ({ page }) => {
  const { api } = await open(page);
  await openSite(page, "acme.example");
  const p = panel(page);
  await part(page, "os").getByRole("button", { name: /Requirements/ }).click();
  const req = p.locator('#mmi-wb-sm-part-os tr[data-id="4001"]');
  await expect(req.locator('td[data-code="R"]')).toContainText("To review");
  await req.getByRole("button", { name: "Use the page's requirements" }).click();
  await expect(p.locator("#mmi-wb-sm-notice")).toHaveText("The page's requirements are written.");
  expect(api.callsTo("maps/acme.example/requirements")[0]!.body).toEqual({ id: 4001, choice: "use" });
  await expect(p.locator('#mmi-wb-sm-part-os tr[data-id="4001"] td[data-field="os"]')).toHaveAttribute("data-code", "H");

  await part(page, "description").getByRole("button", { name: /Description/ }).click();
  const desc = p.locator('#mmi-wb-sm-part-description tr[data-id="4002"]');
  await desc.getByRole("button", { name: "Its page has none of this" }).click();
  await expect(p.locator("#mmi-wb-sm-notice")).toHaveText("Closed: its page has none of this.");
  // A closed cell stays in the part's products, as its own slice, so it can be taken back.
  await expect(p.locator('#mmi-wb-sm-part-description tr[data-id="4002"] td[data-slice="none"]')).toContainText("Page has none");
  await expect(part(page, "description")).toContainText("Page has none 1");
  expect(api.callsTo("maps/acme.example/none")[0]!.body).toEqual({ id: 4002, field: "description", undo: false });
  await p.locator('#mmi-wb-sm-part-description tr[data-id="4002"]').getByRole("button", { name: 'Take back "its page has none"' }).click();
  await expect(p.locator("#mmi-wb-sm-notice")).toHaveText("Taken back: the gap is open again.");

  await tab(page, "text");
  await expect(p.locator("#mmi-wb-sm-skips")).toContainText("None yet.");
  await p.getByRole("button", { name: "Suggest lines" }).click();
  await p.locator(".mmi-wb-sm-suggest li", { hasText: "Add to cart" }).getByRole("button", { name: "Leave it out" }).click();
  await expect(p.locator("#mmi-wb-sm-skips")).toContainText("Add to cart");
  expect(api.callsTo("maps/acme.example/text-skip")[0]!.body).toEqual({ add: ["Add to cart"], remove: [] });

  await tab(page, "settings");
  await p.locator("#mmi-wb-sm-pattern").fill("/products/{slug}/");
  await p.locator(".mmi-wb-sm-settings").getByRole("checkbox").check();
  await p.locator(".mmi-wb-sm-settings").getByRole("button", { name: "Save" }).click();
  await expect(p.locator("#mmi-wb-sm-notice")).toHaveText("Settings saved.");
  expect(api.callsTo("maps/acme.example/settings")[0]!.body).toEqual({ pattern: "/products/{slug}/", render: true });
});

test("Needs a decision's Map parts pill: every site's waiting parts, decided in the row", async ({ page }) => {
  const { api, errors } = await open(page, "decide");
  const pill = page.locator('#mmi-wb-decide .mmi-wb-dc-pills [data-kind="maps"]');
  await expect(pill).toContainText("Map parts");
  await expect(pill.locator(".mmi-pill-count")).toHaveText("2");
  await pill.click();
  await expect(pill).toHaveAttribute("aria-pressed", "true");
  await expect(page.locator('#mmi-wb-decide .mmi-wb-dc-pills [data-kind="all"]')).toHaveAttribute("aria-pressed", "false");
  const q = page.locator("#mmi-wb-map-parts");
  await expect(q.locator("#mmi-wb-mq-table tbody tr[data-proposal]")).toHaveCount(2);
  expect(new URL(page.url()).searchParams.get("wb_kind")).toBe("maps");
  const row = q.locator('tr[data-proposal="27"]');
  await expect(row).toContainText("acme.example");
  await expect(row).toContainText("3 of 3 pages");
  // The site opens in Site maps in a new tab, so this list keeps its place.
  const link = row.getByRole("link", { name: /acme\.example/ });
  await expect(link).toHaveAttribute("target", "_blank");
  expect(new URL((await link.getAttribute("href"))!).searchParams.get("wb_site")).toBe("acme.example");
  await row.getByRole("button", { name: "Approve" }).click();
  await expect(q.locator("#mmi-wb-mq-notice")).toHaveText("acme.example · Pictures: approved as the site's part.");
  expect(api.callsTo("map-proposals/27")[0]!.body).toEqual({ decision: "approve" });
  await expect(q.locator("#mmi-wb-mq-table tbody tr[data-proposal]")).toHaveCount(1);
  await q.locator("#mmi-wb-mq-site").selectOption("acme.example");
  await q.locator("#mmi-wb-mq-site-all").click();
  await expect(q.locator("#mmi-wb-mq-notice")).toHaveText("acme.example: 1 part approved.");
  await expect(q).toContainText("Nothing here.");
  expect(errors).toEqual([]);
});

test("the open site's tables meet the Workbench table standard: headings sort, columns resize, nothing runs past its box", async ({ page }) => {
  await open(page);
  await openSite(page, "acme.example");
  await part(page, "images").getByRole("button", { name: /Pictures/ }).click();
  await expect(page.locator("#mmi-wb-sm-part-images tbody tr")).toHaveCount(3);
  for (const id of ["mmi-wb-sm-table", "mmi-wb-sm-parts", "mmi-wb-sm-part-images"]) {
    const t = page.locator(`#${id}`);
    await expect(t.locator("thead .mmi-resize-handle").first()).toBeAttached();
    const look = await t.evaluate((el: HTMLTableElement) => {
      const heads = Array.from(el.tHead!.rows[0]!.cells).filter((c) => c.getBoundingClientRect().width > 0);
      const box = el.closest(".mmi-table-scroll-wrapper") ?? el.parentElement!;
      return {
        pads: [...new Set(heads.map((h) => getComputedStyle(h).padding))],
        unsorted: heads.filter((h) => h.textContent!.trim() && !h.querySelector(".screen-reader-text") && !h.classList.contains("mmi-wb-job-sortable")).map((h) => h.textContent!.trim()),
        handles: el.tHead!.rows[0]!.querySelectorAll(".mmi-resize-handle").length,
        heads: heads.length,
        over: Math.round(el.scrollWidth - box.clientWidth),
      };
    });
    expect(look.pads, `${id} heading padding`).toEqual(["8px 10px"]);
    expect(look.unsorted, `${id} headings that don't sort`).toEqual([]);
    expect(look.handles, `${id} resize handles`).toBe(look.heads);
    expect(look.over, `${id} runs past its box`).toBeLessThanOrEqual(0);
  }
});
