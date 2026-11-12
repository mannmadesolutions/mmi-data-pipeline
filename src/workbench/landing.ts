/**
 * Where a link lands in the Workbench: the address's wb_* parameters, set by
 * links from other screens (mmi-admin's OnePage::url(), mmi-google-services,
 * the Chrome extension, and the old Product Data page's address, which now
 * redirects here):
 *   wb_sec     the menu entry to open (a key of SECTION_IDS, or an ALIASES key)
 *   wb_q       Find products starts with this search (a product ID or words)
 *   wb_brand   Brands & sites opens this brand (term ID)
 *   wb_site    Site maps opens this maker site (host)
 *   wb_bundle  the Bundles panel shows this bundle (a store's own panel reads it)
 *
 * A link without wb_sec opens the section its other parameter belongs to
 * (wb_brand → Brands & sites, …). An older link's #section-id hash (e.g.
 * #mmi-wb-rules from the rule-efficacy alert) opens that section too.
 */
export interface Landing { sec: string; q: string; brand: number; site: string; bundle: number }

/** Menu key → the section's element id (its Section id, unchanged from the stacked page). */
export const SECTION_IDS: Record<string, string> = {
  data: "mmi-wb-results-section",
  standing: "mmi-wb-rules",
  upkeep: "mmi-wb-upkeep",
  changes: "mmi-wb-history",
  titles: "mmi-wb-proposals",
  descriptions: "mmi-wb-enrich",
  picks: "mmi-wb-enrich",
  pictures: "mmi-wb-pictures",
  pages: "mmi-wb-maker-pages",
  sites: "mmi-wb-brand-sites",
  maps: "mmi-wb-site-maps",
  rules: "mmi-wb-title-rules",
  left: "mmi-wb-left-alone",
  writing: "mmi-wb-pd-runs",
  bundles: "mmi-wb-panel-bundles",
  advisories: "mmi-wb-panel-advisories",
  "part-numbers": "mmi-wb-panel-part-numbers",
};

/** Keys links already use that now name another entry. */
export const ALIASES: Record<string, string> = {
  proposals: "descriptions", // "Description and picture proposals" was one section
  parts: "part-numbers",
  history: "changes",
};

/** A wb_sec value (or alias) → its menu key, or "". */
export function sectionKey(raw: string): string {
  const k = ALIASES[raw] ?? raw;
  return k in SECTION_IDS ? k : "";
}

/** An element id from an older link's hash → the menu key showing it. */
function keyForHash(hash: string): string {
  const id = hash.replace(/^#/, "");
  if (!id) return "";
  if (id === "mmi-wb-find") return "data";
  return Object.keys(SECTION_IDS).find((k) => SECTION_IDS[k] === id) ?? "";
}

export function readLanding(search: string, hash = ""): Landing {
  const p = new URLSearchParams(search);
  const int = (k: string) => { const n = Number(p.get(k) ?? ""); return Number.isInteger(n) && n > 0 ? n : 0; };
  const l: Landing = {
    sec: sectionKey(p.get("wb_sec") ?? ""),
    q: (p.get("wb_q") ?? "").trim().slice(0, 200),
    brand: int("wb_brand"),
    site: (p.get("wb_site") ?? "").trim().toLowerCase().replace(/^https?:\/\//, "").replace(/\/.*$/, "").slice(0, 120),
    bundle: int("wb_bundle"),
  };
  if (!l.sec) l.sec = keyForHash(hash) || (l.brand ? "sites" : l.site ? "maps" : l.bundle ? "bundles" : l.q ? "data" : "");
  return l;
}

let cached: Landing | null = null;
/** This page's landing, read once. */
export function landing(): Landing {
  if (!cached) cached = readLanding(typeof window === "undefined" ? "" : window.location.search, typeof window === "undefined" ? "" : window.location.hash);
  return cached;
}
