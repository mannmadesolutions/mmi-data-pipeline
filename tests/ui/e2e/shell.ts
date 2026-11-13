/** The Workbench's side menu (Shell.tsx) in browser tests: one section on screen at a time. */
import { expect, type Page } from "@playwright/test";

/** The review queues are Needs a decision's pills (2026-11-13): their old menu keys → the pill. */
const QUEUE_PILLS: Record<string, string> = { titles: "titles", descriptions: "descriptions", picks: "pictures", pages: "pages" };

/**
 * Open a section from the side menu by its key (landing.ts SECTION_IDS) and wait until it is the one shown. A review
 * queue's key opens Needs a decision and chooses the queue's pill.
 */
export async function openSection(page: Page, key: string): Promise<void> {
  const pill = QUEUE_PILLS[key];
  const item = page.locator(`#mmi-wb-menu [data-sec="${pill ? "decide" : key}"]`);
  await item.click();
  await expect(item).toHaveAttribute("aria-current", "page");
  if (pill) {
    const button = page.locator(`#mmi-wb-decide .mmi-wb-dc-pills [data-kind="${pill}"]`);
    await button.click();
    await expect(button).toHaveAttribute("aria-pressed", "true");
  }
}
