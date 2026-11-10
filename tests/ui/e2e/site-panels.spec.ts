/**
 * A store's own Workbench panels (mmi_pipeline_product_data_panels): the
 * harness page gets three panels added to its config; one script registers
 * before the Workbench runs, one after it (with the ready event), one never.
 */
import { expect, test, type Page } from "@playwright/test";
import { MockBackend } from "./mock-backend";
import { TitleRulesMock } from "./title-rules-mock";

const PANELS = [
  { id: "early", title: "Early panel", desc: "Registered before the Workbench", icon: "screenoptions" },
  { id: "late", title: "Late panel", desc: "Registered after, with the ready event", icon: "megaphone" },
  { id: "missing", title: "Missing panel", desc: "Its script never comes", icon: "warning" },
];
const EARLY = `<script>window.mmiWorkbenchPanels = { early: { mount(el) { el.innerHTML = '<p id="early-body">Drawn early</p>'; } } };</script>`;
const LATE = `<script>setTimeout(function () { window.mmiWorkbenchPanels.late = { mount(el) { el.textContent = 'Drawn late'; el.id = 'late-body'; } }; window.dispatchEvent(new CustomEvent('mmi-wb:panel-ready', { detail: 'late' })); }, 800);</script>`;

async function open(page: Page) {
  const errors: string[] = [];
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("console", (m) => { if (m.type() === "error" && !/status of 40[034]/.test(m.text())) errors.push(m.text()); });
  await page.route("**/mmi-data-pipeline/build/workbench.js*", (r) => r.fulfill({ path: "build/workbench.js", contentType: "text/javascript" }));
  await new MockBackend().attach(page);
  await new TitleRulesMock().attach(page);
  await page.route(/\/$/, async (route) => {
    const res = await route.fetch();
    let html = await res.text();
    html = html.replace('"panels":[]', `"panels":${JSON.stringify(PANELS)}`).replace("<body", `${EARLY}<body`).replace("</body>", `${LATE}</body>`);
    await route.fulfill({ response: res, body: html });
  });
  await page.clock.install();
  await page.goto("/");
  return { errors };
}

test("each panel gets a section; scripts mount early or late; a missing one says so", async ({ page }) => {
  const { errors } = await open(page);
  await expect(page.locator("#mmi-wb-panel-early h3")).toContainText("Early panel");
  await expect(page.locator("#early-body")).toHaveText("Drawn early");
  await page.clock.runFor(1000);
  await expect(page.locator("#late-body")).toHaveText("Drawn late");
  await expect(page.locator("#mmi-wb-panel-late .mmi-wb-panel-wait")).toHaveCount(0);
  await expect(page.locator("#mmi-wb-panel-missing .mmi-wb-panel-wait")).toBeVisible();
  await page.clock.runFor(16000);
  await expect(page.locator("#mmi-wb-panel-missing")).toContainText("This panel’s script didn’t load");
  await expect(page.locator("#mmi-wb-panel-early")).not.toContainText("didn’t load");
  expect(errors).toEqual([]);
});
