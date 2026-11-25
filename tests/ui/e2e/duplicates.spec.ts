/**
 * Duplicates (Duplicates.tsx), a pill of Needs a decision since 2026-11-25: the two scans run when the pill opens,
 * overlapping groups fold into one row, the open group sits beside the list, and decisions that change stock wait for
 * a second click on the same button. The scans and decisions are admin-ajax actions answered here.
 */
import { expect, test, type Page, type Route } from "@playwright/test";
import { MockBackend } from "./mock-backend";
import { openSection } from "./shell";
import { TitleRulesMock } from "./title-rules-mock";

const member = (id: number, o: Record<string, unknown> = {}) => ({
  supplier: "xchange", sku: `S-${id}`, vendor: "Moog Music", dealer_price: 0, wc_product_id: id, is_canonical: false, is_recommended: false,
  product_name: `Listing ${id}`, stock_status: "instock", visibility: "visible", in_feed: true, held_by: "", list_price: 499, list_price_source: "map", store_price: 499, price_stale: false,
  assets: { title: `Listing ${id}`, has_image: false, gallery_count: 0, has_content: true, content_preview: `Text of ${id}`, content_words: 40, content_hash: id === 102 ? "b" : "a", has_excerpt: false },
  ...o,
});
const group = (key: string, source: string, members: unknown[], o: Record<string, unknown> = {}) => ({
  group_key: key, source, brand: "Moog", name: `Mother-32 (${key})`, members, price_min: 499, price_max: 499, price_spread: 0, price_count: members.length, price_stale: 0,
  price_match: true, already_linked: false, brand_thumbnail_url: "", image_url: "", ...o,
});

const CANDIDATES = [
  group("moog-mother-32", "candidate", [member(101, { dealer_price: 300, is_recommended: true }), member(102, { dealer_price: 320 })]),
];
const EXISTING = [
  // The same two listings by part number: the feed match folds into this row (same size, stronger evidence).
  group("mpn-moog-1", "mpn", [member(101, { dealer_price: 300, is_recommended: true }), member(102, { dealer_price: 320 })], { match: "MOOG-32" }),
  group("legacy-201", "legacy", [member(201, { is_canonical: true, stock_status: "outofstock" }), member(202, { stock_status: "outofstock" })],
    { brand: "Acme", name: "Pro Tools Studio", already_linked: true, canonical_product_id: 201, nothing_on_sale: true, sale_candidate_id: 202 }),
];

async function open(page: Page, query = "", drawer: TitleRulesMock["duplicatesOf"] = {}) {
  const errors: string[] = [];
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("console", (m) => { if (m.type() === "error" && !/status of 40[0349]/.test(m.text())) errors.push(m.text()); });
  await page.route("**/mmi-data-pipeline/build/workbench.js*", (r) => r.fulfill({ path: "build/workbench.js", contentType: "text/javascript" }));
  const backend = new MockBackend();
  await backend.attach(page);
  const api = new TitleRulesMock();
  api.waitingOn = true;
  api.duplicatesOf = drawer;
  await api.attach(page);
  const calls: { action: string; body: URLSearchParams }[] = [];
  let existing = structuredClone(EXISTING);
  await page.route("**/wp-admin/admin-ajax.php", (route: Route) => {
    const body = new URLSearchParams(route.request().postData() ?? "");
    const action = body.get("action") ?? "";
    const reply = (data: unknown) => route.fulfill({ contentType: "application/json", body: JSON.stringify({ success: true, data }) });
    if (!action.startsWith("mmi_scan_") && !action.startsWith("mmi_canonical_") && !action.includes("duplicate")) return route.fallback();
    calls.push({ action, body });
    switch (action) {
      case "mmi_scan_duplicate_candidates": return reply({ groups: CANDIDATES, total: CANDIDATES.length });
      case "mmi_scan_existing_canonical_groups": return reply({ groups: existing, total: existing.length });
      case "mmi_scan_pk_collisions": return reply({ groups: [{ supplier: "xchange", value: "123-45", canonical_id: 0, resolved: false, recommended_id: 301,
        members: [301, 302].map((id) => ({ product_id: id, product_name: `Collider ${id}`, product_url: "#", sku: `C${id}`, regular_price: "10", stock_status: "instock", post_status: "publish", is_recommended: id === 301, reverb_listing_id: "", reverb_sync: id === 302 })) }] });
      case "mmi_approve_duplicate_group": return reply({ message: "Linked 1 product(s) to the canonical listing.", fields_applied: [] });
      case "mmi_dismiss_duplicate_group": return reply({ message: "Dismissed" });
      case "mmi_canonical_reassign_canonical": {
        const g = structuredClone(existing[1]!) as Record<string, unknown> & { members: Record<string, unknown>[] };
        g.group_key = "legacy-202"; g.canonical_product_id = 202; g.nothing_on_sale = false;
        g.members = g.members.map((m) => ({ ...m, is_canonical: m.wc_product_id === 202, stock_status: m.wc_product_id === 202 ? "instock" : "outofstock" }));
        existing = [existing[0]!, g as never];
        return reply({ message: "#202 is now the canonical listing.", group: g, old_group_key: "legacy-201" });
      }
      default: return route.fallback();
    }
  });
  await page.goto(`/${query}`);
  return { calls, errors };
}

