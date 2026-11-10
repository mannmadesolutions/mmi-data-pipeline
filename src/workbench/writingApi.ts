/**
 * How Product Data writes to products, for the Workbench's writing settings
 * (Title proposals, Description and picture proposals) and Product Data runs:
 * the shapes /summary and /enrich/summary return (Store::summary(),
 * Enrichment::summary()), the one-line state shown on each settings box, and
 * the rows of what ran. Moved from the old Product Data page's "Writing and
 * history" section (onepage.js titleSettings, sourceSettings, runsTable).
 */

export interface ApplySettings { enabled: boolean; auto: boolean; batch_size: number; min_confidence: number }

export interface TitleRuleState { label: string; enabled: boolean; approved: boolean }

export interface RunState {
  status?: string;
  started_at?: string;
  completed_at?: string;
  last_error?: string | null;
  stats?: Record<string, unknown>;
  totals?: Record<string, number>;
  day?: string;
  applied_today?: number;
  applied_total?: number;
  failed_total?: number;
  last_run?: string;
}

/** Store::summary(): the parts the writing settings and runs read. */
export interface TitleSummary {
  apply: ApplySettings;
  rules: Record<string, TitleRuleState>;
  auto: number;
  today: string;
  gen_state: RunState;
  app_state: RunState;
  desc: { status: Record<string, number>; fetch: Record<string, number> };
  fetch: RunState;
  urls: { auto?: number; review?: number; approved?: number; last_run?: string };
  picker: RunState;
}

export interface EnrichWriteSummary {
  counts: Record<string, Record<string, number>>;
  rules: Record<string, { label: string; approved: boolean; retired?: boolean }>;
  settings: { enabled: boolean };
  state: { day?: string; today?: Record<string, number> | []; last_batch_at?: string };
  today: string;
}

export const onOff = (b: boolean): string => (b ? "On" : "Off");
const num = (n: number | undefined): string => (n ?? 0).toLocaleString("en-US");

/** "Writing titles On · auto-apply Off at confidence 85+ · 25 per batch" */
export function titlesState(a: ApplySettings): string {
  return `Writing titles ${onOff(a.enabled)} · auto-apply ${onOff(a.auto)} at confidence ${a.min_confidence}+ · ${a.batch_size} per batch`;
}

/** The built-in rules in use, and how many of them may auto-apply. */
export function autoRules(rules: Record<string, TitleRuleState>): { inUse: number; may: number } {
  const used = Object.values(rules).filter((r) => r.enabled);
  return { inUse: used.length, may: used.filter((r) => r.approved).length };
}

/** When an unreviewed proposal auto-applies, or why nothing does. */
export function autoGate(rules: Record<string, TitleRuleState>): string {
  const { inUse, may } = autoRules(rules);
  return may
    ? `An unreviewed proposal auto-applies when its confidence is at the floor or higher, every rule it uses may auto-apply (${may} of the ${inUse} rules in use may), it carries no warning flag and no other product has its title.`
    : "No rule may auto-apply yet, so nothing is written without review.";
}

/** The number fields within what Store::save_apply_settings() keeps; null when one is out of range. */
export function checkNumbers(batch: string, floor: string): { batch_size: number; min_confidence: number } | null {
  const b = Number(batch);
  const f = Number(floor);
  if (!Number.isInteger(b) || b < 1 || b > 50 || !Number.isInteger(f) || f < 50 || f > 100) return null;
  return { batch_size: b, min_confidence: f };
}

/** The description and picture sources still offered (retired ones are left out). */
export const liveSources = (e: EnrichWriteSummary): [string, { label: string; approved: boolean }][] =>
  Object.entries(e.rules ?? {}).filter(([, r]) => !r.retired);

/** How many rows a source has, every status together. */
export const sourceRows = (e: EnrichWriteSummary, id: string): number =>
  Object.values(e.counts?.[id] ?? {}).reduce((x, y) => x + y, 0);

/** "Writing to products On · 3 sources write without review" */
export function sourcesState(e: EnrichWriteSummary): string {
  const n = liveSources(e).filter(([, r]) => r.approved).length;
  return `Writing to products ${onOff(!!e.settings?.enabled)} · ${n} source${n === 1 ? "" : "s"} write${n === 1 ? "s" : ""} without review`;
}

/** "Today: 4 descriptions, 0 pictures written. Last batch: …" (today's counts only when the state is today's). */
export function enrichToday(e: EnrichWriteSummary): string {
  const st = e.state ?? {};
  const today = st.day === e.today && st.today && !Array.isArray(st.today) ? st.today : {};
  return `Today: ${num(today.content)} descriptions, ${num(today.image)} pictures written. Last batch: ${st.last_batch_at || "never"}`;
}

const words = (o: Record<string, number> | undefined): string =>
  Object.entries(o ?? {}).map(([k, v]) => `${k.replace(/_/g, " ")} ${num(v)}`).join(", ") || "—";

const stat = (s: Record<string, unknown> | undefined, k: string): string => {
  const v = s?.[k];
  return typeof v === "number" ? num(v) : "—";
};

/** What ran: label → value, as the old page's Recent runs listed it. */
export function runRows(s: TitleSummary): [string, string][] {
  const g = s.gen_state ?? {};
  const a = s.app_state ?? {};
  const gs = g.stats ?? {};
  const f = s.fetch ?? {};
  const u = s.urls ?? {};
  const p = s.picker ?? {};
  const when = (r: RunState, at?: string) => `${r.status || "never"}${at ? ` ${at}` : ""}`;
  return [
    ["Last composition", when(g, g.completed_at)],
    ["Products composed", stat(gs, "products")],
    ["Proposals", `${stat(gs, "proposals")} (new ${stat(gs, "new")}, changed ${stat(gs, "changed")}, reopened ${stat(gs, "reopened")}, decisions kept ${stat(gs, "kept_decisions")})`],
    ["Writing titles", onOff(!!s.apply?.enabled)],
    ["Last title batch", when(a, a.completed_at)],
    ["Titles written today", num(a.day === s.today ? a.applied_today : 0)],
    ["Titles written in total", num(a.applied_total)],
    ["Failed in total", num(a.failed_total)],
    ...(a.last_error ? [["Last error", a.last_error] as [string, string]] : []),
    ["Maker pages fetched", words(s.desc?.fetch)],
    ["Maker page fetch", `${when(f, f.completed_at || f.started_at)}${f.status === "complete" ? `: ${words(f.totals)}` : ""}`],
    ["Descriptions", words(s.desc?.status)],
    ["Maker links found in sitemaps", `${num(u.auto)} used automatically, ${num(u.review)} to review, ${num(u.approved)} approved; last search ${u.last_run || "never"}`],
    ["AI picks", `last run ${p.last_run || "never"}, ${num(p.totals?.picked)} picked, ${num(p.totals?.none)} none, waiting ${num(s.desc?.status?.needs_ai)}`],
  ];
}
