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