const list = (page: Page) => page.locator("#mmi-wb-du-rows tr[data-key]");
const chip = (page: Page, c: string) => page.locator(`#mmi-wb-duplicates [data-chip="${c}"]`);

test("opens on the pill: both scans at once, overlapping groups folded into one row, the open group beside the list", async ({ page }) => {
  const { calls, errors } = await open(page);
  await expect(page.locator('#mmi-wb-decide [data-kind="duplicates"] .mmi-pill-count')).toHaveText("3"); // the last scan's count
  expect(calls.filter((c) => c.action.startsWith("mmi_scan_"))).toHaveLength(0); // nothing scans until the pill opens
  await openSection(page, "duplicates");
  await expect(list(page)).toHaveCount(1); // New: the MPN row holds the feed match's listings
  expect(calls.filter((c) => c.action.startsWith("mmi_scan_")).map((c) => c.action).sort()).toEqual(["mmi_scan_duplicate_candidates", "mmi_scan_existing_canonical_groups"]);
  // The scans' nonce is Duplicates' own (mmi_pipeline_canonical_candidates), not the Workbench's.
  expect(calls[0]!.body.get("nonce")).toBeTruthy();
  await expect(list(page).first()).toHaveAttribute("data-key", "mpn-moog-1");
  await expect(list(page).first().locator(".mmi-badge")).toContainText(["Same MPN", "+ Fresh match", "In stock"]);
  await expect(chip(page, "new").locator(".mmi-pill-count")).toHaveText("1");
  await expect(chip(page, "linked").locator(".mmi-pill-count")).toHaveText("1");
  await expect(chip(page, "no_sale").locator(".mmi-pill-count")).toHaveText("1");
  await list(page).first().click();
  const work = page.locator("#mmi-wb-du-work");
  await expect(work.locator(".mmi-wb-du-verdict-headline")).toHaveText("Same price on all 2 listings");
  await expect(work.locator(".mmi-wb-du-listing")).toHaveCount(2);
  await expect(work.locator('.mmi-wb-du-listing[data-product="101"]')).toHaveClass(/is-canonical/); // lowest cost preselected
  await expect(work.locator('.mmi-wb-du-field[data-field="content"] .mmi-wb-du-pick')).toHaveCount(2);
  expect(new URL(page.url()).searchParams.get("wb_dupes_group")).toBe("mpn-moog-1");
  expect(errors).toEqual([]);
});

