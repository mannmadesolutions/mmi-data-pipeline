/**
 * Title proposals (Product Data's review queue) for the Workbench's Title
 * proposals section: row shapes as Rest::shape() returns them, the queue
 * filters, the word diff that highlights what a proposal changes, and which
 * actions a row offers in each status. The REST client is titleRulesApi's.
 */

export type ProposalStatus = "proposed" | "accepted" | "edited" | "rejected" | "applied" | "reverted" | "stale" | "error";

export interface Why { from: string; to: string; confidence: number; source: string }

export interface ProposalRow {
  id: number;
  status: ProposalStatus;
  current: string;
  proposed: string;
  edited: string | null;
  original: string | null;
  confidence: number;
  rules: string[];
  flags: string[];
  dupes: number;
  brand: string;
  stock: string;
  post_status: string;
  sku: string;
  price: string;
  why: Record<string, Why>;
  renamed: { from: string; to: string } | null;
  error: string;
  edit_url: string;
  view_url: string;
  cats: { id: number; name: string }[];
  brands: { id: number; name: string }[];
  cat_locked: boolean;
  brand_locked: boolean;
  /** Composer inputs the product lacks: brand, category, sku. */
  missing: string[];
}

/** What a missing input costs the title (Store::MISSING_INPUTS). */
export const MISSING_LABELS: Record<string, [string, string]> = {
  brand: ["No brand", "No brand: the brand can't be put first."],
  category: ["No category", "Only the default category: the rules can't tell what the product is."],
  sku: ["No SKU", "No SKU: the supplier's data can't be matched."],
};

export type TermKind = "cats" | "brands";

export interface ProposalPage { rows: ProposalRow[]; total: number; pages: number }

export interface QueueSummary {
  by_status: Record<string, number>;
  /** Unreviewed proposals that pass every auto-apply gate (status=auto lists them). */
  auto?: number;
  rules: Record<string, { label: string }>;
  brands: string[];
}

export interface ApplyStatus {
  phase: "disabled" | "running" | "waiting" | "interrupted" | "idle";
  enabled: boolean;
  queue: number;
  applied_today: number;
  batch_size: number;
  next_at: number | null;
  now: number;
  run: { total: number; done: number; finished_at: string | null } | null;
  error?: string;
}

export interface Suggestion {
  key: string;
  kind: string;
  from: string;
  to: string;
  label: string;
  brand: string;
  reach: { brand: number; all: number };
}

export type QueueSort = "id" | "proposed" | "brand" | "category" | "rules" | "confidence" | "stock" | "status";

export interface QueueFilter { status: string; q: string; brand: string; rule: string; page: number; per_page: number; orderby: QueueSort; order: "asc" | "desc" }

export const EMPTY_FILTER: QueueFilter = { status: "open", q: "", brand: "", rule: "", page: 1, per_page: 25, orderby: "confidence", order: "desc" };

/** The status pills: query value → label, and which stored statuses each counts. */
export const STATUS_VIEWS: { value: string; label: string; counts: ProposalStatus[] }[] = [
  { value: "open", label: "To review", counts: ["proposed", "stale", "error"] },
  { value: "auto", label: "Would auto-apply", counts: [] }, // counted by Store::summary()'s auto, not by status
  { value: "queued", label: "Waiting to be written", counts: ["accepted", "edited"] },
  { value: "applied", label: "Written", counts: ["applied"] },
  { value: "rejected", label: "Rejected", counts: ["rejected"] },
  { value: "reverted", label: "Put back", counts: ["reverted"] },
  { value: "any", label: "All", counts: ["proposed", "stale", "error", "accepted", "edited", "applied", "rejected", "reverted"] },
];

export const STATUS_LABEL: Record<ProposalStatus, [string, string]> = {
  proposed: ["Proposed", "info"],
  accepted: ["Accepted", "success"],
  edited: ["Edited", "success"],
  applied: ["Written", "success"],
  rejected: ["Rejected", ""],
  reverted: ["Put back", "warning"],
  stale: ["Title changed", "warning"],
  error: ["Write failed", "error"],
};

export function viewCount(byStatus: Record<string, number>, view: string): number {
  const v = STATUS_VIEWS.find((s) => s.value === view);
  return v ? v.counts.reduce((n, s) => n + (byStatus[s] ?? 0), 0) : 0;
}

/** GET /proposals query string for a filter. */
export function filterQuery(f: QueueFilter): string {
  const p = new URLSearchParams({ status: f.status, page: String(f.page), per_page: String(f.per_page), orderby: f.orderby, order: f.order });
  if (f.q.trim()) p.set("q", f.q.trim());
  if (f.brand) p.set("brand", f.brand);
  if (f.rule) p.set("rule", f.rule);
  return p.toString();
}

