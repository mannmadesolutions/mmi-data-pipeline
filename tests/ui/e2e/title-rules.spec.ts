/**
 * The Workbench's Title rules section in a real browser (WebKit first: the
 * owner uses Safari), with the REST routes mocked. The bundle under test is
 * the workspace's fresh build, so the section is checked before it deploys.
 */
import { expect, test, type Page } from "@playwright/test";
import { MockBackend } from "./mock-backend";
import { TitleRulesMock } from "./title-rules-mock";

async function open(page: Page, o: { staleOnce?: boolean; basic?: boolean } = {}) {
  const errors: string[] = [];
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("console", (m) => { if (m.type() === "error" && !/status of 40[034]/.test(m.text())) errors.push(m.text()); });
  page.on("dialog", (d) => d.accept());
  // The workspace's fresh build (scripts/ui.sh build or check), not the deployed one.
  await page.route("**/mmi-data-pipeline/build/workbench.js*", (r) => r.fulfill({ path: "build/workbench.js", contentType: "text/javascript" }));
  await new MockBackend().attach(page);
  const rules = new TitleRulesMock(o);
  await rules.attach(page);
  await page.goto("/");
  await expect(page.locator("#mmi-wb-title-rules")).toBeVisible();
  await expect(page.locator("#mmi-wb-tr-rows tr[data-rule-id]")).toHaveCount(2);
  return { rules, errors };
}

test("lists the store's rules in the order they apply, with brand and scope", async ({ page }) => {
  const { errors } = await open(page);
  const rows = page.locator("#mmi-wb-tr-rows tr[data-rule-id]");
  await expect(rows.nth(0)).toContainText('"Sub" → "Subscription"');
  await expect(rows.nth(0)).toContainText("Acme Audio");
  await expect(rows.nth(1)).toContainText('Remove "Promo"');
  await expect(rows.nth(1)).toContainText("from xchange");
  await expect(rows.nth(1)).toContainText("Every brand");
  await expect(page.locator("#mmi-wb-title-rules .mmi-process-section-description")).toContainText("after the House style rules");
  // Every heading sorts, and says which way.
  const head = page.locator('#mmi-wb-tr-table th[data-col="label"]');
  await head.click();
  await expect(head).toHaveAttribute("aria-sort", "ascending");
  await head.click();
  await expect(head).toHaveAttribute("aria-sort", "descending");
  await expect(rows.nth(0)).toContainText("Promo");
  expect(errors).toEqual([]);
});

test("a new rule previews once typing pauses, then saves and says what changed", async ({ page }) => {
  const { rules, errors } = await open(page);
  await page.locator("#mmi-wb-tr-add").click();
  await expect(page.locator("#mmi-wb-tr-save")).toBeDisabled();
  await page.locator("#mmi-wb-tr-from").pressSequentially("Plug-In", { delay: 30 });
  await page.locator("#mmi-wb-tr-to").pressSequentially("Plugin", { delay: 30 });
  await expect(page.locator("#mmi-wb-tr-summary")).toContainText("Would change 2 product titles (4 checked).");
  expect(rules.callsTo("custom-rules/preview").length).toBeLessThanOrEqual(2); // not one per key
  await expect(page.locator("#mmi-wb-tr-samples tbody tr")).toHaveCount(2);
  await expect(page.locator("#mmi-wb-tr-samples .mmi-wb-after").first()).toHaveText("Acme Audio Great Plugin (Download)");
  await page.locator("#mmi-wb-tr-save").click();
  await expect(page.locator("#mmi-wb-tr-notice")).toHaveText("Rule saved. 2 proposals updated now.");
  await expect(page.locator("#mmi-wb-tr-form")).toHaveCount(0);
  await expect(page.locator("#mmi-wb-tr-rows tr[data-rule-id]")).toHaveCount(3);
  expect(rules.callsTo("custom-rules/add")[0]!.body).toMatchObject({ kind: "replace", from: "Plug-In", to: "Plugin", case: "", categories: [], suppliers: [] });
  expect(errors).toEqual([]);
});

test("scope, any capitals and the move-into-parentheses kind reach the server", async ({ page }) => {
  const { rules } = await open(page);
  await page.locator("#mmi-wb-tr-add").click();
  await page.locator("#mmi-wb-tr-kind").selectOption("qualifier");
  await expect(page.locator("#mmi-wb-tr-to")).toHaveAttribute("placeholder", "Leave empty to keep them as found");
  await page.locator("#mmi-wb-tr-from").fill("Upgrade");
  await page.locator("#mmi-wb-tr-brand").selectOption("Example Sound");
  await page.locator("#mmi-wb-tr-cats .mmi-ms-trigger").click();
  await page.locator('#mmi-wb-tr-cats input[value="12"]').check();
  await page.locator("#mmi-wb-tr-sups .mmi-ms-trigger").click();
  await page.locator('#mmi-wb-tr-sups input[value="skuport"]').check();
  await page.locator("#mmi-wb-tr-case").check();
  await expect(page.locator("#mmi-wb-tr-summary")).toContainText('Move "Upgrade" into the parentheses');
  await page.locator("#mmi-wb-tr-save").click();
  await expect(page.locator("#mmi-wb-tr-notice")).toContainText("Rule saved.");
  expect(rules.callsTo("custom-rules/add")[0]!.body).toMatchObject({ kind: "qualifier", from: "Upgrade", brand: "Example Sound", case: "any", categories: [12], suppliers: ["skuport"] });
  await expect(page.locator('#mmi-wb-tr-rows tr[data-rule-id="r3"]')).toContainText("in Plug-ins; from skuport; any capitals");
});