test("Link selected asks for a second click, then links the ticked listings to the canonical", async ({ page }) => {
  const { calls } = await open(page, "?wb_sec=duplicates&wb_dupes_group=mpn-moog-1");
  const link = page.locator("#mmi-wb-du-link");
  await expect(link).toHaveText(/Link selected/);
  await link.click();
  await expect(link).toHaveText(/Click again: link 1 listing to #101/);
  expect(calls.filter((c) => c.action === "mmi_approve_duplicate_group")).toHaveLength(0);
  await link.click();
  await expect(page.locator("#mmi-wb-du-notice")).toContainText("Linked 1 product(s)");
  const sent = calls.find((c) => c.action === "mmi_approve_duplicate_group")!.body;
  expect(sent.get("canonical_product_id")).toBe("101");
  expect(sent.getAll("associated_product_ids[]")).toEqual(["102"]);
  expect(sent.get("merge_canonicals")).toBe("1"); // a same-MPN group folds a second canonical in
  expect(JSON.parse(sent.get("field_winners") ?? "{}")).toEqual({ content: 101, title: 101 });
  // Linked: the row leaves New.
  await expect(list(page)).toHaveCount(0);
  await expect(chip(page, "linked").locator(".mmi-pill-count")).toHaveText("2");
});

test("Not a duplicate dismisses every source's group the row holds", async ({ page }) => {
  const { calls } = await open(page, "?wb_sec=duplicates&wb_dupes_group=mpn-moog-1");
  await page.locator("#mmi-wb-du-dismiss").click();
  await page.locator("#mmi-wb-du-dismiss").click();
  await expect(list(page)).toHaveCount(0);
  expect(calls.find((c) => c.action === "mmi_dismiss_duplicate_group")!.body.getAll("group_keys[]").sort()).toEqual(["moog-mother-32", "mpn-moog-1"]);
});

test("a linked group with nothing on sale: Make canonical on the suggested listing replaces the row in place", async ({ page }) => {
  const { calls } = await open(page, "?wb_sec=duplicates&wb_dupes=no_sale");
  await expect(list(page)).toHaveCount(1);
  await list(page).first().click();
  const work = page.locator("#mmi-wb-du-work");
  await expect(work.locator(".mmi-wb-du-alert")).toContainText("Nothing in this group is on sale");
  const make = work.locator('[data-make-canonical="202"]');
  await expect(make).toHaveClass(/button-primary/); // the duplicate still in a feed
  await make.click();
  await make.click();
  await expect(page.locator("#mmi-wb-du-notice")).toContainText("#202 is now the canonical listing");
  expect(calls.find((c) => c.action === "mmi_canonical_reassign_canonical")!.body.get("new_canonical_id")).toBe("202");
  await chip(page, "linked").click();
  await expect(list(page)).toHaveCount(1);
  await expect(list(page).first()).toHaveAttribute("data-key", "legacy-202");
});

test("an old Import tab link's collision lands on the Collisions chip with the key open", async ({ page }) => {
  const { calls } = await open(page, "?wb_dupes=collisions&wb_dupes_group=xchange%3A123-45");
  await expect(page.locator('#mmi-wb-decide [data-kind="duplicates"]')).toHaveAttribute("aria-pressed", "true");
  await expect(chip(page, "collisions")).toHaveAttribute("aria-pressed", "true");
  await expect(page.locator('#mmi-wb-du-collisions tr[data-key="xchange:123-45"]')).toHaveClass(/is-open/);
  expect(calls.map((c) => c.action)).toEqual(["mmi_scan_pk_collisions"]); // the candidates' scans wait for their chips
  const keep = page.locator('#mmi-wb-du-work [data-keep="301"]');
  await keep.click();
  await expect(keep).toContainText("1 Reverb listing unpublished");
});

test("the product drawer names a product's group and opens it in Duplicates, folded into the row holding it", async ({ page }) => {
  const { errors } = await open(page, "?wb_product=2012", { 2012: { groups: [{ key: "moog-mother-32", source: "candidate", others: [102] }], linked: [] } });
  const part = page.locator('#mmi-wb-drawer [data-part="duplicates"]');
  await expect(part).toContainText("Listed with #102, waiting for a decision");
  await part.locator('[data-dupes-group="moog-mother-32"]').click();
  await expect(page.locator('#mmi-wb-decide [data-kind="duplicates"]')).toHaveAttribute("aria-pressed", "true");
  await expect(chip(page, "all")).toHaveAttribute("aria-pressed", "true");
  // The feed match folded into the same-MPN row: that row opens.
  await expect(page.locator('#mmi-wb-du-rows tr[data-key="mpn-moog-1"]')).toHaveClass(/is-open/);
  await expect(page.locator("#mmi-wb-du-work .mmi-wb-du-listing")).toHaveCount(2);
  expect(errors).toEqual([]);
});