/** The title a row will write (a person's edit wins). */
export const target = (r: ProposalRow): string => (r.status === "edited" && r.edited ? r.edited : r.proposed);

export type RowAction = "accept" | "reject" | "reset" | "edit" | "apply" | "revert";

/** What a row offers in its status. */
export function actionsFor(status: ProposalStatus): RowAction[] {
  switch (status) {
    case "proposed":
    case "stale":
    case "error":
      return ["accept", "reject", "edit"];
    case "accepted":
    case "edited":
      return ["apply", "edit", "reset"];
    case "rejected":
      return ["reset", "edit"];
    case "applied":
      return ["revert"];
    default:
      return [];
  }
}

/** Confidence badge kind. */
export const confKind = (c: number): string => (c >= 85 ? "success" : c >= 70 ? "info" : c >= 55 ? "warning" : "error");

export interface DiffPart { text: string; kind: "same" | "del" | "ins" }

/** Word-level diff (longest common subsequence): before with deletions marked, after with insertions marked. */
export function wordDiff(a: string, b: string): { before: DiffPart[]; after: DiffPart[] } {
  const x = a.split(/(\s+)/).filter((t) => t !== "");
  const y = b.split(/(\s+)/).filter((t) => t !== "");
  const n = x.length;
  const m = y.length;
  const dp: number[][] = Array.from({ length: n + 1 }, () => new Array<number>(m + 1).fill(0));
  for (let i = n - 1; i >= 0; i--) for (let j = m - 1; j >= 0; j--) dp[i]![j] = x[i] === y[j] ? dp[i + 1]![j + 1]! + 1 : Math.max(dp[i + 1]![j]!, dp[i]![j + 1]!);
  const before: DiffPart[] = [];
  const after: DiffPart[] = [];
  const push = (list: DiffPart[], text: string, kind: DiffPart["kind"]) => {
    const last = list[list.length - 1];
    if (last && last.kind === kind) last.text += text;
    else list.push({ text, kind });
  };
  let i = 0;
  let j = 0;
  while (i < n || j < m) {
    if (i < n && j < m && x[i] === y[j]) { push(before, x[i]!, "same"); push(after, y[j]!, "same"); i++; j++; }
    else if (j < m && (i >= n || dp[i]![j + 1]! >= dp[i + 1]![j]!)) { push(after, y[j]!, /^\s+$/.test(y[j]!) ? "same" : "ins"); j++; }
    else { push(before, x[i]!, /^\s+$/.test(x[i]!) ? "same" : "del"); i++; }
  }
  return { before: joinRuns(before), after: joinRuns(after) };
}

/** A space between two changed words of the same kind belongs to the change: "Acme Audio" is one highlight. */
function joinRuns(parts: DiffPart[]): DiffPart[] {
  const out: DiffPart[] = [];
  for (let k = 0; k < parts.length; k++) {
    const p = parts[k]!;
    const prev = out[out.length - 1];
    const next = parts[k + 1];
    if (p.kind === "same" && /^\s+$/.test(p.text) && prev && next && prev.kind !== "same" && prev.kind === next.kind) {
      prev.text += p.text + next.text;
      k++;
    } else if (prev && prev.kind === p.kind) {
      prev.text += p.text;
    } else {
      out.push({ ...p });
    }
  }
  return out;
}

/** The writer's one-line status. */
export function applyLine(s: ApplyStatus, nowSec: number): string {
  if (!s.enabled || s.phase === "disabled") return "Writing titles to products is off.";
  const q = s.queue;
  const waiting = q ? `${q} title${q === 1 ? "" : "s"} waiting to be written.` : "Nothing waiting to be written.";
  if (s.phase === "running") return `Writing… ${s.run ? `${s.run.done} of ${s.run.total} written. ` : ""}${waiting}`;
  if (s.phase === "waiting" && s.next_at) {
    const sec = Math.max(0, Math.round(s.next_at - nowSec));
    return `Next batch of up to ${s.batch_size} in ${sec >= 60 ? `${Math.floor(sec / 60)} min ${sec % 60} s` : `${sec} s`}. ${waiting}`;
  }
  if (s.phase === "interrupted") return `The last batch stopped part-way; it resumes with the next. ${waiting}`;
  return `${waiting}${s.applied_today ? ` ${s.applied_today} written today.` : ""}`;
}

/** Terms whose name or path holds the search (any case), chosen ones first. */
export function filterTerms<T extends { id: number; name: string; path: string }>(terms: T[], q: string, chosen: number[], limit = 200): T[] {
  const s = q.trim().toLowerCase();
  const hit = s ? terms.filter((t) => t.path.toLowerCase().includes(s)) : terms;
  const first = hit.filter((t) => chosen.includes(t.id));
  return [...first, ...hit.filter((t) => !chosen.includes(t.id))].slice(0, limit);
}
