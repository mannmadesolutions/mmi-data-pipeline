/**
 * Product pictures: what the picture runner did (Pictures::summary(), rows()),
 * for the Workbench's Product pictures section. Moved from the old Product
 * Data page's pictures.js (migration phase 8): the runner's state in one line,
 * outcomes by reason with sizes before and after, the replaced media's
 * whereabouts, and the per-product ledger.
 */

export interface PictureGroup {
  status: string; mode: string; reason: string; reason_label: string; n: number;
  pics_before: number; pics_after: number; bytes_before: number; bytes_after: number;
}

export interface PicturesSummary {
  groups: PictureGroup[];
  running: boolean;
  done_today: number;
  cap: number;
  waiting: number;
  activity: { manual: boolean; busy: boolean; reasons: string[] };
  pass: { started_at?: string; done_at?: string };
  state: { note?: string; last_run?: { vendor?: number; in_place?: number; skipped?: number; error?: number } };
  release: Record<string, { n: number; bytes: number }>;
  folders: { dir: string; n: number; bytes: number; purge_from: string }[];
}

export interface PictureRow {
  product_id: string | number; title: string; status: string; mode: string; reason: string; reason_label: string;
  source_url: string; pics_before: string | number; pics_after: string | number; bytes_before: string | number; bytes_after: string | number;
  note: string; run_at: string; thumb: string;
}

export interface PictureRows { rows: PictureRow[]; total: number; per_page: number }

export type PictureSort = "id" | "title" | "outcome" | "reason" | "pics" | "before" | "after" | "run_at";

export const OUTCOME: Record<string, [string, string]> = {
  vendor: ["Vendor pictures", "success"],
  in_place: ["Own pictures, re-saved", "warning"],
  skipped: ["Left alone", ""],
  error: ["Error", "error"],
  reverted: ["Put back", "info"],
  revert: ["Putting back…", "info"],
  queued: ["Waiting", "info"],
  working: ["Working…", "info"],
};

/** The ledger's Show filter: "" is every finished product. */
export const SHOWS: [string, string][] = [
  ["", "Everything done"], ["vendor", "Vendor pictures"], ["in_place", "Own pictures, re-saved"], ["skipped", "Left alone"],
  ["error", "Errors"], ["reverted", "Put back"], ["queued", "Waiting"],
];

/** A written row's outcome is its mode (vendor or own pictures); any other row's is its status. */
export const outcomeOf = (status: string, mode: string): string => (status === "applied" ? mode : status);

export function size(b: number | string): string {
  const n = Number(b) || 0;
  if (n >= 1073741824) return `${(n / 1073741824).toFixed(2)} GB`;
  if (n >= 1048576) return `${(n / 1048576).toFixed(1)} MB`;
  return `${Math.round(n / 1024)} KB`;
}

const num = (n: number) => n.toLocaleString("en-US");

/** The runner's state in one line. */
export function runnerLine(d: PicturesSummary): string {
  const parts: string[] = [];
  const paused = !!(d.activity?.manual && d.running);
  if (paused) parts.push(`Runner waiting: ${d.activity.reasons.join("; ")}.`);
  else {
    parts.push(d.running ? "Runner working." : "Runner idle.");
    if (d.state?.note) parts.push(`${d.state.note}.`);
  }
  parts.push(`${d.done_today} of ${d.cap} products changed in the last 24 hours.`);
  if (d.waiting) parts.push(`${num(d.waiting)} waiting.`);
  if (d.pass?.started_at) parts.push(d.pass.done_at ? `Last pass finished ${d.pass.done_at} UTC; the runner starts again only when you queue products.` : `Pass started ${d.pass.started_at} UTC.`);
  const l = d.state?.last_run;
  if (l) parts.push(`Last run: ${l.vendor ?? 0} vendor, ${l.in_place ?? 0} own re-saved, ${l.skipped ?? 0} left alone, ${l.error ?? 0} errors.`);
  return parts.join(" ");
}

/** Products that kept their own pictures (owner D7: called out, not buried in a count). */
export const inPlaceCount = (d: PicturesSummary): number =>
  d.groups.filter((g) => g.status === "applied" && g.mode === "in_place").reduce((n, g) => n + g.n, 0);

const ORDER = ["vendor", "in_place", "queued", "working", "revert", "reverted", "skipped", "error"];

/** Outcome groups in a fixed order (biggest first within one), and the totals of the changed ones. */
export function groupTable(d: PicturesSummary): { rows: (PictureGroup & { outcome: string; changed: boolean })[]; total: { n: number; pb: number; pa: number; bb: number; ba: number } } {
  const rows = [...d.groups]
    .map((g) => ({ ...g, outcome: outcomeOf(g.status, g.mode), changed: g.status === "applied" }))
    .sort((a, b) => ORDER.indexOf(a.outcome) - ORDER.indexOf(b.outcome) || b.n - a.n);
  const total = { n: 0, pb: 0, pa: 0, bb: 0, ba: 0 };
  for (const g of rows) {
    total.n += g.n;
    if (g.changed) { total.pb += g.pics_before; total.pa += g.pics_after; total.bb += g.bytes_before; total.ba += g.bytes_after; }
  }
  return { rows, total };
}

/** What a change saved: smaller files as a size, pictures added as "+size", nothing to compare as "—". */
export function saved(before: number, after: number): string {
  if (!before) return after ? `+${size(after)}` : "—";
  return after > before ? `+${size(after - before)}` : size(before - after);
}

/** Where replaced media is: waiting, kept, quarantined, deleted. */
export function releaseLine(d: PicturesSummary): string {
  const g = (k: string) => d.release?.[k] ?? { n: 0, bytes: 0 };
  return `Replaced media: ${num(g("released").n)} waiting 2 days (${size(g("released").bytes)}) · ${num(g("kept").n)} kept because something else uses them · ${num(g("quarantined").n)} in quarantine (${size(g("quarantined").bytes)}) · ${num(g("deleted").n)} deleted for good (${size(g("deleted").bytes)}).`;
}

/** The quarantine folders that may be deleted, with the owner's go-ahead: a heading and one line each. */
export function folderLines(d: PicturesSummary): { head: string; items: string[] } | null {
  const f = d.folders ?? [];
  if (!f.length) return null;
  const bytes = f.reduce((n, x) => n + x.bytes, 0);
  return {
    head: `${f.length} quarantine folder${f.length === 1 ? "" : "s"} (${size(bytes)}) may be deleted with the owner’s go-ahead`,
    items: f.map((x) => `${x.dir}: ${num(x.n)} files, ${size(x.bytes)}, from ${x.purge_from} UTC`),
  };
}

export function rowsQuery(show: string, page: number, orderby: PictureSort, order: "asc" | "desc"): string {
  return new URLSearchParams({ show, page: String(page), orderby, order }).toString();
}
