/**
 * Every Workbench table looks and works like Needs a decision's (owner, 2026-11-12): the same heading
 * padding and height, every heading that holds data sorts, every column resizes, and no table runs past
 * its box. Checks each section the mocks fill, in WebKit and Chromium.
 */
import { expect, test, type Page } from "@playwright/test";
import { MockBackend } from "./mock-backend";
import { openSection } from "./shell";
import { TitleRulesMock } from "./title-rules-mock";

const SECTIONS = ["decide", "titles", "descriptions", "pages", "sites", "maps", "rules", "left", "standing", "upkeep", "pictures", "changes"];

interface TableLook { id: string; pads: string[]; heights: number[]; unsorted: string[]; handles: number; heads: number; over: number }

async function open(page: Page, key: string) {
  await page.route("**/mmi-data-pipeline/build/workbench.js*", (r) => r.fulfill({ path: "build/workbench.js", contentType: "text/javascript" }));
  await new MockBackend().attach(page);
  const api = new TitleRulesMock();
  api.waitingOn = true; // Needs a decision has its waiting products
  await api.attach(page);
  await page.goto("/");
  await openSection(page, key);
}

/** The visible pane's tables that have rows: their headings' look, what doesn't sort, handles and overflow. */
const looks = (page: Page) => page.evaluate((): TableLook[] => {
  const pane = Array.from(document.querySelectorAll<HTMLElement>(".mmi-wb-pane")).find((p) => !p.hidden);
  return Array.from(pane?.querySelectorAll<HTMLTableElement>("table") ?? [])
    .filter((t) => t.tHead && t.getClientRects().length && t.querySelector("tbody td:not(.mmi-wb-empty-cell)"))
    .map((t) => {
      const heads = Array.from(t.tHead!.rows[0]!.cells).filter((c) => !c.classList.contains("check-column") && c.getBoundingClientRect().width > 0);
      const box = t.closest(".mmi-table-scroll-wrapper") ?? t.parentElement!;
      return {
        id: t.id,
        pads: [...new Set(heads.map((h) => getComputedStyle(h).padding))],
        heights: [...new Set(heads.map((h) => Math.round(h.getBoundingClientRect().height)))],
        // Action columns (a screen-reader label only) don't sort; every other heading does.
        unsorted: heads.filter((h) => h.textContent!.trim() && !h.querySelector(".screen-reader-text") && !/(^|\s)(sortable|mmi-wb-job-sortable)(\s|$)/.test(h.className)).map((h) => h.textContent!.trim()),
        handles: t.querySelectorAll("thead .mmi-resize-handle").length,
        heads: heads.length,
        over: Math.round(t.scrollWidth - box.clientWidth),
      };
    });
});

for (const key of SECTIONS) {
  test(`${key}: tables look and work like Needs a decision's`, async ({ page }) => {
    await open(page, key);
    const pane = page.locator(".mmi-wb-pane:not([hidden])");
    const filled = await pane.locator("table tbody td:not(.mmi-wb-empty-cell)").first().waitFor({ timeout: 8000 }).then(() => true, () => false);
    test.skip(!filled, `the mocks give ${key} no rows`);
    await expect(pane.locator("table thead .mmi-resize-handle").first()).toBeAttached();
    // Tables the shared sorter (mmi-table-sort.js) sorts are marked a moment after their rows arrive.
    await expect.poll(async () => (await looks(page)).flatMap((t) => t.unsorted), { timeout: 5000 }).toEqual([]);
    for (const t of await looks(page)) {
      expect(t.pads, `${t.id} heading padding`).toEqual(["8px 10px"]);
      expect(t.heights.length, `${t.id} heading heights ${t.heights.join(",")}`).toBe(1);
      expect(t.unsorted, `${t.id} headings that don't sort`).toEqual([]);
      expect(t.handles, `${t.id} resize handles`).toBe(t.heads);
      expect(t.over, `${t.id} runs past its box`).toBeLessThanOrEqual(0);
    }
  });
}
