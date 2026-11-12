/**
 * The Workbench's Title proposals section (Product Data's review queue) in a
 * real browser, WebKit first, with the REST routes mocked. The bundle under
 * test is the workspace's fresh build.
 */
import { expect, test, type Page } from "@playwright/test";
import { MockBackend } from "./mock-backend";
import { openSection } from "./shell";
import { TitleRulesMock } from "./title-rules-mock";

async function open(page: Page, key = "titles") {
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
  await expect(page.locator("#mmi-wb-proposals")).toBeVisible();
  await expect(page.locator("#mmi-wb-pr-rows tr[data-id]")).toHaveCount(22);
  return { api, errors };
}

const view = (page: Page, v: string) => page.locator(`#mmi-wb-proposals [data-view="${v}"]`);
const row = (page: Page, id: number) => page.locator(`#mmi-wb-pr-rows tr[data-id="${id}"]`);

test("opens on the titles to review: counts, what changes, the rules and how sure", async ({ page }) => {
  const { errors } = await open(page);
  await expect(view(page, "open")).toHaveAttribute("aria-pressed", "true");
  await expect(view(page, "open")).toContainText("22");
  await expect(view(page, "queued")).toContainText("3");
  await expect(page.locator("#mmi-wb-pr-count")).toHaveText("22 proposals");
  const first = page.locator("#mmi-wb-pr-rows tr[data-id]").first();
  await expect(first).toHaveAttribute("data-id", "2001"); // most sure first
  await expect(first.locator(".mmi-wb-pr-new .mmi-wb-after").first()).toHaveText("Acme Audio ");
  await expect(first.locator(".mmi-wb-pr-now .mmi-wb-before")).toHaveText("Perp Lic");
  await expect(first.locator(".mmi-wb-pr-chip")).toHaveText(["Tidy punctuation"]);
  await expect(first).toContainText("95%");
  await expect(row(page, 2004).locator(".mmi-wb-issue")).toHaveText(["Too long"]);
  await expect(page.locator("#mmi-wb-pr-writer")).toHaveText("3 titles waiting to be written.");
  expect(errors).toEqual([]);
});

test("views, search, brand, a rule chip and sorting narrow the queue", async ({ page }) => {
  await open(page);
  await view(page, "applied").click();
  await expect(page.locator("#mmi-wb-pr-rows tr[data-id]")).toHaveCount(3);
  await expect(row(page, 2026).locator(".mmi-badge").last()).toHaveText("Written");
  await view(page, "open").click();
  await page.locator("#mmi-wb-pr-q").pressSequentially("Model 12", { delay: 30 });
  await expect(page.locator("#mmi-wb-pr-rows tr[data-id]")).toHaveCount(1);
  await page.locator("#mmi-wb-pr-q").fill("");
  await page.locator("#mmi-wb-pr-brand").selectOption("Example Sound");
  await expect(page.locator("#mmi-wb-pr-count")).toHaveText("11 proposals");
  await row(page, 2002).locator(".mmi-wb-pr-chip", { hasText: "Fix ALL CAPS" }).click();
  await expect(page.locator("#mmi-wb-pr-rule")).toHaveValue("deshout");
  await expect(page.locator("#mmi-wb-pr-count")).toHaveText("7 proposals");
  const idHead = page.locator('#mmi-wb-pr-table th[data-col="id"]');
  await idHead.click();
  await expect(idHead).toHaveAttribute("aria-sort", "descending");
  await expect(page.locator("#mmi-wb-pr-rows tr[data-id]").first()).toHaveAttribute("data-id", "2020");
  await idHead.click();
  await expect(idHead).toHaveAttribute("aria-sort", "ascending");
  await expect(page.locator("#mmi-wb-pr-rows tr[data-id]").first()).toHaveAttribute("data-id", "2002");
});

test("accept and reject from a row, and in bulk on ticked rows", async ({ page }) => {
  const { api } = await open(page);
  await row(page, 2001).getByRole("button", { name: "Accept" }).click();
  await expect(page.locator("#mmi-wb-pr-notice")).toHaveText("1 title accepted. They are written in the next batch.");
  await expect(row(page, 2001)).toHaveCount(0);
  await expect(view(page, "queued")).toContainText("4");
  await expect(page.locator("#mmi-wb-pr-writer")).toHaveText("4 titles waiting to be written.");
  await row(page, 2002).getByRole("checkbox").check();
  await row(page, 2003).getByRole("checkbox").check();
  await page.locator("#mmi-wb-pr-reject-ticked").click();
  await expect(page.locator("#mmi-wb-pr-notice")).toHaveText("2 titles rejected.");
  expect(api.callsTo("decide")[1]!.body).toEqual({ action: "reject", ids: [2002, 2003] });
  await expect(view(page, "open")).toContainText("19");
});

