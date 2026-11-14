/**
 * Brands & sites (Product Data's BrandSites, mmpt/v1/brand-sites): where each brand's maker publishes its
 * product pages, and matching the brand's products to those pages. Types as the routes return them, the
 * brand list's filters, and the rules for which found pages are ticked for the person.
 */
import type { PageRow } from "./pagesApi";

export type SiteRole = "official" | "partner" | "reseller" | "store" | "notbrand" | "unknown";

export interface BrandSite {
  host: string; n: number; read: number; unread: number; failed: number; unverified: number; better: number;
  bundles: number; open: number; role: SiteRole | ""; why: string; saved: boolean; map: string; shared: number;
}

export interface Brand {
  id: number; name: string; website: string; url: string; logo: string; products: number; open: number;
  failed_open: number; no_url: number; no_page: number; sites: BrandSite[]; gone: boolean; bundles: number; bundles_none: number;
  /** A site whose product pages now redirect to another (two or more products). */
  moves?: { from: string; to: string; n: number; role: string }[];
}

export const ROLES: { value: SiteRole; label: string }[] = [
  { value: "official", label: "The maker's own site" },
  { value: "partner", label: "The maker's partner or distributor" },
  { value: "reseller", label: "A reseller" },
  { value: "store", label: "A store or file link" },
  { value: "notbrand", label: "Not this brand" },
  { value: "unknown", label: "Not decided" },
];
/** The maker's own sites: product data is read from these. */
export const MAKER: SiteRole[] = ["official", "partner"];
/** Sites that aren't the maker's: never used; their products need a maker page. */
export const BAD: SiteRole[] = ["reseller", "store", "notbrand"];

export const roleLabel = (r: SiteRole | ""): string => ROLES.find((x) => x.value === (r || "unknown"))?.label ?? r;
export const isMaker = (s: BrandSite): boolean => MAKER.includes(s.role as SiteRole);
export const makerHosts = (b: Brand): string[] => b.sites.filter(isMaker).map((s) => s.host);

/** What a brand's products wait for: a proposed page to decide, a page that's gone, no page at all. */
export const toDo = (b: Brand): number => b.open + b.failed_open + b.no_url;

export type BrandView = "work" | "nomaker" | "gone" | "all";
export const BRAND_VIEWS: { value: BrandView; label: string; hint: string }[] = [
  { value: "work", label: "Needs work", hint: "Brands with products waiting for a maker page" },
  { value: "nomaker", label: "No maker site", hint: "Brands with no site saved as the maker's" },
  { value: "gone", label: "Set aside", hint: "Brands whose maker has no site any more" },
  { value: "all", label: "Every brand", hint: "" },
];

export function brandsIn(list: Brand[], view: BrandView, q: string): Brand[] {
  const s = q.trim().toLowerCase();
  return list.filter((b) => {
    if (s && !b.name.toLowerCase().includes(s) && !b.sites.some((x) => x.host.includes(s))) return false;
    switch (view) {
      case "work": return !b.gone && toDo(b) > 0;
      case "nomaker": return !b.gone && !b.sites.some(isMaker);
      case "gone": return b.gone;
      default: return true;
    }
  }).sort((a, b) => (view === "work" ? toDo(b) - toDo(a) : 0) || a.name.localeCompare(b.name));
}

export const brandViewCount = (list: Brand[], view: BrandView): number => brandsIn(list, view, "").length;

/** The drawer's product lists → the server's groups (BrandSites::in_group()). */
export type ProductGroup = "work" | "all" | "bundles" | "nopub";
export const PRODUCT_GROUPS: { value: ProductGroup; label: string; hint: string }[] = [
  { value: "work", label: "Need a page", hint: "No maker page, one on a site that isn't the maker's, a page that's gone, or a proposed page to decide" },
  { value: "all", label: "Every product", hint: "" },
  { value: "bundles", label: "Bundles", hint: "Bundles sold here: a maker often has no page for them" },
  { value: "nopub", label: "Maker doesn't publish", hint: "Products a person marked as having no maker page" },
];

