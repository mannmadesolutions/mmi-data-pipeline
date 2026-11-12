/**
 * Description and picture proposals (Product Data's Enrichment) for the
 * Workbench: row shapes as GET mmpt/v1/enrich returns them, the views and
 * sources from GET enrich/summary, the picture pick (first = featured), and
 * which actions a row offers in each status.
 */

export type EnrichKind = "content" | "image";
export type EnrichStatus = "proposed" | "accepted" | "applied" | "rejected" | "reverted" | "stale" | "error";

export interface EnrichRow {
  id: number;
  kind: EnrichKind;
  status: EnrichStatus;
  source: string;
  source_url: string;
  title: string;
  /** Plain text of the current description (excerpt), or the current pictures as JSON for images. */
  current: string;
  proposed: string;
  chars: number;
  /** Picture candidates: image URLs. */
  images: string[];
  /** The product's own pictures: tokens "att:<id>" with a URL. */
  own: { token: string; url: string }[];
  /** The pick, in order: the first is the featured picture. URLs or own tokens. */
  chosen: string[];
  waiting: boolean;
  low_res: string[];
  /** Pictures read from a maker page: its site, the site map's status, and whether that map has a Pictures part. */
  site?: { host: string; map: string; pictures: boolean } | null;
  edit_url: string;
  view_url: string;
}

export interface EnrichPage { rows: EnrichRow[]; total: number; pages: number }

export interface EnrichSummary {
  /** "kind:source" → status → count */
  counts: Record<string, Record<string, number>>;
  rules: Record<string, { label: string; approved: boolean; retired?: boolean }>;
}

export interface EnrichFilter { kind: EnrichKind; status: string; source: string; has_images: "" | "yes" | "no"; page: number }

export const EMPTY_ENRICH: EnrichFilter = { kind: "content", status: "proposed", source: "", has_images: "", page: 1 };

export const ENRICH_VIEWS: { value: string; label: string }[] = [
  { value: "proposed", label: "To review" },
  { value: "accepted", label: "Waiting to be written" },
  { value: "applied", label: "Written" },
  { value: "stale", label: "Out of date" },
  { value: "rejected", label: "Rejected" },
  { value: "reverted", label: "Put back" },
  { value: "all", label: "All" },
];

export const ENRICH_STATUS: Record<EnrichStatus, [string, string]> = {
  proposed: ["Proposed", "info"],
  accepted: ["Accepted", "success"],
  applied: ["Written", "success"],
  rejected: ["Rejected", ""],
  reverted: ["Put back", "warning"],
  stale: ["Out of date", "warning"],
  error: ["Write failed", "error"],
};

/** A count for one kind, summed over its sources (and over every status for "all"). */
export function enrichCount(s: EnrichSummary, kind: EnrichKind, status: string, source = ""): number {
  let n = 0;
  for (const [key, by] of Object.entries(s.counts)) {
    const [k, src] = key.split(":");
    if (k !== kind || (source && src !== source)) continue;
    n += status === "all" ? Object.values(by).reduce((a, b) => a + b, 0) : by[status] ?? 0;
  }
  return n;
}

/** The sources a kind has, with the rule's label when there is one. */
export function enrichSources(s: EnrichSummary, kind: EnrichKind): { value: string; label: string }[] {
  return Object.keys(s.counts).filter((k) => k.startsWith(`${kind}:`)).map((k) => {
    const src = k.slice(kind.length + 1);
    return { value: src, label: s.rules[k]?.label ?? src.replace(/_/g, " ") };
  });
}

export function enrichQuery(f: EnrichFilter): string {
  const p = new URLSearchParams({ kind: f.kind, status: f.status, page: String(f.page), per_page: "25" });
  if (f.source) p.set("source", f.source);
  if (f.kind === "image" && f.has_images) p.set("has_images", f.has_images);
  return p.toString();
}

export type EnrichAction = "accept" | "reject" | "reset" | "apply" | "revert";

export function enrichActions(status: EnrichStatus): EnrichAction[] {
  switch (status) {
    case "proposed": case "stale": case "error": return ["accept", "reject"];
    case "accepted": return ["apply", "reset"];
    case "rejected": return ["reset"];
    case "applied": return ["revert"];
    default: return [];
  }
}

