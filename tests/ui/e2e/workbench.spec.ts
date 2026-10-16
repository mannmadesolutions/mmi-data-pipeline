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
  return { backend, errors };
}

async function searchFor(page: Page, q: string) {
  await page.locator("#mmi-wb-q").fill(q);
  await expect(page.locator("#mmi-wb-count")).toContainText("found");
}

test("mounts, and hosts the shared jQuery components in place", async ({ page }) => {
  const { errors } = await open(page);
  await expect(page.locator("#mmi-wb-find #mmi-wb-cb")).toBeVisible();          // condition builder moved into Find
  await expect(page.locator("#mmi-wb-change #mmi-wb-action")).toBeVisible();     // change picker moved into Results
  await expect(page.locator(".mmi-wb-islands > *")).toHaveCount(0);
  await expect(page.locator("#mmi-wb-save-search")).toBeVisible();              // React portal inside the builder
  await expect(page.locator("#mmi-wb-empty")).toContainText("Type a search");
  await expect(page.locator("#mmi-wb-preview")).toBeDisabled();
  expect(errors).toEqual([]);
});

test("searching waits for typing to pause, then sends one request", async ({ page }) => {
  const { backend } = await open(page);
  await page.locator("#mmi-wb-q").pressSequentially("synth", { delay: 60 });
  await expect(page.locator("#mmi-wb-count")).toHaveText("40 products found");
  expect(backend.callsTo("mmi_workbench_search")).toHaveLength(1);
  expect(backend.callsTo("mmi_workbench_search")[0]!.body.get("filter[q]")).toBe("synth");
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
  expect(backend.callsTo("mmi_workbench_search")).toHaveLength(1);
});

test("a category chip adds a real condition row and narrows the search", async ({ page }) => {
  const { backend } = await open(page);
  await searchFor(page, "test");
  await page.locator(".mmi-wb-facet", { hasText: "Plug-ins" }).click();
  await expect(page.locator("#mmi-wb-conditions .mmi-condition-row")).toHaveCount(1);
  await expect.poll(() => backend.callsTo("mmi_workbench_search").at(-1)?.body.get("filter[conditions][0][value]")).toBe("Plug-ins");
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
  // The page's first Workbench call (Recent changes) met the stale nonce: refreshed once, resent, and every later call uses the new one.
  expect(backend.callsTo("mmi_refresh_nonces")).toHaveLength(1);
  expect(backend.callsTo("mmi_workbench_jobs").map((c) => c.body.get("nonce"))).toEqual([expect.not.stringMatching(/^nonce-2$/), "nonce-2"]);
  expect(backend.callsTo("mmi_workbench_search").map((c) => c.body.get("nonce"))).toEqual(["nonce-2"]);
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
  await page.locator(".mmi-wb-facet").first().click();
  await expect(page.locator("#mmi-wb-conditions .mmi-condition-row")).toHaveCount(1);
  await page.locator(".mmi-wb-filters-clear").click();
  await expect(page.locator("#mmi-wb-q")).toHaveValue("");
  await expect(page.locator("#mmi-wb-conditions .mmi-condition-row")).toHaveCount(0);
  await expect(page.locator("#mmi-wb-empty")).toBeVisible();
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
