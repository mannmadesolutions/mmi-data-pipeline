/**
 * Where a link lands in the Workbench: the address's wb_* parameters, set by
 * links from other screens (mmi-admin's OnePage::url(), and the old Product
 * Data page's address, which now redirects here):
 *   wb_sec     the section to scroll to (SECTION_IDS)
 *   wb_q       Find products starts with this search (a product ID or words)
 *   wb_brand   Brands & sites opens this brand (term ID)
 *   wb_site    Site maps opens this maker site (host)
 *   wb_bundle  the Bundles panel shows this bundle (a store's own panel reads it)
 */
export interface Landing { sec: string; q: string; brand: number; site: string; bundle: number }

export const SECTION_IDS: Record<string, string> = {
  data: "mmi-wb-results-section",
  titles: "mmi-wb-proposals",
  proposals: "mmi-wb-enrich",
  pictures: "mmi-wb-pictures",
  pages: "mmi-wb-maker-pages",
  sites: "mmi-wb-brand-sites",
  maps: "mmi-wb-site-maps",
  rules: "mmi-wb-title-rules",
  left: "mmi-wb-left-alone",
  writing: "mmi-wb-pd-runs",
  bundles: "mmi-wb-panel-bundles",
  advisories: "mmi-wb-panel-advisories",
  parts: "mmi-wb-panel-part-numbers",
};

export function readLanding(search: string): Landing {
  const p = new URLSearchParams(search);
  const int = (k: string) => { const n = Number(p.get(k) ?? ""); return Number.isInteger(n) && n > 0 ? n : 0; };
  const sec = p.get("wb_sec") ?? "";
  return {
    sec: sec in SECTION_IDS ? sec : "",
    q: (p.get("wb_q") ?? "").trim().slice(0, 200),
    brand: int("wb_brand"),
    site: (p.get("wb_site") ?? "").trim().toLowerCase().replace(/^https?:\/\//, "").replace(/\/.*$/, "").slice(0, 120),
    bundle: int("wb_bundle"),
  };
}

let cached: Landing | null = null;
/** This page's landing, read once. */
export function landing(): Landing {
  if (!cached) cached = readLanding(typeof window === "undefined" ? "" : window.location.search);
  return cached;
}

/** Scroll to the landing section once it is drawn (sections arrive as their data loads); gives up after 20 s. */
export function scrollToLanding(l: Landing = landing()): () => void {
  const id = SECTION_IDS[l.sec];
  if (!id) return () => {};
  let tries = 0;
  const t = window.setInterval(() => {
    const el = document.getElementById(id);
    if (el || ++tries > 80) {
      window.clearInterval(t);
      el?.scrollIntoView({ block: "start" });
    }
  }, 250);
  return () => window.clearInterval(t);
}