/** A row's pictures can still be picked. */
export const pickable = (r: EnrichRow): boolean => r.kind === "image" && ["proposed", "accepted", "stale", "error"].includes(r.status);

/**
 * The pick a row shows and builds on. With none saved yet it starts as the old picker did (owner 2026-10-08):
 * the product's own pictures when it has some, so adding a picture never drops them; otherwise the first five
 * candidates. Writing imports exactly this pick, so Accept and Write now save it first when it isn't saved.
 */
export function effectivePick(r: EnrichRow): string[] {
  if (r.chosen.length) return r.chosen;
  return r.own.length ? r.own.map((o) => o.token) : r.images.slice(0, 5);
}

/** Short names for where a proposal comes from (the rule's full sentence shows on hover and in the filter). */
const SOURCE_SHORT: Record<string, string> = {
  vendor_page: "Maker's page",
  web_assets: "Supplier data",
  plugivery: "Supplier data",
  family_copy: "Product family",
  members: "Bundle members",
};
export const sourceShort = (src: string): string => SOURCE_SHORT[src] ?? src.replace(/_/g, " ");

/** Click on a picture: in the pick → out; out → in, at the end (the first stays featured). */
export function togglePick(chosen: string[], token: string): string[] {
  return chosen.includes(token) ? chosen.filter((t) => t !== token) : [...chosen, token];
}

/** Make a picked (or not yet picked) picture the featured one: first in the pick. */
export function makeFeatured(chosen: string[], token: string): string[] {
  return [token, ...chosen.filter((t) => t !== token)];
}

/**
 * A picture dragged onto another: dropped before or after a used one, it is used at that place (the first
 * place is the featured picture); dropped on one left out, it is left out too. Anything else: no change.
 */
export function placePick(chosen: string[], token: string, target: string, side: "before" | "after"): string[] {
  if (token === target) return chosen;
  const rest = chosen.filter((t) => t !== token);
  const at = rest.indexOf(target);
  if (at < 0) return chosen.includes(target) ? chosen : rest; // onto a left-out picture: leave this one out
  const next = [...rest];
  next.splice(side === "before" ? at : at + 1, 0, token);
  return next;
}

/** A used picture moved one place left (-1) or right (+1) in the pick. */
export function nudgePick(chosen: string[], token: string, by: -1 | 1): string[] {
  const i = chosen.indexOf(token);
  const j = i + by;
  if (i < 0 || j < 0 || j >= chosen.length) return chosen;
  const next = [...chosen];
  [next[i], next[j]] = [next[j]!, next[i]!];
  return next;
}

/** The order the tiles are shown in: the pick (featured first), then the pictures left out, as listed. */
export function tileOrder<T extends { token: string }>(tiles: T[], chosen: string[]): T[] {
  const by = new Map(tiles.map((t) => [t.token, t]));
  const used = chosen.map((t) => by.get(t)).filter((t): t is T => !!t);
  return [...used, ...tiles.filter((t) => !chosen.includes(t.token))];
}

/** Picture size in the picker, in pixels: the person's choice, kept in this browser. */
export const PIC_SIZE = { key: "mmi-wb-en-pic-size", min: 72, max: 320, step: 8, fallback: 120 } as const;
export function readPicSize(): number {
  try {
    const n = Number(window.localStorage.getItem(PIC_SIZE.key));
    return n >= PIC_SIZE.min && n <= PIC_SIZE.max ? n : PIC_SIZE.fallback;
  } catch { return PIC_SIZE.fallback; }
}
export function savePicSize(n: number): void {
  try { window.localStorage.setItem(PIC_SIZE.key, String(n)); } catch { /* private window: this visit only */ }
}

/** What write-now answered, in words. */
export function writeResult(r: { result: string; message: string }, kind: EnrichKind): { text: string; ok: boolean } {
  const what = kind === "content" ? "Description" : "Pictures";
  switch (r.result) {
    case "applied": return { text: `${what} written to the product.`, ok: true };
    case "reverted": return { text: `${what} put back as they were.`, ok: true };
    case "waiting": case "later": return { text: r.message, ok: true };
    default: return { text: r.message || "Not written.", ok: false };
  }
}