export interface ProductsReply { group: string; rows: PageRow[]; total: number; page: number; per_page: number }

/** GET /brand-sites/{id}/pages: a maker site's own product pages and the best one for each product asked about. */
export interface Match { url: string; name: string; score: number; why: string }
export interface SitePages {
  pending?: boolean; reading?: { at: string; source: string; page: number } | null;
  hosts: string[]; host: string; source?: string; blocked?: boolean; base?: string;
  matches?: Record<string, Match | null>;
}

/** A match good enough to tick for the person (a tie scores 65). */
export const SURE = 70;

/** Each product's best match across the maker's sites. */
export function bestMatches(replies: SitePages[]): Map<number, Match> {
  const best = new Map<number, Match>();
  for (const r of replies) {
    for (const [pid, m] of Object.entries(r.matches ?? {})) {
      if (!m) continue;
      const id = Number(pid);
      const had = best.get(id);
      if (!had || m.score > had.score) best.set(id, m);
    }
  }
  return best;
}

/** Ticked for the person: a sure match, unless the product already has a verified page that reads fine elsewhere. */
export function ticks(row: PageRow, m: Match | undefined): boolean {
  if (!m || m.score < SURE) return false;
  const same = !!row.current && row.current.replace(/\/+$/, "") === m.url.replace(/\/+$/, "");
  if (same) return false; // nothing to save
  return !(row.verified && row.result === "read");
}

/** The roles a person changed, as POST /brand-sites/{id} takes them. */
export const rolesBody = (host: string, role: SiteRole): { roles: Record<string, SiteRole> } => ({ roles: { [host]: role } });

/** A site typed in to add: its bare host, or "" when it isn't one. */
export function hostOf(typed: string): string {
  const s = typed.trim().toLowerCase();
  if (!s) return "";
  try {
    const h = new URL(/^https?:\/\//.test(s) ? s : `https://${s}`).hostname.replace(/^www\./, "");
    return /^[a-z0-9.-]+\.[a-z]{2,}$/.test(h) ? h : "";
  } catch {
    return "";
  }
}

/** A page read that failed: gone, moved, another site, a loop, an error, refused, robots.txt (PageReview's results). */
export const BROKEN_RESULTS = ["gone", "moved", "offsite", "loop", "error", "refused", "robots"];

/**
 * A brand's product row as a Maker pages row: the drawer's rows carry no list group, so it is worked out from the
 * row itself (a proposed page waits; a page read that failed; no page), which also keeps it right after an action.
 */
export function drawerRow(r: PageRow): PageRow {
  const group: PageRow["op_group"] = r.status === "candidate" && r.proposed ? "open"
    : r.fetch_status === "no_page" ? ""
    : r.current && BROKEN_RESULTS.includes(r.result) ? "broken"
    : !r.current ? "none" : "";
  return { ...r, op_group: group, op_conf: r.op_conf ?? null, op_wrong: r.op_wrong ?? "", op_wrong_note: r.op_wrong_note ?? "" };
}

/* ── The split view (owner 2026-11-14: "the same layout" as Site maps) ── */

export type BrandSort = "brand" | "todo";
/** The brand list's order: the view's own, or by a heading; ties stay A–Z whichever way the column runs. */
export function sortBrands(list: Brand[], sort: { col: BrandSort; desc: boolean } | null): Brand[] {
  if (!sort) return list;
  const dir = sort.desc ? -1 : 1;
  return [...list].sort((a, b) => (sort.col === "brand" ? a.name.localeCompare(b.name) * dir : (toDo(a) - toDo(b)) * dir || a.name.localeCompare(b.name)));
}

/** What a brand's products wait for, in words ("2 proposed pages to decide, 1 gone or moved"), or "". */
export function toDoText(b: Brand): string {
  const n = (k: number, one: string, many: string) => (k ? `${k} ${k === 1 ? one : many}` : "");
  return [n(b.open, "proposed page to decide", "proposed pages to decide"), n(b.failed_open, "page gone or moved", "pages gone or moved"), n(b.no_url, "product with no page", "products with no page")].filter(Boolean).join(", ");
}
