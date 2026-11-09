/**
 * Maker pages → Product pages (Product Data's PageList, GET mmpt/v1/page-list):
 * the products whose maker page needs a person's decision. Row shapes as the
 * route returns them, the groups and filters, and which actions a row offers.
 * Every change goes through the routes the old Product Data screen used
 * (/urls/*, /pages/queue, /page-list/{id}/keep), so both screens do the same.
 */

export type PageGroup = "open" | "broken" | "none" | "wrong" | "approved" | "rejected" | "every";
export type ConfBand = "high" | "mid" | "low" | "bad" | "unrated";

export interface CoverageAttr { key: string; label: string; state: string; detail: string; page: string; fills: boolean }

export interface PageRow {
  id: number;
  title: string;
  edit_url: string;
  view_url: string;
  image: string;
  sku: string;
  brand: { id: number; name: string } | null;
  /** The product's maker page now ("" when it has none). */
  current: string;
  verified: boolean;
  /** The page proposed for it, when one waits (status candidate) or was decided. */
  proposed: string;
  status: string;
  reason: string;
  reason_label: string;
  score: number;
  note: string;
  page_title: string;
  page_status: string;
  result: string;
  result_label: string;
  result_badge: string;
  fetch_status: string;
  read_at: string;
  read_note: string;
  /** Where the page now redirects, when it moved. */
  read_final: string;
  coverage?: { attrs: CoverageAttr[]; score: number } | null;
  op_group: PageGroup | "";
  op_conf: number | null;
  op_wrong: string;
  op_wrong_note: string;
}

export interface Option { key: string; label: string; n: number }

export interface PageListReply {
  rows: PageRow[];
  total: number;
  page: number;
  pages: number;
  counts: Record<PageGroup, number>;
  conf_counts: Record<ConfBand, number>;
  group: PageGroup | "";
  conf: ConfBand | "";
  reasons: Option[];
  results: Option[];
}

export interface PageFilter { group: PageGroup | ""; conf: ConfBand | ""; reason: string; result: string; brand: number; q: string; page: number }

export const EMPTY_PAGES: PageFilter = { group: "", conf: "", reason: "", result: "", brand: 0, q: "", page: 1 };

export const PER_PAGE = 50;

/** The pills: the four waiting groups (and all of them together), then the decisions taken and every product. */
export const PAGE_GROUPS: { value: PageGroup | ""; label: string; hint: string }[] = [
  { value: "", label: "Waiting", hint: "Every product whose page needs a decision" },
  { value: "open", label: "Proposed pages", hint: "A page was found for the product: use it or say it isn't the one" },
  { value: "broken", label: "Pages gone or moved", hint: "Its page is gone, moved or refuses the server" },
  { value: "none", label: "No page", hint: "No maker page and nothing proposed" },
  { value: "wrong", label: "Not a product page", hint: "Its address is a home, support or login page" },
  { value: "approved", label: "Approved", hint: "Proposed pages a person or a sure rule approved" },
  { value: "rejected", label: "Rejected", hint: "Proposed pages turned down" },
  { value: "every", label: "Every product", hint: "Every product in scope, to set or change its page" },
];

export const WAITING: PageGroup[] = ["open", "broken", "none", "wrong"];

export const CONF_BANDS: { value: ConfBand; label: string; badge: string }[] = [
  { value: "high", label: "Sure (85+)", badge: "success" },
  { value: "mid", label: "Likely (70–84)", badge: "info" },
  { value: "low", label: "Doubtful (55–69)", badge: "warning" },
  { value: "bad", label: "Unlikely (under 55)", badge: "error" },
  { value: "unrated", label: "Not rated yet", badge: "" },
];

/** The rating's badge colour, the same bands as the filter. */
export function confBand(n: number | null): ConfBand {
  if (n === null) return "unrated";
  return n >= 85 ? "high" : n >= 70 ? "mid" : n >= 55 ? "low" : "bad";
}

export const groupCount = (r: PageListReply | null, g: PageGroup | ""): number | null =>
  !r ? null : g === "" ? WAITING.reduce((a, k) => a + (r.counts[k] ?? 0), 0) : r.counts[g] ?? 0;

export function pagesQuery(f: PageFilter, extra: Record<string, string> = {}): string {
  const p = new URLSearchParams({ page: String(f.page), per_page: String(PER_PAGE) });
  if (f.group) p.set("group", f.group);
  if (f.conf) p.set("conf", f.conf);
  if (f.reason) p.set("reason", f.reason);
  if (f.result) p.set("result", f.result);
  if (f.brand) p.set("brand", String(f.brand));
  if (f.q.trim()) p.set("q", f.q.trim());
  for (const [k, v] of Object.entries(extra)) p.set(k, v);
  return p.toString();
}

