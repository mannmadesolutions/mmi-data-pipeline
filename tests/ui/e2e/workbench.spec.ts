/**
 * Product Workbench in a real browser: the React app, the shared jQuery
 * components it hosts (condition builder, change picker, pager, resizer,
 * nonce refresh) and the plugin's own CSS, with admin-ajax mocked.
 */
import { expect, test, type Page } from "@playwright/test";
import { MockBackend, type MockOptions } from "./mock-backend";

async function open(page: Page, o: MockOptions = {}) {
  const errors: string[] = [];
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("console", (m) => { if (m.type() === "error" && !/status of 40[03]/.test(m.text())) errors.push(m.text()); });
  page.on("dialog", (d) => d.accept());
  const backend = new MockBackend(o);
  await backend.attach(page);
  await page.goto("/");
  await expect(page.locator("#mmi-wb-find")).toBeVisible();
  // The page opens on every product (browsing) before any search (a logged-out page can't).
  if (!o.loggedOut) await expect(page.locator("#mmi-wb-count")).toHaveText("120 products found");
  return { backend, errors };
}

/** Type a search and wait for its results (not the ones already on screen). */
async function searchFor(page: Page, q: string) {
  const count = page.locator("#mmi-wb-count");
  const before = await count.textContent();
  await page.locator("#mmi-wb-q").fill(q);
  await expect(count).not.toHaveText(before ?? "");
  await expect(count).toContainText("found");
  await expect(page.locator("#mmi-wb-rows.mmi-wb-loading")).toHaveCount(0);
}

test("mounts, and hosts the shared jQuery components in place", async ({ page }) => {
  const { errors } = await open(page);
  await expect(page.locator("#mmi-wb-find #mmi-wb-cb")).toBeVisible();          // condition builder moved into Find
  await expect(page.locator("#mmi-wb-change #mmi-wb-action")).toBeVisible();     // change picker moved into Results
  await expect(page.locator(".mmi-wb-islands > *")).toHaveCount(0);
  await expect(page.locator("#mmi-wb-save-search")).toBeVisible();              // React portal inside the builder
  // Browsing: products shown, nothing to preview, and Apply to all waits for a search.
  await expect(page.locator("#mmi-wb-target")).toContainText("Showing every product");
  await expect(page.locator("#mmi-wb-preview")).toBeDisabled();
  await expect(page.locator("#mmi-wb-apply-all")).toBeDisabled();
  expect(errors).toEqual([]);
});

test("searching waits for typing to pause, then sends one request", async ({ page }) => {
  const { backend } = await open(page);
  const opening = backend.callsTo("mmi_workbench_search").length; // the browse-all search
  await page.locator("#mmi-wb-q").pressSequentially("synth", { delay: 60 });
  await expect(page.locator("#mmi-wb-count")).toHaveText("40 products found");
  expect(backend.callsTo("mmi_workbench_search")).toHaveLength(opening + 1);
  expect(backend.callsTo("mmi_workbench_search").at(-1)!.body.get("filter[q]")).toBe("synth");
  await expect(page.locator("#mmi-wb-rows tr[data-id]")).toHaveCount(40);
});

test("headings sort on the server and say which way", async ({ page }) => {
  const { backend } = await open(page);
  await searchFor(page, "synth");
  const id = page.locator("th[data-col='id']");
  await id.click();
  await expect(id).toHaveAttribute("aria-sort", "ascending");
  await id.click();
  await expect(id).toHaveAttribute("aria-sort", "descending");
  await expect(page.locator("#mmi-wb-rows tr[data-id]").first()).toHaveAttribute("data-id", "1118");
  const last = backend.callsTo("mmi_workbench_search").at(-1)!.body;
  expect([last.get("sort"), last.get("dir")]).toEqual(["id", "desc"]);
});

test("an unfinished condition holds the search and disables Apply", async ({ page }) => {
  const { backend } = await open(page);
  await searchFor(page, "synth");
  await page.locator("#mmi-wb-add-condition").click();
  await expect(page.locator("#mmi-wb-search-status")).toHaveText("Condition 1: pick a Source and a Field, or remove it.");
  await expect(page.locator("#mmi-wb-apply-all")).toBeDisabled();
  expect(backend.callsTo("mmi_workbench_search").filter((c) => c.body.get("filter[q]") === "synth")).toHaveLength(1);
});

