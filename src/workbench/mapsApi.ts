/**
 * Site maps (Product Data's VendorMaps, SiteGaps, FillRun; mmpt/v1/maps): where each maker site keeps pictures and
 * text, what one Fill would write from its pages, and what the last Fills wrote. Types as the routes return them,
 * the cell legend, the site list's filters, and the Fill button's words.
 */

export type MapStatus = "confirmed" | "draft" | "auto" | "refused" | "needs" | "off" | string;

export interface SiteRow {
  host: string;
  status: MapStatus;
  pattern: string;
  render: number;
  stats: { products: number; pages: number; read: number; refused: number; blocked: number; at: string };
  gaps: { products: number; gain: number; fields: number; proposals: number; pending: number; at: string } | [];
  map_at: string;
  checked: string;
}

export interface MapsReply { rows: SiteRow[]; runner: { running: boolean; waiting: number; note: string; finished_at: string } }

/** A cell: [code, from, now, why, n]. */
export type Cell = [string, string, string, string, number?];

export interface GapRow {
  id: number; title: string; url: string; edit: string; page: "read" | "blocked" | "unread" | "broken" | string; status: string; note: string;
  cells: Record<string, Cell>;
}

export interface FillPlan { confirm: boolean; products: number; fields: number; per: Record<string, number> | []; waiting: number; why: string; scrape: number; pictures: number }

export interface Gaps {
  host: string; status: MapStatus; products: number; ready: { read: number; blocked: number; unread: number; broken: number };
  pending: number; rows: GapRow[]; totals: Record<string, Record<string, number>>; fill: FillPlan; at: string;
}

export interface FillItem { id: number; title: string; edit: string; state: string; wrote: string[]; pictures: string; undoable: boolean }
export interface FillRunRow { run: string; at: string; items: FillItem[] }

export interface Reading { products: number; stored: number; waiting: number; working: number; done: number; read: number; failed: number; running: boolean; busy: boolean }

export const STATUS: Record<string, [string, string]> = {
  confirmed: ["Map confirmed", "success"],
  draft: ["Draft map", "info"],
  auto: ["Reads fine", "success"],
  refused: ["Refuses the server", "warning"],
  needs: ["Needs a map", "warning"],
  off: ["Map off", ""],
  broken: ["Map stopped matching", "error"],
};

/** The matrix's columns: SiteGaps' fields, as people say them. */
export const FIELDS: { key: string; label: string }[] = [
  { key: "images", label: "Pictures" }, { key: "description", label: "Description" }, { key: "features", label: "Features" },
  { key: "os", label: "Requirements" }, { key: "version", label: "Version" }, { key: "manual", label: "Manual" },
  { key: "videos", label: "Videos" }, { key: "audio", label: "Audio" }, { key: "size", label: "Size" },
];

/** SiteGaps' cell codes: [glyph, label, meaning]. */
export const CODES: Record<string, [string, string, string]> = {
  H: ["✓", "Has it", "The product already has it"],
  K: ["≈", "Has some", "The product has some; filling only writes empty fields"],
  A: ["+", "Page adds", "Filling writes it from the maker's page"],
  Q: ["↻", "Queued", "A fill queued it: the picture runner writes it"],
  P: ["◎", "Needs a part", "The page has it, but only a mapped part writes it"],
  S: ["◌", "Nothing reads it", "The page shows it, but nothing reads it: pick it on the page in the Chrome extension"],
  R: ["?", "To review", "The page differs from what the product has"],
  G: ["✗", "Gap left", "The page doesn't have it"],
  N: ["○", "No place yet", "The page has it, but nothing stores this yet"],
  L: ["■", "Locked", "Locked: neither imports nor maps write it"],
  C: ["…", "Being checked", "Its page is being read with the map as it is now"],
  X: ["⊘", "Blocked", "The site sent a bot check instead of the page"],
  U: ["…", "Not read", "The page hasn't been read (or the last read failed)"],
  B: ["!", "Page broken", "The page is gone or moved: a URL decision"],
  "-": ["–", "Doesn't apply", "Doesn't apply to this product"],
};
export const LEGEND = ["H", "A", "P", "S", "G", "L", "B"];

export type SiteView = "gain" | "confirmed" | "draft" | "auto" | "needs" | "refused" | "off" | "all";
export const SITE_VIEWS: { value: SiteView; label: string; hint: string }[] = [
  { value: "gain", label: "Gaps to fill", hint: "Sites whose pages have something their products lack (as last measured)" },
  { value: "confirmed", label: "Confirmed", hint: "" },
  { value: "draft", label: "Draft maps", hint: "A map drafted from the site's pages, waiting for a person's sign-off" },
  { value: "auto", label: "Read without a map", hint: "The reader finds what it needs without a map" },
  { value: "needs", label: "Needs a map", hint: "" },
  { value: "refused", label: "Refuses the server", hint: "The site answers this server with a bot check: its pages come from the owner's browser" },
  { value: "off", label: "Map off", hint: "" },
  { value: "all", label: "Every site", hint: "" },
];

const gapsOf = (r: SiteRow) => (Array.isArray(r.gaps) ? null : r.gaps);
export const gainOf = (r: SiteRow): number => gapsOf(r)?.fields ?? 0;
export const gainProducts = (r: SiteRow): number => gapsOf(r)?.gain ?? 0;

export function sitesIn(rows: SiteRow[], view: SiteView, q: string): SiteRow[] {
  const s = q.trim().toLowerCase();
  return rows
    .filter((r) => (!s || r.host.includes(s)) && (view === "all" ? true : view === "gain" ? gainOf(r) > 0 : r.status === view))
    .sort((a, b) => (view === "gain" ? gainOf(b) - gainOf(a) : 0) || a.host.localeCompare(b.host));
}
export const siteViewCount = (rows: SiteRow[], view: SiteView): number => sitesIn(rows, view, "").length;

/** The products one Fill writes to: those with a field their page adds. */
export const fillRows = (g: Gaps): GapRow[] => g.rows.filter((r) => Object.values(r.cells).some((c) => c[0] === "A"));
export const adds = (r: GapRow): string[] => FIELDS.filter((f) => r.cells[f.key]?.[0] === "A").map((f) => f.label);

/** The Fill button's words: confirming a draft first when the plan says so. */
export function fillLabel(p: FillPlan): string {
  const what = `${p.fields} gap${p.fields === 1 ? "" : "s"} on ${p.products} product${p.products === 1 ? "" : "s"}`;
  return p.confirm ? `Confirm map & fill ${what}` : `Fill ${what}`;
}

/** What POST /maps/{host}/fill answered, in words. */
export function fillDone(d: { confirmed: boolean; scrape: number; pictures: number }): string {
  const parts = [d.scrape ? `${d.scrape} product${d.scrape === 1 ? "" : "s"} queued to read and write` : "", d.pictures ? `${d.pictures} queued for pictures` : ""].filter(Boolean);
  return `${d.confirmed ? "Map confirmed. " : ""}${parts.join("; ") || "Nothing queued"}. What it wrote shows under Last fills, with Undo.`;
}

/** Products the readiness line can act on, by page state. */
export const idsWith = (g: Gaps, page: string): number[] => g.rows.filter((r) => r.page === page).map((r) => r.id);