/** One product's row again, with the filters shown: it comes back only while it still belongs in the list. */
export const rowQuery = (f: PageFilter, id: number): string => `${pagesQuery({ ...f, page: 1 })}&ids[]=${id}`;

export type PageAction = "use" | "reject" | "useMoved" | "keep" | "read" | "scrape" | "set" | "clear" | "none" | "look" | "reset";

/** The product is marked as having no maker page ("Maker doesn't publish it"). */
export const markedNone = (r: PageRow): boolean => r.fetch_status === "no_page" || r.result === "no_page";

/** The moved page's new address, when it stays on the same site (another site is never followed without a look). */
export function movedTo(r: PageRow): string {
  if (!r.read_final || !r.current) return "";
  try {
    const a = new URL(r.current).hostname.replace(/^www\./, "");
    const b = new URL(r.read_final).hostname.replace(/^www\./, "");
    return a === b && r.read_final !== r.current ? r.read_final : "";
  } catch {
    return "";
  }
}

/** The actions a row offers, most likely first. The page editor (set) and "Maker doesn't publish it" are always there. */
export function pageActions(r: PageRow): PageAction[] {
  if (markedNone(r)) return ["look", "set"];
  const out: PageAction[] = [];
  const waits = r.status === "candidate" && !!r.proposed;
  if (waits) out.push("use", "reject");
  if (r.op_group === "broken") {
    if (movedTo(r)) out.push("useMoved");
    out.push("read");
  }
  if (r.op_group === "wrong") out.push("keep");
  if (!waits && r.current && r.op_group !== "broken" && r.op_group !== "wrong") out.push("scrape");
  if (r.op_group === "rejected" || (r.status === "rejected" && r.op_group === "every")) out.push("reset");
  out.push("set");
  if (r.current && (r.op_group === "broken" || r.op_group === "wrong")) out.push("clear");
  out.push("none");
  return out;
}

/** Actions that take something away: their button asks for a second click. */
export const CONFIRM: Partial<Record<PageAction, string>> = {
  clear: "Click again to clear",
  none: "Click again: the maker doesn't publish it",
};

/** A typed address the editor can save: http(s), and not the page it has now. */
export function urlError(typed: string, current: string): string {
  const u = typed.trim();
  if (!u) return "Type or paste the page's address.";
  let parsed: URL;
  try {
    parsed = new URL(u);
  } catch {
    return "That isn't a web address.";
  }
  if (parsed.protocol !== "http:" && parsed.protocol !== "https:") return "The address starts with http:// or https://.";
  if (current && u.replace(/\/+$/, "") === current.replace(/\/+$/, "")) return "That's already its page. Type or paste another address.";
  return "";
}

/** What the page would add to the product: the parts it lacks that the page fills. */
export const pageFills = (r: PageRow): string[] => (r.coverage?.attrs ?? []).filter((a) => a.fills && a.state !== "ok").map((a) => a.label);

/** The outcome of an action, in words. */
export const DONE: Record<PageAction, string> = {
  use: "Approved: now its maker page; reading it.",
  reject: "Rejected: this page isn't proposed again.",
  useMoved: "Saved its new address; reading it.",
  keep: "Kept: this is its page.",
  read: "Queued to read again.",
  scrape: "Queued to read again and update its description and pictures.",
  set: "Saved: now its maker page; reading it.",
  clear: "Cleared: the finder will look for its page again.",
  none: "Marked: the maker doesn't publish a page for it.",
  look: "The finder will look for its page again.",
  reset: "Back in the proposed pages to decide.",
};

/** Bulk "Use proposed URL" answers how many were approved and which it left (a verified page never goes in bulk). */
export function bulkUseText(d: { done: number; skipped: number[] }): string {
  const s = `${d.done} page${d.done === 1 ? "" : "s"} approved and being read.`;
  return d.skipped.length ? `${s} ${d.skipped.length} left as they are: they have a different verified page, so decide those one at a time.` : s;
}

/** POST /urls/evidence: why a proposed page is (or isn't) the product's, from its stored copy. */
export interface Evidence {
  ours: { image: string; pictures: number; chars: number };
  proposed: { url: string; host: string; images: string[]; text: string; chars: number; status: string; title: string; stored_at: string; pending: boolean } | null;
  reason_label: string;
  score: number;
  /** [tone, text, points?]: tone good | bad | info. */
  notes: [string, string, number?][];
  confidence: { n: number; why: string[] } | null;
}

export const NOTE_ICON: Record<string, string> = { good: "yes-alt", bad: "warning", info: "info-outline" };