test("a category chip adds a real condition row and narrows the search", async ({ page }) => {
  const { backend } = await open(page);
  await searchFor(page, "test");
  await page.locator("#mmi-wb-facets .mmi-ms-trigger").click();
  await page.locator(".mmi-wb-facet", { hasText: "Plug-ins" }).click();
  await expect(page.locator("#mmi-wb-facets .mmi-ms-menu")).toBeHidden(); // picking closes the menu
  await expect(page.locator("#mmi-wb-conditions .mmi-condition-row")).toHaveCount(1);
  await expect.poll(() => backend.callsTo("mmi_workbench_search").at(-1)?.body.get("filter[conditions][0][value]")).toBe("Plug-ins");
  await expect(page.locator("#mmi-wb-facets .mmi-ms-badge")).toHaveText("1"); // one category picked
  await page.locator("#mmi-wb-facets .mmi-ms-trigger").click();
  await expect(page.locator(".mmi-wb-facet", { hasText: "Plug-ins" })).toHaveClass(/is-active/);
});

test("select all matching, preview, then apply to all with progress and undo", async ({ page }) => {
  const { backend } = await open(page);
  await searchFor(page, "test");
  await page.locator("#mmi-wb-per-page").selectOption("25");
  await expect(page.locator("#mmi-wb-rows tr[data-id]")).toHaveCount(25);
  await page.getByLabel("Select this page").check();
  await page.locator("#mmi-wb-select-all-matching").click();
  await expect(page.locator("#mmi-wb-target")).toHaveText("All 80 products matching the search will be checked.");
  await page.locator("#mmi-wb-rows .mmi-wb-row-cb").first().uncheck();
  await expect(page.locator("#mmi-wb-target")).toHaveText("All 80 products matching the search will be checked, except 1 unticked.");

  await page.locator("#mmi-wb-preview").click();
  await expect(page.locator("#mmi-wb-preview-out")).toContainText("79 of 79 would change");

  await page.locator("#mmi-wb-apply").click();
  await expect(page.locator("#mmi-wb-message")).toHaveText("Done: 79 changed, 0 already matched.");
  expect(backend.callsTo("mmi_workbench_run")).toHaveLength(2); // 50 + 29
  const start = backend.callsTo("mmi_workbench_start")[0]!.body;
  expect(start.get("selection[mode]")).toBe("filter");      // the search, not 79 ids
  expect(start.getAll("selection[exclude][]")).toHaveLength(1);
  await expect(page.locator("#mmi-wb-selected")).toHaveText(""); // selection cleared after a finished run

  const undo = page.locator("#mmi-wb-jobs-rows .mmi-wb-job-undo");
  await expect(undo).toBeVisible();
  await undo.click();
  await expect(page.locator("#mmi-wb-message")).toHaveText("Undone: 79 restored.");
  await expect(page.locator("#mmi-wb-jobs-rows")).toContainText("Undone");
});

test("Stop ends a run where it is and it can be resumed", async ({ page }) => {
  await open(page, { busyBatches: 2 });
  await searchFor(page, "test");
  await page.locator("#mmi-wb-apply-all").click();
  await expect(page.locator("#mmi-wb-progress")).toBeVisible();
  await page.locator(".mmi-wb-stop").click();
  await expect(page.locator("#mmi-wb-message")).toHaveText(/^Stopped after \d+ of 80\. Resume it from Recent changes\.$/);
  await expect(page.locator("#mmi-wb-jobs-rows .mmi-wb-job-resume")).toBeVisible();
  await page.locator("#mmi-wb-jobs-rows .mmi-wb-job-resume").click();
  await expect(page.locator("#mmi-wb-message")).toHaveText(/^Done: 80 changed/);
});

