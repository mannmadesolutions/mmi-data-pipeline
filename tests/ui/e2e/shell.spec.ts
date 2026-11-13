/**
 * The Workbench's frame (Shell.tsx): a side menu of every section, one on screen; sections mount on first open and
 * stay as they were left; the address keeps the place; badges from one counts call; a <select> on narrow screens.
 */
import { expect, test, type Page } from "@playwright/test";
import { MockBackend } from "./mock-backend";
import { openSection } from "./shell";
import { TitleRulesMock } from "./title-rules-mock";

async function open(page: Page, o: { countsDelayMs?: number; clock?: boolean; query?: string } = {}) {
  const errors: string[] = [];
  page.on("pageerror", (e) => errors.push(e.message));
  page.on("console", (m) => { if (m.type() === "error" && !/status of 40[034]/.test(m.text())) errors.push(m.text()); });
  await page.route("**/mmi-data-pipeline/build/workbench.js*", (r) => r.fulfill({ path: "build/workbench.js", contentType: "text/javascript" }));
  const backend = new MockBackend();
  await backend.attach(page);
  const api = new TitleRulesMock();
  await api.attach(page);
  if (o.countsDelayMs) {
    await page.route("**/wp-admin/admin-ajax.php", async (route) => {
      if ((route.request().postData() ?? "").includes("action=mmi_workbench_counts")) await new Promise((r) => setTimeout(r, o.countsDelayMs));
      await route.fallback();
    });
  }
  if (o.clock) await page.clock.install();
  await page.goto(`/${o.query ?? ""}`);
  await expect(page.locator("#mmi-wb-shell")).toBeVisible();
  return { backend, api, errors };
}

const item = (page: Page, key: string) => page.locator(`#mmi-wb-menu [data-sec="${key}"]`);
const current = (page: Page) => page.locator('#mmi-wb-menu [aria-current="page"]');

test("every section in groups, Find and change first; badges arrive from one counts call", async ({ page }) => {
  const { backend, errors } = await open(page);
  // The review queues are Needs a decision's pills (2026-11-13): no Review group.
  await expect(page.locator(".mmi-wb-menu-heading")).toHaveText(["Work", "Maker data", "Automation", "History"]);
  await expect(page.locator('[data-group="work"] [data-sec]')).toHaveText([/Needs a decision/, /Find and change/]);
  await expect(current(page)).toHaveAttribute("data-sec", "data");
  await expect(page.locator("#mmi-wb-find")).toBeVisible();
  await expect(item(page, "data").locator(".mmi-wb-menu-badge")).toHaveText("120"); // draft and published, as Find and change browses
  await expect(item(page, "upkeep").locator(".mmi-wb-menu-badge")).toHaveText("Hourly");
  expect(backend.callsTo("mmi_workbench_counts")).toHaveLength(1);
  expect(errors).toEqual([]);
});

test("a slow count shows a spinner in its badge, never a blank menu", async ({ page }) => {
  await open(page, { countsDelayMs: 1500 });
  await expect(item(page, "upkeep")).toBeVisible();
  await expect(item(page, "upkeep").locator(".mmi-wb-menu-badge.is-loading")).toBeVisible();
  await expect(item(page, "upkeep").locator(".mmi-wb-menu-badge")).toHaveText("Hourly");
});

test("a menu click adds a history entry: Back returns, reload keeps the place, Cmd-click has a real address", async ({ page }) => {
  await open(page);
  await expect(item(page, "maps")).toHaveAttribute("href", /[?&]wb_sec=maps(&|$)/);
  await openSection(page, "rules");
  expect(new URL(page.url()).searchParams.get("wb_sec")).toBe("rules");
  await expect(page.locator("#mmi-wb-title-rules")).toBeVisible();
  await expect(page.locator("#mmi-wb-find")).toBeHidden();
  await openSection(page, "maps");
  await page.goBack();
  await expect(current(page)).toHaveAttribute("data-sec", "rules");
  await expect(page.locator("#mmi-wb-title-rules")).toBeVisible();
  await page.reload();
  await expect(current(page)).toHaveAttribute("data-sec", "rules");
  // A review queue's pill is in the address too: a reload opens Needs a decision on it.
  await openSection(page, "titles");
  expect(new URL(page.url()).searchParams.get("wb_kind")).toBe("titles");
  await page.reload();
  await expect(current(page)).toHaveAttribute("data-sec", "decide");
  await expect(page.locator("#mmi-wb-proposals")).toBeVisible();
});