test("edit writes your own title and can save a rule from the change", async ({ page }) => {
  const { api } = await open(page);
  await row(page, 2006).getByRole("button", { name: "Edit" }).click();
  const input = page.locator("#mmi-wb-pr-edit-input");
  await expect(input).toHaveValue("Example Sound Synth Model 6 (Perpetual License, Download)");
  await input.fill("Example Sound Synth Mk 6 (Perpetual License, Download)");
  await expect(page.locator("#mmi-wb-pr-suggest")).toContainText('"Model" → "Mk"');
  await expect(page.locator("#mmi-wb-pr-suggest")).toContainText("for Example Sound (14 more)");
  await page.locator("#mmi-wb-pr-suggest").getByLabel("for every brand (29 more)").check();
  await page.locator("#mmi-wb-pr-edit-save").click();
  await expect(page.locator("#mmi-wb-pr-notice")).toHaveText("Your title for #2006 is saved and goes out in the next batch. 1 rule saved; 12 other proposals updated.");
  expect(api.callsTo("proposals/2006/edit")[0]!.body).toEqual({ title: "Example Sound Synth Mk 6 (Perpetual License, Download)", rules: [{ key: "a".repeat(32), scope: "all" }] });
  await expect(row(page, 2006)).toHaveCount(0); // edited rows leave "To review"
});

test("the writer starts and counts down; a row is written now and put back", async ({ page }) => {
  await open(page);
  await page.locator("#mmi-wb-pr-write").click();
  await expect(page.locator("#mmi-wb-pr-writer")).toContainText("Next batch of up to 25 in 1 min 3");
  await page.locator("#mmi-wb-pr-stop").click();
  await expect(page.locator("#mmi-wb-pr-writer")).toHaveText("3 titles waiting to be written.");
  await view(page, "queued").click();
  await expect(page.locator("#mmi-wb-pr-rows tr[data-id]")).toHaveCount(3);
  await row(page, 2023).getByRole("button", { name: "Write now" }).click();
  await view(page, "applied").click();
  await expect(page.locator("#mmi-wb-pr-rows tr[data-id]")).toHaveCount(4);
  await row(page, 2023).getByRole("button", { name: "Put back" }).click();
  await expect(row(page, 2023)).toHaveCount(0);
  await view(page, "reverted").click();
  await expect(row(page, 2023)).toContainText("Put back");
});

test("a row's categories change in place: search, tick, save; the lock shows", async ({ page }) => {
  const { api } = await open(page);
  await expect(row(page, 2002).locator(".mmi-wb-pr-termcell").nth(1)).toContainText("Plug-ins");
  await expect(row(page, 2002).locator(".mmi-wb-pr-termcell").nth(1).locator(".dashicons-lock")).toHaveCount(1);
  await row(page, 2001).getByRole("button", { name: "Change categories of #2001" }).click();
  const picker = page.locator("#mmi-wb-pr-terms");
  await expect(picker.getByRole("checkbox", { name: "Plug-ins" })).toBeChecked();
  await page.locator("#mmi-wb-pr-terms-q").fill("soft");
  await picker.getByRole("checkbox", { name: "Software", exact: true }).check();
  await picker.getByRole("button", { name: "Remove Software › Plug-ins" }).click();
  await page.locator("#mmi-wb-pr-terms-save").click();
  await expect(page.locator("#mmi-wb-pr-notice")).toHaveText("Categories saved for #2001; its title was recomposed.");
  expect(api.callsTo("proposals/2001/categories")[0]!.body).toEqual({ ids: [10] });
  await expect(row(page, 2001).locator(".mmi-wb-pr-termcell").nth(1)).toContainText("Software");
});

test("ticked rows change brand together, replacing or adding", async ({ page }) => {
  const { api } = await open(page);
  await row(page, 2001).getByRole("checkbox").check();
  await row(page, 2003).getByRole("checkbox").check();
  await page.locator("#mmi-wb-pr-brands-ticked").click();
  await expect(page.locator("#mmi-wb-pr-terms")).toContainText("Brands for 2 ticked products");
  await expect(page.locator("#mmi-wb-pr-terms-save")).toBeEnabled(); // replacing with none clears the brand
  await page.locator("#mmi-wb-pr-terms-mode").selectOption("add");
  await expect(page.locator("#mmi-wb-pr-terms-save")).toBeDisabled(); // adding needs one
  await page.locator("#mmi-wb-pr-terms").getByRole("checkbox", { name: "Example Sound" }).check();
  await page.locator("#mmi-wb-pr-terms-save").click();
  await expect(page.locator("#mmi-wb-pr-notice")).toHaveText("Brands saved on 2 products; their titles were recomposed.");
  expect(api.callsTo("proposals/bulk-terms")[0]!.body).toEqual({ kind: "brands", mode: "add", products: [2001, 2003], ids: [21] });
});

test("a product missing an input says so, what it costs, and is fixed in the row", async ({ page }) => {
  const { api } = await open(page);
  const badge = row(page, 2007).locator(".mmi-badge.error");
  await expect(badge).toHaveText("No SKU");
  await expect(badge).toHaveAttribute("title", "Without a SKU the supplier’s data can’t be matched to it. Click to fix it here.");
  await badge.click();
  await page.locator(".mmi-wb-issue-sku").fill("FP-1000");
  await page.locator(".mmi-wb-issue-row .mmi-wb-issue-save").click();
  await expect(page.locator(".mmi-wb-pr-row-notice")).toHaveText("SKU set to FP-1000.");
  expect(api.callsTo("products/2007/fix")[0]!.body).toEqual({ kind: "sku", sku: "FP-1000" });
  await expect(row(page, 2007).locator(".mmi-wb-issue")).toHaveCount(0);
});