test("edit a product's categories in the row", async ({ page }) => {
  const { backend } = await open(page);
  await searchFor(page, "test plug-in 2");
  const row = page.locator("#mmi-wb-rows tr[data-id='1002']");
  await row.locator(".mmi-wb-col-cats .mmi-wb-edit-terms").click();
  const editor = page.locator(".mmi-wb-term-editor");
  await expect(editor.locator(".mmi-wb-term-option")).toHaveCount(4);
  await editor.locator(".mmi-wb-term-search").fill("bund");
  await expect(editor.locator(".mmi-wb-term-option:not(.mmi-hidden)")).toHaveCount(1);
  await editor.locator(".mmi-wb-term-option", { hasText: "Bundles" }).locator("input").check();
  await editor.locator(".mmi-wb-term-save").click();
  await expect(page.locator(".mmi-wb-row-notice")).toHaveText("Categories saved. Undo it from Recent changes.");
  await expect(row.locator(".mmi-wb-col-cats")).toContainText("Bundles");
  await expect(row.locator(".mmi-wb-term-lock")).toBeVisible();
  expect(backend.callsTo("mmi_workbench_set_terms")[0]!.body.getAll("term_ids[]").sort()).toEqual(["11", "12"]);
});

test("a stale nonce is refreshed and the request resent, unseen", async ({ page }) => {
  const { backend } = await open(page, { staleNonce: 1 });
  await searchFor(page, "synth");
  // The page opens with four Workbench calls (Recent changes, Standing rules, Catalog upkeep, the browse-all search);
  // the one that met the stale nonce was refreshed once and resent, and every later call uses the new one.
  expect(backend.callsTo("mmi_refresh_nonces")).toHaveLength(1);
  const opening = ["mmi_workbench_jobs", "mmi_workbench_rules", "mmi_workbench_upkeep", "mmi_workbench_search"].flatMap((a) => backend.callsTo(a));
  expect(opening.filter((c) => c.body.get("nonce") === "nonce-2").length).toBeGreaterThanOrEqual(1);
  expect(backend.callsTo("mmi_workbench_search").at(-1)!.body.get("nonce")).toBe("nonce-2");
  await expect(page.locator(".mmi-cb-notice")).not.toContainText("expired");
});

test("an ended WordPress session says so instead of a network error", async ({ page }) => {
  await open(page, { loggedOut: true });
  await page.locator("#mmi-wb-q").fill("synth");
  await expect(page.locator(".mmi-cb-notice")).toContainText("Your login has expired");
});

test("a health chip narrows to products failing that check", async ({ page }) => {
  const { backend } = await open(page);
  await searchFor(page, "synth");
  await page.locator(".mmi-health-chip").first().click();
  await expect.poll(() => backend.callsTo("mmi_workbench_search").at(-1)?.body.getAll("filter[health][]")).toEqual(["no_image"]);
  await expect(page.locator("#mmi-wb-health-ms .mmi-ms-label")).toHaveText("Health: No featured image");
});

test("changing what to change clears the preview and shows only relevant lock switches", async ({ page }) => {
  await open(page);
  await searchFor(page, "synth");
  await page.locator("#mmi-wb-preview").click();
  await expect(page.locator("#mmi-wb-preview-out")).toBeVisible();
  await expect(page.locator(".mmi-wb-lock-option")).toHaveCount(1);     // a taxonomy change always locks: Skip only
  await page.locator("#mmi-wb-action").selectOption("set_status_draft");
  await expect(page.locator("#mmi-wb-preview-out")).toHaveCount(0);
  await expect(page.locator("#mmi-wb-lock-options")).toHaveCount(0);   // status isn't an import-locked field
});

test("Clear all resets the search, filters and conditions", async ({ page }) => {
  await open(page);
  await searchFor(page, "synth");
  await page.locator("#mmi-wb-facets .mmi-ms-trigger").click();
  await page.locator(".mmi-wb-facet").first().click();
  await expect(page.locator("#mmi-wb-conditions .mmi-condition-row")).toHaveCount(1);
  await page.locator(".mmi-wb-filters-clear").click();
  await expect(page.locator("#mmi-wb-q")).toHaveValue("");
  await expect(page.locator("#mmi-wb-conditions .mmi-condition-row")).toHaveCount(0);
  await expect(page.locator("#mmi-wb-count")).toHaveText("120 products found"); // back to every product
});

test("a column-resize drag never sorts (shared guard)", async ({ page }) => {
  const { backend } = await open(page);
  await searchFor(page, "synth");
  const before = backend.callsTo("mmi_workbench_search").length;
  const handle = page.locator("th[data-col='title'] .mmi-column-resizer, th[data-col='title'] [class*='resize']").first();
  await expect(handle).toBeAttached();
  const box = (await handle.boundingBox())!;
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
  await page.mouse.down();
  await page.mouse.move(box.x + 60, box.y + box.height / 2, { steps: 5 });
  await page.mouse.up();
  await page.waitForTimeout(300);
  expect(backend.callsTo("mmi_workbench_search").length).toBe(before);
});

