/**
 * Needs a decision (Product Data's Waiting, GET mmpt/v1/waiting) and the product drawer
 * (GET mmpt/v1/products/{id}/workbench): types, the list's query, and what the chips mean.
 */
import type { FeedValue, Issue } from "./issuesApi";
import type { ProposalRow } from "./proposalsApi";

export interface WaitBadge { key: string; label: string; tone: "error" | "warning" | "success" | "info" }
export interface TermRef { id: number; name: string }
/** A product's picture for its row: the featured picture's small copy, or its first gallery picture's (featured false). */
export interface Thumb { url: string; featured: boolean; count: number }
/** A waiting key on the listed products, with how many carry it: one pill each. issue: the Issue key that fixes it. */
export interface WaitKey extends WaitBadge { n: number; issue: string }

export interface WaitingRow {
  id: number;
  title: string;
  status: string;
  sku: string;
  cats: TermRef[];
  brands: TermRef[];
  waiting: WaitBadge[];
  thumb: Thumb | null;
  /** Its issues with their fixes (as the drawer shows them), for Alerts on this page. */
  issues: Issue[];
}

/** A pill: every product (""), a review queue, or key:{one waiting key}. */
export type QueueKind = "titles" | "descriptions" | "pictures" | "pages" | "maps";
export type WaitKind = "" | QueueKind | `key:${string}`;
export type WaitSort = "" | "id" | "title" | "brand" | "waiting";

export interface WaitingPage {
  rows: WaitingRow[];
  total: number;
  pages: number;
  page: number;
  counts: Record<QueueKind | "data" | "picture_errors" | "all", number>;
  keys: WaitKey[];
}

export interface WaitFilter { kind: WaitKind; q: string; page: number; orderby: WaitSort; order: "asc" | "desc" }
export const EMPTY_WAIT: WaitFilter = { kind: "", q: "", page: 1, orderby: "", order: "desc" };

/**
 * The first pills, in order: every product, then the review queues (owner 2026-11-13: the Review menu's sections are
 * subsets of Needs a decision, so they are its pills). Each queue shows its own tools under the pill. After them, one
 * pill per alert on the listed products (alertKeys()).
 */
export const WAIT_KINDS: { value: "" | QueueKind; label: string; count: "all" | QueueKind; hint: string }[] = [
  { value: "", label: "All waiting", count: "all", hint: "Every product something waits on, in one table" },
  { value: "titles", label: "Title proposals", count: "titles", hint: "Titles the rules would change: accept, edit or reject them, and write the accepted ones" },
  { value: "descriptions", label: "Descriptions", count: "descriptions", hint: "Description proposals from maker pages and suppliers" },
  { value: "pictures", label: "Pictures to pick", count: "pictures", hint: "Picture proposals: pick and order a product's pictures" },
  { value: "pages", label: "Maker pages", count: "pages", hint: "Products whose maker page waits on a decision" },
  { value: "maps", label: "Map parts", count: "maps", hint: "Parts found on maker sites' pages (where each keeps pictures, manuals, requirements…), waiting for your approval" },
];

/** The waiting keys the queue pills stand for (they get no alert pill of their own). */
export const QUEUE_KEYS: Record<string, QueueKind> = { title: "titles", description: "descriptions", pictures: "pictures", page: "pages" };

/** The pills that show a review queue's own tools instead of the product table. */
export const QUEUE_KINDS: QueueKind[] = ["titles", "descriptions", "pictures", "pages", "maps"];
export const isQueue = (k: WaitKind): k is QueueKind => (QUEUE_KINDS as string[]).includes(k);

/**
 * One pill per alert on the listed products (errors, then warnings, then notes; commonest first), the active one kept
 * with 0 when fixing it emptied it, so the pill a person is on never disappears under them.
 */
export function alertKeys(keys: WaitKey[], active: WaitKind): WaitKey[] {
  const list = keys.filter((k) => !QUEUE_KEYS[k.key]);
  if (active.startsWith("key:") && !list.some((k) => `key:${k.key}` === active)) {
    const key = active.slice(4);
    list.push({ key, label: key.replace(/^flag:/, "").replace(/_/g, " ").replace(/^./, (c) => c.toUpperCase()), tone: "warning", n: 0, issue: key.replace(/^flag:/, "") });
  }
  return list;
}

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
  thumb: Thumb | null;
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