test("a pattern the server can't compile says why, and can't be saved", async ({ page }) => {
  await open(page);
  await page.locator("#mmi-wb-tr-add").click();
  await page.locator("#mmi-wb-tr-kind").selectOption("pattern");
  await page.locator("#mmi-wb-tr-from").fill("(unclosed");
  await expect(page.locator("#mmi-wb-tr-error")).toContainText("missing closing parenthesis");
  await expect(page.locator("#mmi-wb-tr-save")).toBeDisabled();
  await page.locator("#mmi-wb-tr-from").fill("\\bVol\\. (\\d+)");
  await page.locator("#mmi-wb-tr-to").fill("Volume $1");
  await expect(page.locator("#mmi-wb-tr-error")).toHaveCount(0);
  await expect(page.locator("#mmi-wb-tr-summary")).toContainText("Would change 1 product title");
  await expect(page.locator("#mmi-wb-tr-save")).toBeEnabled();
});

test("edit opens the rule as written; move and delete work in place", async ({ page }) => {
  const { rules } = await open(page);
  await page.locator('#mmi-wb-tr-rows tr[data-rule-id="r1"] button:has-text("Edit")').click();
  await expect(page.locator("#mmi-wb-tr-from")).toHaveValue("Sub");
  await expect(page.locator("#mmi-wb-tr-to")).toHaveValue("Subscription");
  await expect(page.locator("#mmi-wb-tr-brand")).toHaveValue("Acme Audio");
  await page.locator("#mmi-wb-tr-to").fill("Subscription Plan");
  await expect(page.locator("#mmi-wb-tr-summary")).toBeVisible();
  await page.locator("#mmi-wb-tr-save").click();
  await expect(page.locator("#mmi-wb-tr-notice")).toHaveText("Rule changed. 1 proposal updated now.");
  expect(rules.callsTo("custom-rules/update")[0]!.body).toMatchObject({ id: "r1", to: "Subscription Plan" });

  await page.locator('#mmi-wb-tr-rows tr[data-rule-id="r1"] button[aria-label^="Move"][aria-label$="later"]').click();
  await expect(page.locator("#mmi-wb-tr-notice")).toContainText("Order saved.");
  expect(rules.callsTo("custom-rules/order")[0]!.body).toEqual({ ids: ["r2", "r1"] });
  await expect(page.locator("#mmi-wb-tr-rows tr[data-rule-id]").nth(0)).toHaveAttribute("data-rule-id", "r2");

  await page.locator('#mmi-wb-tr-rows tr[data-rule-id="r2"] .mmi-wb-rule-delete').click();
  await expect(page.locator("#mmi-wb-tr-rows tr[data-rule-id]")).toHaveCount(1);
  await expect(page.locator("#mmi-wb-tr-notice")).toContainText("Rule deleted.");
});

test("an expired REST nonce is fetched again and the call resent, unseen", async ({ page }) => {
  const { rules, errors } = await open(page, { staleOnce: true });
  expect(rules.nonceCalls).toBeGreaterThanOrEqual(2);
  await expect(page.locator("#mmi-wb-tr-notice")).toHaveCount(0);
  expect(errors).toEqual([]);
});

test("built-in rules switch in place: In use recomposes, May auto-apply approves", async ({ page }) => {
  const { rules, errors } = await open(page);
  const rows = page.locator("#mmi-wb-tr-builtin-rows tr[data-rule]");
  await expect(rows).toHaveCount(3);
  await expect(page.locator('#mmi-wb-tr-builtin-rows tr[data-rule="tidy"]').getByRole("checkbox", { name: "Tidy punctuation in use" })).toBeDisabled();
  const caps = page.locator('#mmi-wb-tr-builtin-rows tr[data-rule="deshout"]');
  await expect(caps).toContainText("2");
  await caps.getByRole("checkbox", { name: "Fix ALL CAPS in use" }).uncheck();
  await expect(page.locator("#mmi-wb-tr-notice")).toHaveText("Fix ALL CAPS is switched off. Proposals were recomposed: 2 changed.");
  await expect(caps).toHaveClass(/mmi-wb-rule-off/);
  expect(rules.callsTo("rules")[0]!.body).toEqual({ rule: "deshout", enabled: false });
  await caps.getByRole("checkbox", { name: "Fix ALL CAPS may auto-apply" }).check();
  await expect(page.locator("#mmi-wb-tr-notice")).toHaveText("Fix ALL CAPS may auto-apply.");
  expect(rules.callsTo("rules")[1]!.body).toEqual({ rule: "deshout", approved: true });
  await expect(page.locator("#mmi-wb-tr-words")).toHaveCount(0); // the house style doesn't read the word lists
  expect(errors).toEqual([]);
});

test("with the Basic rules, the word lists show and save as lists", async ({ page }) => {
  const { rules } = await open(page, { basic: true });
  await expect(page.locator("#mmi-wb-title-rules h4").first()).toHaveText("Built-in rules: Basic");
  await expect(page.locator("#mmi-wb-tr-w-keep_caps")).toHaveValue("MIDI, HDMI");
  await page.locator("#mmi-wb-tr-w-keep_caps").fill("MIDI, HDMI,\nDSP, MIDI");
  await page.locator("#mmi-wb-tr-words-save").click();
  await expect(page.locator("#mmi-wb-tr-notice")).toContainText("Word lists saved.");
  expect(rules.callsTo("title-words").find((c) => c.method === "POST")!.body).toMatchObject({ keep_caps: ["MIDI", "HDMI", "DSP"] });
  await expect(page.locator("#mmi-wb-tr-w-keep_caps")).toHaveValue("MIDI, HDMI, DSP");
});