/* ── Standing rules and catalog upkeep ──────────────────────────────────── */

test("Force In/Out of Stock is offered only for Keep applying", async ({ page }) => {
  await open(page);
  const force = page.locator('#mmi-wb-action option[value="force_outofstock"]');
  await expect(force).toBeDisabled();
  await page.locator("#mmi-wb-lifetime-keep").click();
  await expect(force).toBeEnabled();
  await expect(page.locator("#mmi-wb-rule-save")).toBeVisible();
  await expect(page.locator("#mmi-wb-apply")).toHaveCount(0);
  await page.locator("#mmi-wb-lifetime-once").click();
  await expect(force).toBeDisabled();
});

test("Keep applying checks the rule first, and saves after Save anyway", async ({ page }) => {
  const { backend, errors } = await open(page);
  await page.locator("#mmi-wb-lifetime-keep").click();
  await searchFor(page, "synth");
  await page.locator("#mmi-wb-action").selectOption("force_outofstock");
  await page.locator("#mmi-wb-rule-name").fill("Synths out");
  await page.locator("#mmi-wb-rule-save").click();
  // The check found a problem: nothing saved yet, the finding and Save anyway shown in place.
  await expect(page.locator("#mmi-wb-rule-check")).toContainText("Feed sync puts 12");
  expect(backend.callsTo("mmi_workbench_rule_save")).toHaveLength(0);
  await page.locator("#mmi-wb-rule-save-anyway").click();
  await expect(page.locator("#mmi-wb-message")).toContainText("Saved the standing rule “Synths out”");
  const row = page.locator("#mmi-wb-rules-rows tr", { hasText: "Synths out" });
  await expect(row).toBeVisible();
  await expect(row).toContainText("Works against itself");
  await expect(page.locator("#mmi-wb-editing")).toContainText("Synths out");
  const saved = backend.callsTo("mmi_workbench_rule_save")[0]!.body;
  expect(saved.get("wb_action")).toBe("force_outofstock");
  expect(saved.get("filter[q]")).toBe("synth");
  expect(errors).toEqual([]);
});

test("a rule that checks out saves straight away; Open loads it to edit, and saving updates it", async ({ page }) => {
  const { backend } = await open(page);
  await page.locator("#mmi-wb-lifetime-keep").click();
  await searchFor(page, "bundle");
  await page.locator("#mmi-wb-rule-name").fill("Bundles");
  await page.locator("#mmi-wb-rule-save").click();
  await expect(page.locator("#mmi-wb-rules-rows tr", { hasText: "Bundles" })).toBeVisible();
  await page.locator("#mmi-wb-editing button").click(); // stop editing
  await page.locator("#mmi-wb-lifetime-once").click();
  await page.locator("#mmi-wb-q").fill("");

  await page.locator("#mmi-wb-rules-rows tr", { hasText: "Bundles" }).getByRole("button", { name: "Open" }).click();
  await expect(page.locator("#mmi-wb-q")).toHaveValue("bundle");
  await expect(page.locator("#mmi-wb-lifetime-keep")).toHaveAttribute("aria-pressed", "true");
  await expect(page.locator("#mmi-wb-rule-name")).toHaveValue("Bundles");
  await page.locator("#mmi-wb-rule-save").click();
  await expect(page.locator("#mmi-wb-message")).toContainText("Updated the standing rule");
  const saves = backend.callsTo("mmi_workbench_rule_save");
  expect(saves).toHaveLength(2);
  expect(saves[1]!.body.get("rule_id")).toBe(saves[0]!.body.get("rule_id") || "r000000000001");
});

