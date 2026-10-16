/**
 * Pure Workbench logic: filters, selection, field locks, jobs and term trees.
 * No DOM and no requests, so every rule here is unit-tested directly.
 */
import {
  HEALTH_MODE_LABELS, JOB_STATUS_LABELS, MESSAGES, SEARCH_IN_LABELS, STATUS_LABELS, STOCK_LABELS, fill, num, plural,
} from "./messages";
import type { ActionChoice, Filter, Job, Selection, WorkbenchConfig } from "./types";

/* ── Filter ─────────────────────────────────────────────────────────────── */

/** Statuses ticked on load (sorted). On their own they don't count as a search. */
export const DEFAULT_STATUSES = ["draft", "publish"];

/** The quick filters above the condition builder. */
export interface QuickFilters {
  q: string;
  qCase: boolean;
  qIn: Filter["q_in"];
  supplier: string;
  statuses: string[];
  stock: string[];
  health: string[];
  healthMode: Filter["health_mode"];
}

export const EMPTY_QUICK: QuickFilters = {
  q: "", qCase: false, qIn: "title_sku", supplier: "all",
  statuses: [...DEFAULT_STATUSES], stock: [], health: [], healthMode: "any",
};

/**
 * The filter mmi_workbench_search receives. Key order matches the jQuery
 * version, so the "nothing changed" check compares like with like.
 */
export function buildFilter(quick: QuickFilters, conditions: Filter["conditions"], matchLogic: Filter["match_logic"]): Filter {
  return {
    stock: quick.stock,
    health: quick.health,
    health_mode: quick.healthMode,
    q: quick.q.trim(),
    q_case: quick.qCase ? 1 : 0,
    q_in: quick.qIn,
    supplier: quick.supplier,
    match_logic: matchLogic,
    statuses: quick.statuses.length ? quick.statuses : ["publish"],
    conditions,
  };
}

export const filterKey = (f: Filter): string => JSON.stringify(f);

/** Whether the filter narrows anything. Results (and Apply to all) wait until it does. */
export function hasParameters(f: Filter): boolean {
  const statuses = [...f.statuses].sort().join(",");
  return !!(f.q || f.conditions.length || f.stock.length || f.health.length
    || f.supplier !== "all" || statuses !== DEFAULT_STATUSES.join(","));
}

/** Readable one-liner of a search, stored with each job ("Products searched for"). */
export function describeFilter(f: Filter, conditionPhrases: { text: string; or: boolean }[], cfg: Pick<WorkbenchConfig, "suppliers" | "healthChecks">): string {
  const parts: string[] = [];
  if (f.q) parts.push(`${SEARCH_IN_LABELS[f.q_in]} contains "${f.q}"${f.q_case ? " (match case)" : ""}`);
  for (const c of conditionPhrases) parts.push(`${parts.length && c.or ? "or " : ""}${c.text}`);
  if (parts.length > 1 && f.match_logic === "any") parts[0] = `Any of: ${parts[0]}`;
  if (f.supplier !== "all") {
    const name = f.supplier === "native" ? "No supplier (created here)" : cfg.suppliers.find((s) => s.id === f.supplier)?.name ?? f.supplier;
    parts.push(`Supplier: ${name}`);
  }
  parts.push(f.statuses.map((s) => STATUS_LABELS[s] ?? s).join(", "));
  if (f.stock.length) parts.push(f.stock.map((s) => STOCK_LABELS[s] ?? s).join(", "));
  if (f.health.length) {
    const names = f.health.map((id) => cfg.healthChecks.find((c) => c.id === id)?.label ?? id);
    parts.push(`${HEALTH_MODE_LABELS[f.health_mode]}${names.join(", ")}`);
  }
  return parts.join(" · ");
}

/* ── Selection ──────────────────────────────────────────────────────────── */

/**
 * Either explicit product IDs, or "every product matching the last search"
 * minus any unticked. The server re-matches a filter selection when a job
 * starts, so a 5,000-product selection never travels as 5,000 IDs.
 */
export interface SelectionState { mode: "ids" | "filter"; ids: ReadonlySet<number>; exclude: ReadonlySet<number> }

export const EMPTY_SELECTION: SelectionState = { mode: "ids", ids: new Set(), exclude: new Set() };

