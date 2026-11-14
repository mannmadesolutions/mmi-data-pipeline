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
  /** Fields a person said its page has none of (SiteGaps::decide_none()). */
  none?: string[];
  req?: string;
}

export interface FillPlan { confirm: boolean; products: number; fields: number; per: Record<string, number> | []; waiting: number; why: string; scrape: number; pictures: number }

export interface Gaps {
  host: string; status: MapStatus; products: number; ready: { read: number; blocked: number; unread: number; broken: number };
  pending: number; rows: GapRow[]; totals: Record<string, Record<string, number>>; fill: FillPlan; at: string;
  /** The map's parts as saved, by part (pictures, requirements…): its element and whether it stopped matching. PHP's empty list is []. */
  parts?: Record<string, { sel: string; mode: string; broken: boolean }> | [];
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

/* ── Pictures in the Fill review (PictureMatch::merge(), PictureChoices) ── */

/** One picture as a product's merge has it: kept, a sharper copy, new, a duplicate, already there, over the cap, vetoed. */
export interface PicItem { url: string; src?: string; kind: string; att?: number; key?: string; choice?: number; doubt?: boolean }
export interface PicMerge { final: PicItem[]; page: PicItem[]; featured: number; counts: Record<string, number>; summary: string }
export interface ProductPlan { id: number; title: string; fields: { pictures?: { merge?: PicMerge; why?: string; action?: string } } }

export const PIC_WORDS: Record<string, [string, string]> = {
  kept: ["Kept", "same"], sharper: ["Sharper copy", "up"], new: ["New", "up"], duplicate: ["Duplicate", "warn"],
  have: ["Has it", "same"], over_cap: ["Over the cap", "warn"], unreadable: ["Kept", "same"], vetoed: ["Vetoed", "warn"],
};

/** The pictures the product ends up with, in order (the first is featured), then the page's pictures a person vetoed. */
export function tilesOf(m: PicMerge | undefined): PicItem[] {
  if (!m) return [];
  const seen = new Set(m.final.map((p) => p.key || p.url));
  return [...m.final, ...m.page.filter((p) => p.kind === "vetoed" && !seen.has(p.key || p.url))];
}

/** POST /pictures/choices: veto or feature one picture on this product, or veto it on every product of the site. */
export function choiceBody(kind: "veto" | "featured", scope: "product" | "site", host: string, product: number, p: { att?: number; url?: string }): Record<string, unknown> {
  return { kind, scope, host, product, att: p.att ?? 0, url: p.att ? "" : p.url ?? "" };
}

/** Every distinct picture across the reviewed products (GET /maps/{host}/pictures), grouped by fingerprint. */
export interface PicGroup { key: string; thumb: string; att: number; url: string; products: number[]; kinds: Record<string, number>; veto: number; featured: number }
export interface SitePictures { groups: PicGroup[]; products: Record<string, string[]>; next: number | null; of: number }

/** What happens to a picture across products, in words ("New on 3, kept on 1"). */
export const kindsText = (k: Record<string, number>): string =>
  Object.entries(k).filter(([, n]) => n > 0).map(([kind, n]) => `${(PIC_WORDS[kind]?.[0] ?? kind).toLowerCase()} on ${n}`).join(", ").replace(/^./, (c) => c.toUpperCase());

/** What a person can decide on a cell: a requirement to review, a gap its page has none of, or that taken back. */
export type CellAction = "use" | "keep" | "none" | "unnone";
export function cellActions(r: GapRow, field: string): CellAction[] {
  const code = r.cells[field]?.[0] ?? "-";
  if ((r.none ?? []).includes(field)) return ["unnone"];
  if (code === "R" && field === "os") return ["use", "keep"];
  if (code === "G" || code === "S") return ["none"];
  return [];
}
export const CELL_ACTION_LABEL: Record<CellAction, string> = {
  use: "Use the page's requirements", keep: "Keep ours", none: "Its page has none of this", unnone: "Take back \"its page has none\"",
};

/** Text the reader leaves out on a site (GET /maps/{host}/text): lines and, after a scan, suggestions. */
export interface TextSkips { skips: string[]; scan: { repeated?: { text: string; pages: number }[]; pages?: number; checked?: number } | null }

/* ── Proposed maps (MapProposals, /map-proposals): a part found on the site's pages, approved by a person ── */
export interface Proposal {
  id: number; host: string; field: string; layout: number; scope: string; sel: string; mode: string; found: number; of: number;
  note: string; checked: string; status: "proposed" | "approved" | "rejected" | string;
  /** The reviewer's verdict (MapProposals::VERDICTS), "" when no reviewer has looked; older servers leave it out. */
  verdict?: string;
  samples: { id: number; title: string; url: string; short: string; urls?: string[] }[];
}
export const PART_LABEL: Record<string, string> = {
  pictures: "Pictures", description: "Description", features: "Features", requirements: "System requirements", version: "Version",
  manual: "Manual", videos: "Videos", audio: "Audio", disclaimer: "Disclaimer",
};
export const proposalHosts = (rows: Proposal[]): Set<string> => new Set(rows.filter((p) => p.status === "proposed").map((p) => p.host));

/* ── Proposed parts by verdict (owner 2026-11-14, Makers step 3): sorted by how closely to look ── */

export type Verdict = "careful" | "changed" | "added" | "checked" | "";
export interface VerdictDef { key: Verdict; label: string; hint: string; badge: string; bulk: boolean }
export const VERDICTS: VerdictDef[] = [
  { key: "careful", label: "Careful: look before approving", hint: "The reviewer found it can read the wrong thing on some pages", badge: "warning", bulk: false },
  { key: "changed", label: "Changed: the reviewer replaced the finder's element", hint: "Read each line before approving", badge: "info", bulk: true },
  { key: "added", label: "Added: a part the finder missed", hint: "The reviewer's own pick, tested on the stored pages", badge: "info", bulk: true },
  { key: "checked", label: "Checked: the reviewer agrees with the finder", hint: "Safe to approve together", badge: "success", bulk: true },
  { key: "", label: "Not reviewed yet", hint: "Found on the stored pages; no reviewer has looked yet", badge: "", bulk: false },
];
const VERDICT_RE = /^\s*(careful|changed|added|checked)\s*:\s*/i;
/** A proposal's verdict: the saved one, else (an older server) the reviewed note's first word. */
export function verdictOf(p: Proposal): Verdict {
  const v = (p.verdict ?? "").toLowerCase();
  if (VERDICTS.some((d) => d.key === v && v)) return v as Verdict;
  const m = p.checked ? p.note.match(VERDICT_RE) : null;
  return m ? (m[1]!.toLowerCase() as Verdict) : "";
}
/** A review note without its verdict word (the batch already says it). */
export const noteBody = (note: string): string => note.replace(VERDICT_RE, "");
const FIELD_ORDER = ["pictures", "description", "features", "requirements", "version", "manual", "videos", "audio", "disclaimer"];
const byLayoutThenPart = (a: Proposal, b: Proposal) => a.layout - b.layout || FIELD_ORDER.indexOf(a.field) - FIELD_ORDER.indexOf(b.field);
/** The waiting proposals as batches, Careful first; empty batches left out. */
export function verdictBatches(rows: Proposal[]): { v: VerdictDef; rows: Proposal[] }[] {
  const waiting = rows.filter((p) => p.status === "proposed");
  return VERDICTS.map((v) => ({ v, rows: waiting.filter((p) => verdictOf(p) === v.key).sort(byLayoutThenPart) })).filter((b) => b.rows.length > 0);
}
/** The grid's columns (the layouts the waiting proposals are for, the whole site first) and rows (their parts). */
export function proposalGrid(rows: Proposal[]): { layouts: { layout: number; label: string; pages: number }[]; parts: string[]; at: (field: string, layout: number) => Proposal | null } {
  const waiting = rows.filter((p) => p.status === "proposed");
  const layouts = [...new Set(waiting.map((p) => p.layout))].sort((a, b) => a - b)
    .map((l) => ({ layout: l, label: layoutName({ scope: l < 0 ? "site" : "layout", layout: l }), pages: Math.max(0, ...waiting.filter((p) => p.layout === l).map((p) => p.of)) }));
  const parts = [...new Set(waiting.map((p) => p.field))].sort((a, b) => FIELD_ORDER.indexOf(a) - FIELD_ORDER.indexOf(b));
  return { layouts, parts, at: (f, l) => waiting.find((p) => p.field === f && p.layout === l) ?? null };
}

/* ── The open site, part by part (owner 2026-11-14: the site list beside the open site, one row per part) ── */

/** A map part → its column in the gaps (SiteGaps::FIELDS): pictures are images, requirements are os. */
const GAP_OF_PART: Record<string, string> = { pictures: "images", requirements: "os" };
export const gapKey = (part: string): string => GAP_OF_PART[part] ?? part;
/** A gaps column → the map part that writes it ("" where no part does: SiteGaps::FIELDS). */
const PART_OF_GAP: Record<string, string> = { images: "pictures", description: "description", os: "requirements", manual: "manual", videos: "videos" };

/** A part's products, grouped the way its bar shows them: the cell codes each slice stands for. */
export const SLICES: { key: string; codes: string[]; label: string }[] = [
  { key: "add", codes: ["A", "Q"], label: "Page adds" },
  { key: "has", codes: ["H", "K"], label: "Has it" },
  { key: "part", codes: ["P", "S"], label: "Needs a part" },
  { key: "review", codes: ["R"], label: "To review" },
  { key: "gap", codes: ["G", "N"], label: "Gap left" },
  { key: "none", codes: [], label: "Page has none" }, // a person said so (GapRow.none): kept in view, so it can be taken back
  { key: "check", codes: ["C", "U"], label: "Being checked" },
  { key: "page", codes: ["X", "B"], label: "Page blocked or gone" },
  { key: "lock", codes: ["L"], label: "Locked" },
];
const SLICE_OF: Record<string, string> = Object.fromEntries(SLICES.flatMap((s) => s.codes.map((c) => [c, s.key])));
export const sliceOf = (code: string): string => SLICE_OF[code] ?? "";
/** A product's slice for a part: "none" where a person said its page has none of it, else its cell's. */
export const sliceAt = (r: GapRow, field: string): string => ((r.none ?? []).includes(field) ? "none" : sliceOf(r.cells[field]?.[0] ?? "-"));

export interface Slice { key: string; label: string; n: number }
/** What a part does across the products: products per slice, and how many it applies to ("–" cells left out). */
export interface Tally { slices: Slice[]; applies: number }
export function tally(rows: GapRow[], field: string): Tally {
  const n: Record<string, number> = {};
  for (const r of rows) {
    const s = sliceAt(r, field);
    if (s) n[s] = (n[s] ?? 0) + 1;
  }
  const slices = SLICES.map((s) => ({ key: s.key, label: s.label, n: n[s.key] ?? 0 }));
  return { slices, applies: slices.reduce((a, s) => a + s.n, 0) };
}
export const tallyText = (t: Tally): string => t.slices.filter((s) => s.n > 0).map((s) => `${s.label} ${s.n}`).join(" · ");
export const sliceN = (t: Tally, key: string): number => t.slices.find((s) => s.key === key)?.n ?? 0;

/** One row of the Parts tab: a part, what the map reads it with now, what it does across the products, its proposals. */
export interface PartRow { field: string; label: string; sel: string; broken: boolean; proposals: Proposal[]; tally: Tally }
export function partRows(g: Gaps, rows: GapRow[], proposals: Proposal[]): PartRow[] {
  const saved = Array.isArray(g.parts) ? {} : (g.parts ?? {});
  const waiting = proposals.filter((p) => p.status === "proposed");
  const out: PartRow[] = FIELDS.map((f) => {
    const part = saved[PART_OF_GAP[f.key] ?? ""];
    return { field: f.key, label: f.label, sel: part?.sel ?? "", broken: !!part?.broken, proposals: waiting.filter((p) => gapKey(p.field) === f.key), tally: tally(rows, f.key) };
  }).filter((p) => p.tally.applies > 0 || p.proposals.length > 0 || p.sel !== "");
  // A proposal for a part the gaps don't measure (a disclaimer) still gets its row.
  const known = new Set(FIELDS.map((f) => f.key));
  for (const p of waiting.filter((x) => !known.has(gapKey(x.field)))) {
    const row = out.find((r) => r.field === p.field);
    if (row) row.proposals.push(p);
    else out.push({ field: p.field, label: PART_LABEL[p.field] ?? p.field, sel: "", broken: false, proposals: [p], tally: { slices: [], applies: 0 } });
  }
  return out;
}

export type PartSort = "part" | "map" | "adds";
/** The Parts tab's order: as the gaps list them, or by a heading (map: proposed, then saved, then none; adds: most first). */
export function sortParts(rows: PartRow[], sort: { col: PartSort; desc: boolean } | null): PartRow[] {
  if (!sort) return rows;
  const mapRank = (r: PartRow) => (r.proposals.length ? 2 : r.sel ? 1 : 0);
  const key = (r: PartRow): number | string => (sort.col === "part" ? r.label.toLowerCase() : sort.col === "map" ? mapRank(r) : sliceN(r.tally, "add"));
  return [...rows].sort((a, b) => {
    const x = key(a), y = key(b);
    return (x < y ? -1 : x > y ? 1 : 0) * (sort.desc ? -1 : 1) || a.label.localeCompare(b.label);
  });
}

export type DrillSort = "id" | "title" | "state";
/** A part's products: those it applies to, one slice or all, in the bar's order (then title) unless a heading sorts them. */
export function drillRows(rows: GapRow[], field: string, slice: string, sort: { col: DrillSort; desc: boolean } | null): GapRow[] {
  const rank = (r: GapRow) => SLICES.findIndex((s) => s.key === sliceAt(r, field));
  const list = rows.filter((r) => rank(r) >= 0 && (!slice || sliceAt(r, field) === slice));
  const by = sort?.col ?? "state";
  const dir = sort?.desc ? -1 : 1;
  return list.sort((a, b) => (by === "id" ? a.id - b.id : by === "title" ? a.title.localeCompare(b.title) : rank(a) - rank(b) || a.title.localeCompare(b.title)) * dir);
}

/** Products whose title has the words typed (the Parts tab's finder narrows every bar to them). */
export function findProducts(rows: GapRow[], q: string): GapRow[] {
  const s = q.trim().toLowerCase();
  return s ? rows.filter((r) => r.title.toLowerCase().includes(s) || String(r.id) === s) : rows;
}

/** A proposal's place: the whole site, or one page layout (lettered as the extension and the CLI letter them). */
export const layoutName = (p: Pick<Proposal, "scope" | "layout">): string =>
  p.scope === "site" || p.layout < 0 ? "Whole site" : `Layout ${p.layout < 26 ? String.fromCharCode(65 + p.layout) : p.layout + 1}`;

/** A reviewer's note as one line and the rest: its first sentence shows, the rest opens on a click. */
export function noteParts(note: string): [string, string] {
  const m = note.match(/^(.+?[.!?])\s+(\S[\s\S]*)$/);
  return m ? [m[1]!, m[2]!] : [note, ""];
}

/** What a waiting proposal means for a Fill, said beside the Fill (Fill reads with the map as it is). */
export function partsHint(waiting: number): string {
  if (!waiting) return "";
  return `${waiting} proposed part${waiting === 1 ? " waits" : "s wait"} under Parts. A Fill reads with the map as it is now, so a proposed part writes nothing until it is approved.`;
}

export type RailSort = "site" | "gaps";
/** The site list's order: the view's own, or by a heading. */
export function sortSites(rows: SiteRow[], sort: { col: RailSort; desc: boolean } | null): SiteRow[] {
  if (!sort) return rows;
  const dir = sort.desc ? -1 : 1;
  // Ties stay in A–Z order whichever way the column runs.
  return [...rows].sort((a, b) => (sort.col === "site" ? a.host.localeCompare(b.host) * dir : (gainOf(a) - gainOf(b)) * dir || a.host.localeCompare(b.host)));
}

/* ── Map parts under Needs a decision (owner 2026-11-14): every site's waiting parts, one row each ── */

export type QueueSort = "id" | "site" | "part" | "found" | "reads";
/** Waiting parts, one site and one part or all, by site then the parts' own order unless a heading sorts them. */
export function waitingParts(rows: Proposal[], host: string, part: string, sort: { col: QueueSort; desc: boolean } | null): Proposal[] {
  const list = rows.filter((p) => p.status === "proposed" && (!host || p.host === host) && (!part || p.field === part));
  if (!sort) return list;
  const share = (p: Proposal) => (p.of ? p.found / p.of : 0);
  const reads = (p: Proposal) => p.samples[0]?.short ?? "";
  const by: Record<QueueSort, (a: Proposal, b: Proposal) => number> = {
    id: (a, b) => a.id - b.id,
    site: (a, b) => a.host.localeCompare(b.host),
    part: (a, b) => (PART_LABEL[a.field] ?? a.field).localeCompare(PART_LABEL[b.field] ?? b.field),
    found: (a, b) => share(a) - share(b),
    reads: (a, b) => reads(a).localeCompare(reads(b)),
  };
  return [...list].sort((a, b) => by[sort.col](a, b) * (sort.desc ? -1 : 1) || a.id - b.id);
}
/** Each site with parts waiting, and how many: the site menu's options. */
export function waitingSites(rows: Proposal[]): [string, number][] {
  const n = new Map<string, number>();
  for (const p of rows) if (p.status === "proposed") n.set(p.host, (n.get(p.host) ?? 0) + 1);
  return [...n.entries()].sort((a, b) => a[0].localeCompare(b[0]));
}