test("rule rows switch off, move, run and delete in place", async ({ page }) => {
  const { backend } = await open(page);
  backend.rules = [
    { id: "r000000000001", name: "First", enabled: true, action: "set_status_draft", params: {}, stock: false, change: "Set to Draft",
      search: "Title contains \"old\"", filter: { stock: [], health: [], health_mode: "any", q: "old", q_case: 0, q_in: "title_sku", supplier: "all", match_logic: "all", statuses: ["publish"], conditions: [] },
      efficacy: { grade: "ok", matched: 3, will_change: 3, findings: [] }, last_run: { at: 0, matched: 3, changed: 1, deferred: 0, ms: 10 } },
    { id: "r000000000002", name: "Second", enabled: true, action: "force_outofstock", params: {}, stock: true, change: "Force Out of Stock",
      search: "Brand is \"Acme\"", filter: { stock: [], health: [], health_mode: "any", q: "", q_case: 0, q_in: "title_sku", supplier: "all", match_logic: "all", statuses: ["publish"], conditions: [] },
      efficacy: null, last_run: null },
  ];
  await page.reload();
  const second = page.locator("#mmi-wb-rules-rows tr", { hasText: "Second" });
  await second.getByRole("button", { name: "Move Second earlier" }).click();
  await expect(page.locator("#mmi-wb-rules-rows tr").first()).toContainText("Second");
  await second.getByRole("checkbox").uncheck();
  await expect(second.locator(".mmi-wb-rule-notice")).toContainText("is off");
  await second.getByRole("checkbox").check();
  await second.getByRole("button", { name: "Run now" }).click();
  await expect(second.locator(".mmi-wb-rule-notice")).toContainText("40 of 40 changed");
  await page.locator("#mmi-wb-rules-rows tr", { hasText: "First" }).getByRole("button", { name: "Delete First" }).click();
  await expect(page.locator("#mmi-wb-rules-rows tr", { hasText: "First" })).toHaveCount(0);
  expect(backend.callsTo("mmi_workbench_rule_move")[0]!.body.get("by")).toBe("-1");
  expect(backend.callsTo("mmi_workbench_rule_hold")).toHaveLength(1);
});

test("a non-stock rule's Run now is an undoable Workbench job", async ({ page }) => {
  const { backend } = await open(page);
  backend.rules = [{ id: "r000000000001", name: "Drafts", enabled: true, action: "set_status_draft", params: {}, stock: false, change: "Set to Draft",
    search: "Title contains \"synth\"", filter: { stock: [], health: [], health_mode: "any", q: "synth", q_case: 0, q_in: "title_sku", supplier: "all", match_logic: "all", statuses: ["publish", "draft"], conditions: [] },
    efficacy: { grade: "ok", matched: 40, will_change: 40, findings: [] }, last_run: null }];
  await page.reload();
  await page.locator("#mmi-wb-rules-rows tr", { hasText: "Drafts" }).getByRole("button", { name: "Run now" }).click();
  await expect(page.locator("#mmi-wb-message")).toContainText("Done:");
  const start = backend.callsTo("mmi_workbench_start")[0]!.body;
  expect(start.get("selection[mode]")).toBe("filter");
  expect(start.get("selection[filter][q]")).toBe("synth");
  expect(start.get("wb_action")).toBe("set_status_draft");
  await expect(page.locator("#mmi-wb-jobs-rows .mmi-wb-job-undo")).toBeVisible();
});

test("catalog upkeep runs every step in order and saves its switches and schedule", async ({ page }) => {
  const { backend, errors } = await open(page);
  const up = page.locator("#mmi-wb-upkeep");
  await expect(up.locator("#mmi-wb-upkeep-steps tbody tr")).toHaveCount(3);
  await up.locator("#mmi-wb-upkeep-steps").getByRole("checkbox").uncheck();
  await expect.poll(() => backend.callsTo("mmi_save_catalog_rules").length).toBe(1);
  expect(backend.callsTo("mmi_save_catalog_rules")[0]!.body.get("toggles[cleanup_stock_meta]")).toBe("");
  await up.locator("#mmi-wb-upkeep-frequency").selectOption("daily");
  await expect(up.locator("#mmi-wb-upkeep-message")).toContainText("Schedule saved");
  await up.locator("#mmi-wb-upkeep-run").click();
  await expect(up.locator("#mmi-wb-upkeep-message")).toContainText("6,553 products processed");
  expect(backend.callsTo("mmi_catalog_run_phase").map((c) => c.body.get("index"))).toEqual(["0", "1", "2"]);
  expect(backend.callsTo("mmi_catalog_finish_run")[0]!.body.get("aborted")).toBeNull();
  expect(errors).toEqual([]);
});