test("a queue is drawn on first open of its pill and keeps its state; Descriptions and Pictures to pick share one", async ({ page }) => {
  const { api } = await open(page);
  await expect(page.locator("#mmi-wb-proposals")).toHaveCount(0);
  await openSection(page, "titles");
  await page.locator("#mmi-wb-pr-q").fill("Synth");
  await openSection(page, "data");
  await openSection(page, "titles");
  await expect(page.locator("#mmi-wb-pr-q")).toHaveValue("Synth"); // not drawn again
  await openSection(page, "picks");
  await expect(page.locator('#mmi-wb-enrich [data-kind="image"]')).toHaveAttribute("aria-pressed", "true");
  await page.locator('#mmi-wb-enrich [data-kind="content"]').click(); // the queue's own pill: Needs a decision's follows
  await expect(page.locator('#mmi-wb-decide .mmi-wb-dc-pills [data-kind="descriptions"]')).toHaveAttribute("aria-pressed", "true");
  await expect(current(page)).toHaveAttribute("data-sec", "decide");
  await expect.poll(() => new URL(page.url()).searchParams.get("wb_kind")).toBe("descriptions");
  expect(api.callsTo("enrich/summary").length).toBeGreaterThanOrEqual(1);
});

test("a hidden section's poller pauses, and looks again when it is back", async ({ page }) => {
  const { api } = await open(page, { clock: true });
  await openSection(page, "titles");
  await expect.poll(() => api.callsTo("apply-status").length).toBeGreaterThanOrEqual(1);
  await openSection(page, "data");
  const before = api.callsTo("apply-status").length;
  await page.clock.runFor(95_000); // three idle polls' worth
  expect(api.callsTo("apply-status").length).toBe(before);
  await openSection(page, "titles");
  await expect.poll(() => api.callsTo("apply-status").length).toBe(before + 1);
});

test("a job run from another section shows its outcome above it", async ({ page }) => {
  const { backend } = await open(page);
  backend.jobs = [{ id: "job-1", created_at: "2026-11-12 08:00:00", user: "Tester", action: "set_status_draft", summary: "Set to Draft", filter_label: "Title contains synth",
    total: 3, cursor: 3, changed: 3, unchanged: 0, failed: 0, skipped: 0, skip_locked: false, add_lock: false, status: "done", undo_cursor: 0, undone: 0, conflicts: 0 }];
  await page.reload();
  await openSection(page, "changes");
  page.on("dialog", (d) => d.accept());
  await page.locator("#mmi-wb-jobs-rows .mmi-wb-job-undo").click();
  await expect(page.locator("#mmi-wb-strip-message")).toBeVisible();
  await page.locator("#mmi-wb-strip-message").getByRole("button", { name: "Dismiss" }).click();
  await expect(page.locator("#mmi-wb-strip")).toHaveCount(0);
});

test("under 960 px the menu is a select above the section", async ({ page }) => {
  await page.setViewportSize({ width: 800, height: 900 });
  await open(page);
  await expect(page.locator("#mmi-wb-menu")).toBeHidden();
  const select = page.locator("#mmi-wb-menu-select");
  await expect(select).toBeVisible();
  await expect(select.locator("option", { hasText: "Find and change" })).toHaveText(/\(120\)/);
  await select.selectOption("maps");
  await expect(page.locator("#mmi-wb-site-maps")).toBeVisible();
  expect(new URL(page.url()).searchParams.get("wb_sec")).toBe("maps");
});