export const isSelected = (s: SelectionState, id: number): boolean => (s.mode === "filter" ? !s.exclude.has(id) : s.ids.has(id));

export const selectedCount = (s: SelectionState, total: number): number => (s.mode === "filter" ? total - s.exclude.size : s.ids.size);

export function toggleRows(s: SelectionState, ids: number[], on: boolean): SelectionState {
  if (s.mode === "filter") {
    const exclude = new Set(s.exclude);
    ids.forEach((id) => (on ? exclude.delete(id) : exclude.add(id)));
    return { ...s, exclude };
  }
  const next = new Set(s.ids);
  ids.forEach((id) => (on ? next.add(id) : next.delete(id)));
  return { ...s, ids: next };
}

export const selectAllMatching = (): SelectionState => ({ mode: "filter", ids: new Set(), exclude: new Set() });

export function selectionPayload(s: SelectionState, filter: Filter): Selection {
  return s.mode === "filter" ? { mode: "filter", filter, exclude: [...s.exclude] } : { mode: "ids", ids: [...s.ids] };
}

/** Every product the search finds, ticks ignored. */
export const allPayload = (filter: Filter): Selection => ({ mode: "filter", filter, exclude: [] });

/** The sentence under the change controls saying what Preview/Apply will touch. */
export function targetText(s: SelectionState, total: number, haveResults: boolean): string {
  const n = selectedCount(s, total);
  if (n && s.mode === "filter") {
    const excl = s.exclude.size ? fill(MESSAGES.TARGET_EXCLUDED, { count: num(s.exclude.size) }) : "";
    return fill(MESSAGES.TARGET_FILTER, { count: num(total), s: plural(total), excl });
  }
  if (n) return fill(MESSAGES.TARGET_IDS, { count: num(n), s: plural(n) });
  if (haveResults) return fill(MESSAGES.TARGET_NONE, { count: num(total), s: plural(total) });
  return MESSAGES.NOTHING_SELECTED;
}

/* ── Field locks ────────────────────────────────────────────────────────── */

export interface LockOptions { fields: string[]; label: string; always: boolean; skip: boolean; add: boolean }

/** Lockable import fields a change writes (mirrors Product_Workbench::lock_fields()). */
export function lockFields(choice: Pick<ActionChoice, "action" | "params">, cfg: Pick<WorkbenchConfig, "lockLabels" | "actionLockFields">): string[] {
  let fields = cfg.actionLockFields[choice.action] ?? [];
  if (choice.action === "set_taxonomy_term") fields = [choice.params.taxonomy ?? ""];
  if (choice.action === "copy_source_field_to_meta") fields = [choice.params.meta_key ?? ""];
  return fields.filter((f) => f && Object.prototype.hasOwnProperty.call(cfg.lockLabels, f));
}

/** The two lock switches' effect for this change. A taxonomy change always locks, so it has no Lock switch. */
export function lockOptions(
  choice: Pick<ActionChoice, "action" | "params">,
  switches: { skip: boolean; add: boolean },
  cfg: Pick<WorkbenchConfig, "lockLabels" | "actionLockFields">,
): LockOptions {
  const fields = lockFields(choice, cfg);
  const always = choice.action === "set_taxonomy_term";
  return {
    fields,
    label: fields.map((f) => cfg.lockLabels[f]).join(" and "),
    always,
    skip: fields.length > 0 && switches.skip,
    add: fields.length > 0 && (always || switches.add),
  };
}

/* ── Jobs ───────────────────────────────────────────────────────────────── */

export type JobSortKey = "created_at" | "summary" | "filter_label" | "changed" | "status";
const JOB_NUMERIC: JobSortKey[] = ["changed"];

/** Recent changes: ≤30 rows, all in hand, sorted in memory and never re-fetched to sort. */
export function sortJobs(jobs: Job[], key: JobSortKey, dir: "asc" | "desc"): Job[] {
  const sign = dir === "desc" ? -1 : 1;
  return [...jobs].sort((a, b) => {
    const av = a[key], bv = b[key];
    const cmp = JOB_NUMERIC.includes(key)
      ? (Number(av) || 0) - (Number(bv) || 0)
      : String(av ?? "").localeCompare(String(bv ?? ""), undefined, { sensitivity: "base", numeric: true });
    return cmp * sign;
  });
}

