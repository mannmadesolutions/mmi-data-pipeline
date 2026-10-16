/** Every user-facing string, with %placeholder% templates (AGENTS.md → Messages & Strings). */

export const MESSAGES = {
  TERMS_LOADING:    "Loading…",
  TERMS_SEARCH:     "Search %label%…",
  TERMS_TITLE:      "%label% for “%title%”",
  TERMS_HINT:       "Saving locks %label% against imports, so the next supplier import keeps your choice. Undo from Recent changes.",
  TERMS_SAVED:      "%label% saved. Undo it from Recent changes.",
  TERMS_NO_CAT:     "Choose at least one category.",
  TERMS_LOCKED:     "Locked against imports",
  BULK_LOCK:        "Each changed product gets %label% locked against imports, so supplier imports keep this change. Undo releases the lock.",
  SKIP_LOCKED:      "Skip products where %label% is locked",
  ADD_LOCK:         "Lock %label% against imports",
  PREVIEW_LOCKED:   "%count% of them %have% %label% locked against imports. They change too, unless you turn on Skip.",
  PREVIEW_SKIPPED:  "%count% with %label% locked against imports will be skipped.",
  ADD_LOCK_OFF:     "Supplier imports may write %label% back over this change. Turn on Lock to keep it.",
  CONFIRM_SKIP:     "Products with %label% locked against imports are skipped.",
  CONFIRM_LOCK:     "Changed products get %label% locked against imports.",
  NOTHING_SELECTED: "Nothing selected yet.",
  TARGET_NONE:      "Nothing ticked. Preview checks all %count% result%s%; Apply to all results changes every one of them.",
  TARGET_IDS:       "%count% selected product%s% will be checked.",
  TARGET_FILTER:    "All %count% product%s% matching the search will be checked%excl%.",
  TARGET_EXCLUDED:  ", except %count% unticked",
  CONFIRM_APPLY:    "%summary%\n\nApply to %count% product%s%? You can undo this from Recent changes.",
  CONFIRM_APPLY_ALL:"%summary%\n\nApply to ALL %count% product%s% the search finds, ticked or not?\n\nSearch: %filter%\n\nYou can undo this from Recent changes.",
  APPLY_ALL_TITLE:  "Apply to every product the search finds, ticked or not",
  TOO_MANY:         "The search finds %count% products; one run can change at most %max%. Narrow the search first.",
  WAITING_VALUE:    "Condition %n% needs a value before the results update.",
  CONFIRM_UNDO:     "Undo \"%summary%\"?\n\nEach product gets its previous value back, unless it has been edited again since.",
  INCOMPLETE:       "Condition %n%: pick a Source and a Field, or remove it.",
  SAVED_SEARCH:     "Saved “%name%”. Load it from Load saved conditions, here or in Catalog Maintenance.",
  NETWORK:          "Network error — please try again.",
  LOGIN_EXPIRED:    "Your login has expired. Reload the page to sign in again; nothing was changed.",
  RUN_SAVED:        "%message% The run is saved — resume it from Recent changes.",
  DONE:             "Done: %changed% changed, %unchanged% already matched%skipped%%failed%.",
  DONE_SKIPPED:     ", %count% skipped (locked against imports)",
  DONE_FAILED:      ", %count% failed (see the sync log)",
  STOPPED:          "Stopped after %cursor% of %total%. Resume it from Recent changes.",
  UNDONE:           "Undone: %undone% restored%conflicts%.",
  UNDONE_CONFLICTS: "; %count% left alone because they were edited again since",
  FOUND:            "%count% product%s% found",
  SELECTED:         "%count% selected",
  SELECT_ALL:       "Select all %count% matching products",
  PROGRESS_APPLY:   "%cursor% of %total% · %changed% changed",
  PROGRESS_UNDO:    "Undoing… %done% of %changed%",
  PREVIEW_SUMMARY:  "%changing% of %selected% would change; %unchanged% already match%skipped%.",
  PREVIEW_SKIP_TAIL:"; %count% skipped",
  PREVIEW_MORE:     "…and %count% more.",
  NO_MATCH:         "No products match.",
  EMPTY:            "Type a search, pick a filter or add a condition to see products here.",
  NO_JOBS:          "No changes yet.",
  JOBS_LOADING:     "Loading…",
  JOB_CHANGED:      "%changed% of %total%",
  JOB_SKIPPED:      ", %count% skipped (locked)",
  JOB_UNDONE:       "%undone% restored",
  JOB_KEPT:         ", %count% kept",
  FACET_TITLE:      "Narrow to products in %name%",
  HEALTH_CHIP:      "%label% — click to show only these",
  CHANGE_TERMS:     "Change %label%",
  SELECT_ROW:       "Select %title%",
  SAVE_SEARCH_NAME: "Name this search…",
} as const;

/** Button contents: an icon and a label, swapped for the spinner while busy. */
export const LABELS = {
  SEARCHING:     "Searching…",
  PREVIEW:       "Preview",
  PREVIEWING:    "Checking…",
  APPLY:         "Apply to selected",
  APPLYING:      "Applying…",
  APPLY_ALL:     "Apply to all %count% results",
  APPLY_ALL_NONE:"Apply to all results",
} as const;

export const STATUS_LABELS: Record<string, string> = { publish: "Published", draft: "Draft", pending: "Pending", private: "Private" };
export const STOCK_LABELS: Record<string, string> = { instock: "In stock", outofstock: "Out of stock", onbackorder: "Backorder" };
export const JOB_STATUS_LABELS: Record<string, string> = {
  running: "Running", done: "Done", stopped: "Stopped", undoing: "Undoing", undone: "Undone",
};
export const SEARCH_IN_LABELS: Record<string, string> = { title_sku: "Title/SKU", title: "Title", sku: "SKU" };
export const HEALTH_MODE_LABELS: Record<string, string> = { any: "Fails any of: ", all: "Fails all of: ", none: "Passes: " };

/** Fills %name% placeholders; unknown ones are left as they are. */
export function fill(template: string, values: Record<string, string | number>): string {
  return template.replace(/%(\w+)%/g, (m, key: string) => (key in values ? String(values[key]) : m));
}

export const plural = (n: number): string => (n === 1 ? "" : "s");
export const num = (n: number): string => Number(n).toLocaleString();
