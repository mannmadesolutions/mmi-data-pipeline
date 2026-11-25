/**
 * Category & brand mapping (TaxonomyMapping.tsx), moved from the Import tab 2026-11-25: one scan when the section
 * opens, sources on the left, a source's values beside them; the term field picks or creates a term; Skip, Clear and
 * Exclude change one row; Apply all asks for a second click and runs in the background. The mapper's admin-ajax
 * actions are answered here; the page's config (sources, taxonomies, profiles) is the live site's.
 */
import { expect, test, type Page, type Route } from "@playwright/test";
import { MockBackend, taxValue } from "./mock-backend";
import { openSection } from "./shell";
import { TitleRulesMock } from "./title-rules-mock";

async function open(page: Page, query = "") {
  const errors: string[] = [];
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("console", (m) => { if (m.type() === "error" && !/status of 40[0349]/.test(m.text())) errors.push(m.text()); });
  await page.route("**/mmi-data-pipeline/build/workbench.js*", (r) => r.fulfill({ path: "build/workbench.js", contentType: "text/javascript" }));
  await new MockBackend().attach(page);
  await new TitleRulesMock().attach(page);
  const calls: { action: string; body: URLSearchParams }[] = [];
  const values = [
    taxValue("ACME AUDIO", 12),
    taxValue("Example Synths", 7, { wc_term_id: 401, wc_term_name: "Example Synths", mapping_id: 9 }),
    taxValue("TESTVENDOR", 2, { excluded: true, excluded_at: "2026-11-25 04:00:00", excluded_by: "Owner" }),
    taxValue("Gone Brand", 3, { missing_term_id: 77, mapping_id: 11 }),
  ];
  await page.route("**/wp-admin/admin-ajax.php", (route: Route) => {
    const body = new URLSearchParams(route.request().postData() ?? "");
    const action = body.get("action") ?? "";
    const reply = (data: unknown) => route.fulfill({ contentType: "application/json", body: JSON.stringify({ success: true, data }) });
    const mine = ["mmi_scan_all_sources", "mmi_search_wc_terms", "mmi_save_taxonomy_mapping", "mmi_delete_taxonomy_mapping", "mmi_set_taxonomy_value_excluded", "mmi_apply_all_taxonomy_mappings", "mmi_get_taxonomy_variations"];
    if (!mine.includes(action)) return route.fallback();
    calls.push({ action, body });
    switch (action) {
      case "mmi_scan_all_sources": return reply({ values, total: 3, mapped: 1, excluded: 1 });
      case "mmi_search_wc_terms": {
        const q = (body.get("search") ?? "").toLowerCase();
        return reply({ terms: [{ id: 401, name: "Example Synths", count: 7 }, { id: 402, name: "Acme", count: 3 }].filter((t) => !q || t.name.toLowerCase().includes(q)) });
      }
      case "mmi_save_taxonomy_mapping": {
        const id = Number(body.get("wc_term_id"));
        return reply({ mapping_id: 50, wc_term_id: id === 0 ? 999 : id, wc_term_name: id === -1 ? "__skip__" : body.get("term_name"), applied: { updated: 12, already: 0, locked: 1, too_many: 0 } });
      }
      case "mmi_delete_taxonomy_mapping": return reply({ message: "Deleted" });
      case "mmi_set_taxonomy_value_excluded": return reply({ excluded: body.get("excluded") === "1", excluded_at: "2026-11-25 04:00:00", excluded_by: "Owner" });
      case "mmi_apply_all_taxonomy_mappings": return reply({ message: "Applying every mapping to existing products in the background.", queued: true });
      case "mmi_get_taxonomy_variations": return reply({ variations: [], profiles: {} });
      default: return route.fallback();
    }
  });
  await page.goto(`/${query}`);
  return { calls, errors };
}

const rows = (page: Page) => page.locator("#mmi-wb-tm-rows tr[data-key]");
const row = (page: Page, value: string) => page.locator(`#mmi-wb-tm-rows tr[data-key="xchange|brand|${value}"]`);

test("opens on every source's unmapped values after one scan; chips and the source list narrow it", async ({ page }) => {
  const { calls, errors } = await open(page);
  expect(calls.filter((c) => c.action === "mmi_scan_all_sources")).toHaveLength(0); // nothing reads the feeds until the section opens
  await openSection(page, "mapping");
  await expect(rows(page)).toHaveCount(2); // Unmapped: ACME AUDIO and the deleted term's value
  expect(calls.filter((c) => c.action === "mmi_scan_all_sources")).toHaveLength(1);
  await expect(page.locator('#mmi-wb-tm-sources tr[data-key="all"] .mmi-wb-split-num')).toHaveText("2");
  await expect(row(page, "Gone Brand")).toContainText("Term deleted");
  await expect(row(page, "Gone Brand")).toContainText("The mapped term (#77) was deleted");
  await page.locator('#mmi-wb-mapping [data-chip="excluded"]').click();
  await expect(rows(page)).toHaveCount(1);
  await expect(row(page, "TESTVENDOR")).toContainText("Excluded on 2026-11-25");
  await page.locator('#mmi-wb-tm-sources tr[data-key="xchange|brand"]').click();
  expect(new URL(page.url()).searchParams.get("wb_source")).toBe("xchange|brand");
  await page.locator('#mmi-wb-mapping [data-chip="all"]').click();
  await expect(rows(page)).toHaveCount(3);
  expect(errors).toEqual([]);
});

