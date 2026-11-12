/** The Workbench's side menu (Shell.tsx) in browser tests: one section on screen at a time. */
import { expect, type Page } from "@playwright/test";

/** Open a section from the side menu by its key (landing.ts SECTION_IDS) and wait until it is the one shown. */
export async function openSection(page: Page, key: string): Promise<void> {
  const item = page.locator(`#mmi-wb-menu [data-sec="${key}"]`);
  await item.click();
  await expect(item).toHaveAttribute("aria-current", "page");
}
