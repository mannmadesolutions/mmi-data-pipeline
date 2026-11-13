/**
 * Moving between the Workbench's sections (the side menu, Shell.tsx).
 *
 * Any part of the page opens a section by its menu key with openSection();
 * the shell listens for OPEN_EVENT. A section asks useSectionVisible() whether
 * it is the one on screen, to pause its pollers while hidden.
 */
import { createContext, useContext } from "react";
import { QUEUE_PILLS } from "./landing";

export const OPEN_EVENT = "mmi-wb:open-section";
/** Needs a decision's pill to show (detail: the kind), sent with the section's opening. */
export const PILL_EVENT = "mmi-wb:open-pill";

export interface OpenRequest {
  key: string;
  /** Replace the address's entry instead of adding one (a section following its own pill, not a person's step). */
  replace?: boolean;
}

/** Open a section by its menu key (landing.ts SECTION_IDS); a review queue's key opens Needs a decision on its pill. */
export function openSection(key: string, replace = false): void {
  const pill = QUEUE_PILLS[key];
  if (pill) openPill(pill, replace);
  else window.dispatchEvent(new CustomEvent<OpenRequest>(OPEN_EVENT, { detail: { key, replace } }));
}

/** Open Needs a decision on a pill: a queue (titles, descriptions, pictures, pages) or key:{waiting key}. */
export function openPill(kind: string, replace = false): void {
  window.dispatchEvent(new CustomEvent<string>(PILL_EVENT, { detail: kind }));
  window.dispatchEvent(new CustomEvent<OpenRequest>(OPEN_EVENT, { detail: { key: "decide", replace } }));
}

/** A section shown inside another (the review queues inside Needs a decision): its Section draws no card or title. */
export const SectionEmbedContext = createContext(false);

export const COUNTS_EVENT = "mmi-wb:counts-stale";

/** Something changed what the menu's badges count: the shell asks again (debounced). */
export function refreshCounts(): void {
  window.dispatchEvent(new Event(COUNTS_EVENT));
}

/** True for the section on screen. Outside a shell pane (a test rendering one section) it is always true. */
export const SectionVisibleContext = createContext(true);
export const useSectionVisible = (): boolean => useContext(SectionVisibleContext);