test("picking a term in the field saves the mapping and says what it did to products in the store", async ({ page }) => {
  const { calls } = await open(page, "?wb_sec=mapping");
  const field = row(page, "ACME AUDIO").locator("input.mmi-wb-tm-term");
  await field.click();
  await field.fill("acm");
  const option = page.locator(".mmi-wb-tm-picker .mmi-wb-tm-option", { hasText: "Acme" }).first();
  await option.click();
  await expect(page.locator("#mmi-wb-tm-notice")).toContainText("“Acme” applied to 12 existing products. 1 skipped (field locked on the product).");
  const sent = calls.find((c) => c.action === "mmi_save_taxonomy_mapping")!.body;
  expect([sent.get("supplier_id"), sent.get("source_field"), sent.get("source_value"), sent.get("wc_taxonomy"), sent.get("wc_term_id"), sent.get("profile_id")])
    .toEqual(["xchange", "brand", "ACME AUDIO", "product_brand", "402", ""]);
  await expect(rows(page)).toHaveCount(1); // mapped: it leaves Unmapped
});

test("Create term opens an editor with the name title-cased; Create & map finds or creates it", async ({ page }) => {
  const { calls } = await open(page, "?wb_sec=mapping");
  await row(page, "ACME AUDIO").locator('[data-act="create"]').click();
  const name = page.locator(".mmi-wb-tm-create input[type=text]");
  await expect(name).toHaveValue("Acme Audio");
  await page.locator(".mmi-wb-tm-create").getByRole("button", { name: "UPPER" }).click();
  await expect(name).toHaveValue("ACME AUDIO");
  await page.locator(".mmi-wb-tm-create").getByRole("button", { name: "Create & map" }).click();
  const sent = calls.find((c) => c.action === "mmi_save_taxonomy_mapping")!.body;
  expect([sent.get("wc_term_id"), sent.get("term_name")]).toEqual(["0", "ACME AUDIO"]);
});

test("Skip, Clear and Exclude change one row; Apply all asks for a second click", async ({ page }) => {
  const { calls } = await open(page, "?wb_sec=mapping");
  await row(page, "ACME AUDIO").locator('[data-act="skip"]').click();
  await expect(page.locator("#mmi-wb-tm-notice")).toContainText("is skipped");
  expect(calls.find((c) => c.action === "mmi_save_taxonomy_mapping")!.body.get("wc_term_id")).toBe("-1");
  await row(page, "Gone Brand").locator('[data-act="clear"]').click();
  await expect.poll(() => calls.filter((c) => c.action === "mmi_delete_taxonomy_mapping").map((c) => c.body.get("mapping_id"))).toEqual(["11"]);
  await row(page, "Gone Brand").locator('[data-act="exclude"]').click();
  await expect(page.locator("#mmi-wb-tm-notice")).toContainText("“Gone Brand” is excluded");
  expect(calls.find((c) => c.action === "mmi_set_taxonomy_value_excluded")!.body.get("excluded")).toBe("1");
  const apply = page.locator("#mmi-wb-tm-apply");
  await apply.click();
  await expect(apply).toContainText("Click again");
  expect(calls.filter((c) => c.action === "mmi_apply_all_taxonomy_mappings")).toHaveLength(0);
  await apply.click();
  await expect(page.locator("#mmi-wb-tm-notice")).toContainText("in the background");
});

test("an Import tab source link opens its source; alias rules and variations open beside the list", async ({ page }) => {
  await open(page, "?wb_sec=mapping&wb_source=xchange");
  await expect(page.locator('#mmi-wb-tm-sources tr[data-key="xchange|master_category+sub_category"], #mmi-wb-tm-sources tr[data-key="xchange|brand"]').and(page.locator(".is-open"))).toHaveCount(1);
  await page.locator("#mmi-wb-tm-open-rules").click();
  await expect(page.locator("#mmi-wb-tm-work h4")).toHaveText("Alias rules");
  await page.locator("#mmi-wb-tm-rule-add").click();
  await expect(page.locator(".mmi-wb-tm-rule")).toHaveCount(1);
  await page.locator(".mmi-wb-tm-rule input[placeholder=roland]").fill("acme");
  await expect(page.locator(".mmi-wb-tm-rule [data-matches]")).toHaveText("1 match (1 unmapped)");
  await page.locator("#mmi-wb-tm-open-variations").click();
  await expect(page.locator("#mmi-wb-tm-var-table")).toContainText("No profile-only variations saved.");
});