export function jobChangedText(j: Job): string {
  if (j.status === "undone") {
    return fill(MESSAGES.JOB_UNDONE, { undone: num(j.undone) }) + (j.conflicts ? fill(MESSAGES.JOB_KEPT, { count: num(j.conflicts) }) : "");
  }
  return fill(MESSAGES.JOB_CHANGED, { changed: num(j.changed), total: num(j.total) })
    + (j.skipped ? fill(MESSAGES.JOB_SKIPPED, { count: num(j.skipped) }) : "");
}

export const jobBadge = (j: Job): { kind: string; label: string } => ({
  kind: ({ done: "success", undone: "info", running: "warning", undoing: "warning", stopped: "warning" } as Record<string, string>)[j.status] ?? "",
  label: JOB_STATUS_LABELS[j.status] ?? j.status,
});

/** Which row buttons a job offers. */
export function jobActions(j: Job): { undo: boolean; finishUndo: boolean; resume: boolean } {
  return {
    undo: (j.status === "done" || j.status === "stopped") && j.changed > 0,
    finishUndo: j.status === "undoing",
    resume: (j.status === "running" || j.status === "stopped") && j.cursor < j.total,
  };
}

export type RunKind = "apply" | "undo";

export function progressOf(job: Job, kind: RunKind): { pct: number; text: string } {
  const undo = kind === "undo";
  const done = undo ? job.undone + job.conflicts : job.cursor;
  const total = Math.max(undo ? job.changed : job.total, 1);
  return {
    pct: Math.min(100, Math.round((done / total) * 100)),
    text: undo
      ? fill(MESSAGES.PROGRESS_UNDO, { done: num(done), changed: num(job.changed) })
      : fill(MESSAGES.PROGRESS_APPLY, { cursor: num(job.cursor), total: num(job.total), changed: num(job.changed) }),
  };
}

/** Whether a batch reply means the run is over. */
export const runFinished = (job: Job, kind: RunKind): boolean => (kind === "undo" ? job.status === "undone" : job.status !== "running");

/** The message shown when a run ends normally. */
export function finishMessage(job: Job, kind: RunKind): { text: string; ok: boolean } {
  if (kind === "undo") {
    const conflicts = job.conflicts ? fill(MESSAGES.UNDONE_CONFLICTS, { count: num(job.conflicts) }) : "";
    return { text: fill(MESSAGES.UNDONE, { undone: num(job.undone), conflicts }), ok: true };
  }
  const failed = job.failed ? fill(MESSAGES.DONE_FAILED, { count: num(job.failed) }) : "";
  const skipped = job.skipped ? fill(MESSAGES.DONE_SKIPPED, { count: num(job.skipped) }) : "";
  return {
    text: fill(MESSAGES.DONE, { changed: num(job.changed), unchanged: num(job.unchanged), skipped, failed }),
    ok: !job.failed,
  };
}

/* ── Term trees (row editor) ────────────────────────────────────────────── */

export interface TreeTerm { id: number; name: string; depth: number; path: string }

/** Terms in tree order, each with its depth and "Parent › Child" path. */
export function termTree(terms: { id: number; name: string; parent?: number }[], hierarchical: boolean): TreeTerm[] {
  if (!hierarchical) return terms.map((t) => ({ id: t.id, name: t.name, depth: 0, path: t.name }));
  const kids = new Map<number, typeof terms>();
  terms.forEach((t) => {
    const p = t.parent ?? 0;
    kids.set(p, [...(kids.get(p) ?? []), t]);
  });
  const out: TreeTerm[] = [];
  const seen = new Set<number>();
  const walk = (parent: number, depth: number, path: string): void => {
    for (const t of kids.get(parent) ?? []) {
      if (seen.has(t.id)) continue; // a corrupt parent loop must not hang the page
      seen.add(t.id);
      const p = path ? `${path} › ${t.name}` : t.name;
      out.push({ id: t.id, name: t.name, depth, path: p });
      walk(t.id, depth + 1, p);
    }
  };
  walk(0, 0, "");
  return out;
}

/** Ticked terms first, so the current choice is visible without scrolling. */
export function orderTicked(terms: TreeTerm[], ticked: ReadonlySet<number>): TreeTerm[] {
  return [...terms.filter((t) => ticked.has(t.id)), ...terms.filter((t) => !ticked.has(t.id))];
}