/* ── Database-first: columns, cell edits, new changes ──────────────────── */

test("the Columns menu adds price and meta columns, kept for the next visit", async ({ page }) => {
  const { backend } = await open(page);
  await searchFor(page, "synth");
  await page.locator("#mmi-wb-columns-ms .mmi-ms-trigger").click();
  await page.locator("#mmi-wb-columns-ms").getByLabel("Regular price").check();
  await page.locator("#mmi-wb-meta-col").fill("_mmi_platforms");
  await page.locator("#mmi-wb-meta-col").press("Enter");
  await expect(page.locator("th[data-col='regular']")).toBeVisible();
  await expect(page.locator("th[data-col='meta:_mmi_platforms']")).toBeVisible();
  await expect.poll(() => backend.callsTo("mmi_workbench_search").at(-1)?.body.getAll("meta_cols[]")).toEqual(["_mmi_platforms"]);
  await expect(page.locator("#mmi-wb-rows tr[data-id='1001'] .mmi-wb-col-meta")).toContainText("mac,win");
  // The new headings sort on the server like the rest, and still resize.
  await page.locator("th[data-col='regular']").click();
  await expect.poll(() => backend.callsTo("mmi_workbench_search").at(-1)?.body.get("sort")).toBe("regular");
  await expect(page.locator("th[data-col='regular'] [class*='resize']").first()).toBeAttached();
  await page.reload();
  await expect(page.locator("th[data-col='meta:_mmi_platforms']")).toBeVisible();
});

test("a cell edits in place, refuses a bad value, and saves a good one", async ({ page }) => {
  const { backend } = await open(page);
  await searchFor(page, "synth");
  await page.locator("#mmi-wb-columns-ms .mmi-ms-trigger").click();
  await page.locator("#mmi-wb-columns-ms").getByLabel("Regular price").check();
  await page.keyboard.press("Escape");
  const row = page.locator("#mmi-wb-rows tr[data-id='1001']");
  await row.locator(".mmi-wb-col-regular .mmi-wb-edit-cell").click();
  const input = row.locator(".mmi-wb-cell-input");
  await input.fill("abc");
  await input.press("Enter");
  await expect(row.locator(".mmi-wb-cell-error")).toHaveText("Enter a price, e.g. 49.99.");
  await input.fill("49.99");
  await row.locator(".mmi-wb-cell-lock input").check();
  await row.locator(".mmi-wb-cell-save").click();
  await expect(page.locator(".mmi-wb-row-notice")).toHaveText("Regular price saved. Undo it from Recent changes.");
  await expect(row.locator(".mmi-wb-col-regular")).toContainText("49.99");
  const saved = backend.callsTo("mmi_workbench_set_field").at(-1)!.body;
  expect([saved.get("field"), saved.get("value"), saved.get("lock")]).toEqual(["regular", "49.99", "1"]);

  await row.locator(".mmi-wb-col-title .mmi-wb-edit-cell").click();
  await row.locator(".mmi-wb-cell-input").fill("Synth Bundle One");
  await row.locator(".mmi-wb-cell-input").press("Enter");
  await expect(row.locator(".mmi-wb-col-title a")).toHaveText("Synth Bundle One");
});

test("the new changes show their settings: replace in title, set meta", async ({ page }) => {
  await open(page);
  await page.locator("#mmi-wb-action").selectOption("replace_in_title");
  await expect(page.locator(".mmi-crp-find")).toBeVisible();
  await expect(page.locator(".mmi-crp-replace")).toBeVisible();
  await page.locator("#mmi-wb-action").selectOption("set_meta");
  await expect(page.locator(".mmi-crp-meta-key")).toBeVisible();
  await expect(page.locator(".mmi-crp-meta-value")).toBeVisible();
  await expect(page.locator(".mmi-crp-find")).toBeHidden();
  await page.locator("#mmi-wb-action").selectOption("delete_meta");
  await expect(page.locator(".mmi-crp-meta-key")).toBeVisible();
  await expect(page.locator(".mmi-crp-meta-value")).toBeHidden();
  await expect(page.locator('#mmi-wb-action option[value="set_status_private"]')).toHaveCount(1);
});
