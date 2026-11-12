/**
 * Needs a decision (Product Data's Waiting, GET mmpt/v1/waiting) and the product drawer
 * (GET mmpt/v1/products/{id}/workbench): types, the list's query, and what the chips mean.
 */
import type { FeedValue, Issue } from "./issuesApi";
import type { ProposalRow } from "./proposalsApi";

export interface WaitBadge { key: string; label: string; tone: "error" | "warning" | "success" | "info" }
export interface TermRef { id: number; name: string }

export interface WaitingRow {
  id: number;
  title: string;
  status: string;
  sku: string;
  cats: TermRef[];
  brands: TermRef[];
  waiting: WaitBadge[];
}

export type WaitKind = "" | "titles" | "descriptions" | "pictures" | "pages" | "data" | "picture_errors";
export type WaitSort = "" | "id" | "title" | "brand" | "waiting";

export interface WaitingPage {
  rows: WaitingRow[];
  total: number;
  pages: number;
  page: number;
  counts: Record<Exclude<WaitKind, ""> | "all", number>;
}

export interface WaitFilter { kind: WaitKind; q: string; page: number; orderby: WaitSort; order: "asc" | "desc" }
export const EMPTY_WAIT: WaitFilter = { kind: "", q: "", page: 1, orderby: "", order: "desc" };

/** The chips, in order: the list's kinds and what each says. */
export const WAIT_KINDS: { value: WaitKind; label: string; count: keyof WaitingPage["counts"]; hint: string }[] = [
  { value: "", label: "All waiting", count: "all", hint: "Every product something waits on" },
  { value: "titles", label: "Titles", count: "titles", hint: "Title proposals to review" },
  { value: "descriptions", label: "Descriptions", count: "descriptions", hint: "Description proposals" },
  { value: "pictures", label: "Pictures", count: "pictures", hint: "Picture proposals to pick" },
  { value: "pages", label: "Maker pages", count: "pages", hint: "Maker pages to decide" },
  { value: "data", label: "Data issues", count: "data", hint: "No brand, no category, no SKU, or a flag that still holds" },
  { value: "picture_errors", label: "Picture errors", count: "picture_errors", hint: "The picture runner failed on them in its last run" },
];

/** GET /waiting query string. The default order (no orderby) is the server's: errors first, most waiting, newest. */
export function waitingQuery(f: WaitFilter): string {
  const p = new URLSearchParams({ page: String(f.page) });
  if (f.kind) p.set("kind", f.kind);
  if (f.q.trim()) p.set("q", f.q.trim());
  if (f.orderby) { p.set("orderby", f.orderby); p.set("order", f.order); }
  return p.toString();
}

/** A heading click: a new column starts ascending (IDs and counts descending), the same one flips; a third click goes back to the default order. */
export function nextSort(f: WaitFilter, col: Exclude<WaitSort, "">): Pick<WaitFilter, "orderby" | "order"> {
  if (f.orderby !== col) return { orderby: col, order: col === "id" || col === "waiting" ? "desc" : "asc" };
  const first = col === "id" || col === "waiting" ? "desc" : "asc";
  return f.order === first ? { orderby: col, order: first === "asc" ? "desc" : "asc" } : { orderby: "", order: "desc" };
}

/** Everything the drawer shows about one product. */
export interface DrawerProduct {
  id: number;
  title: string;
  status: string;
  stock: string;
  sku: string;
  edit_url: string;
  view_url: string;
  waiting: WaitBadge[];
  issues: Issue[];
  /** Its title proposal row, as Title proposals shows it (null: the titles engine has no row for it). */
  proposal: ProposalRow | null;
  cats: TermRef[];
  brands: TermRef[];
  locks: { cats: boolean; brands: boolean };
  feed: { brands: FeedValue[]; cats: FeedValue[] };
}

export const OPEN_TITLE = ["proposed", "stale", "error"];

/** The id before or after this one in the rows on screen (null at an end). */
export function step(rows: { id: number }[], id: number, by: -1 | 1): number | null {
  const i = rows.findIndex((r) => r.id === id);
  if (i < 0) return by === 1 ? rows[0]?.id ?? null : null; // a product opened from a link: › starts the list
  return rows[i + by]?.id ?? null;
}

export const STOCK_LABEL: Record<string, string> = { instock: "In stock", outofstock: "Out of stock", onbackorder: "On backorder" };
export const POST_STATUS_LABEL: Record<string, string> = { publish: "Published", draft: "Draft", pending: "Pending", private: "Private" };

/** The window event a product's change is announced with, so a list showing it can redraw that row. */
export const PRODUCT_CHANGED = "mmi-wb:product-changed";
/** One event for one product or several (a brand made from one product and mapped to its siblings). */
export const announceChanged = (ids: number | number[]): void => {
  window.dispatchEvent(new CustomEvent<number[]>(PRODUCT_CHANGED, { detail: Array.isArray(ids) ? ids : [ids] }));
};
