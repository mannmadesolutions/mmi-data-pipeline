/**
 * Issues a product shows in the Workbench and the fixes made in its row
 * (Product Data's Issues: GET products/{id}/fix-context, POST products/{id}/fix).
 * Pure helpers here; the panels are IssueFixes.tsx.
 */
import { rest, type TitleRulesConfig } from "./titleRulesApi";
import type { ProposalRow } from "./proposalsApi";

export type FixKind = "brand" | "cats" | "sku" | "describe" | "title" | "image" | "action";

export interface Fix { kind: FixKind; label: string; action?: string; primary?: boolean }

export interface Issue { key: string; label: string; help: string; tone: "error" | "warning" | "info"; fixes: Fix[] }

/** What a supplier sends for a brand or category, and where it maps now. */
export interface FeedValue { supplier: string; label: string; field: string; value: string; mapped: { id: number; name: string } | null; others: number }

export interface BrandContext { id: number; title: string; can_create: boolean; feed: FeedValue[]; similar: { id: number; name: string; score: number; count: number }[]; suggested: string }
export interface CatsContext { id: number; title: string; can_create: boolean; feed: FeedValue[] }
export interface CopyBlock { index: number; type: string; label: string; url: string; text: string }
export interface DescribeContext { id: number; title: string; blocks: CopyBlock[] }

export interface FixResult {
  id: number;
  message: string;
  row: ProposalRow | null;
  removed: boolean;
  missing: string[];
  /** The supplier's other products a mapping gave the term; ids: those whose rows to redraw (their proposals are recomposed already). */
  others?: { updated: number; locked: number; queued: boolean; ids?: number[] };
}

export interface NewBrand { name: string; slug: string; description: string; logo_id: number; website: string }

/** The supplier value key the server expects in `remember`. */
export const feedKey = (f: FeedValue): string => `${f.supplier}|${f.field}`;

/**
 * Supplier values worth mapping to the chosen term: those not mapped to it already.
 * Ticked by default when unmapped (a value nobody mapped yet is why the product has none).
 */
export function mappable(feed: FeedValue[], termId: number | null): FeedValue[] {
  return feed.filter((f) => !f.mapped || (termId !== null && f.mapped.id !== termId));
}

/** "Freqport" from Xchange, and what saving the mapping does for the supplier's other products. */
export function mapLine(f: FeedValue, target: string): string {
  const others = f.others ? ` ${f.others} other product${f.others === 1 ? "" : "s"} from ${f.label} sending it get${f.others === 1 ? "s" : ""} it now;` : "";
  const was = f.mapped ? ` (now mapped to ${f.mapped.name})` : "";
  return `Map ${f.label}’s “${f.value}”${was} to ${target || "it"}:${others} future imports too.`;
}

/** The issue chips' order: errors, then warnings, then notes; the server's order within each. */
export function sortIssues(list: Issue[]): Issue[] {
  const rank = { error: 0, warning: 1, info: 2 } as const;
  return [...list].sort((a, b) => rank[a.tone] - rank[b.tone]);
}

/** A slug like WordPress makes it, for the placeholder only (the server makes the real one). */
export const slugify = (s: string): string => s.toLowerCase().normalize("NFKD").replace(/[̀-ͯ]/g, "").replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");

export const fixContext = <T>(cfg: TitleRulesConfig, id: number, kind: "brand" | "cats" | "describe") =>
  rest<T>(cfg, "GET", `products/${id}/fix-context?kind=${kind}`);

export const runFix = (cfg: TitleRulesConfig, id: number, body: Record<string, unknown>) =>
  rest<FixResult>(cfg, "POST", `products/${id}/fix`, body);

/** The Workbench's health checks that have a fix panel, and which. */
export const HEALTH_FIX: Record<string, FixKind> = { no_brand: "brand", no_category: "cats", no_sku: "sku" };

/** One alert across the rows shown: what it is, its fixes, and the products that carry it. */
export interface IssueGroup extends Issue { ids: number[] }

/**
 * The alerts on a page, alike ones together, for fixing them all at once (2026-11-12): errors first, then
 * warnings, then notes; within a tone the commonest first. A row's own wording of an alert can differ (a store
 * names its bundles category); the first row's is shown.
 */
export function groupIssues(rows: { id: number; issues?: Issue[] }[]): IssueGroup[] {
  const by = new Map<string, IssueGroup>();
  for (const r of rows) {
    for (const i of r.issues ?? []) {
      const g = by.get(i.key);
      if (g) { if (!g.ids.includes(r.id)) g.ids.push(r.id); } else by.set(i.key, { ...i, ids: [r.id] });
    }
  }
  const rank = { error: 0, warning: 1, info: 2 } as const;
  return [...by.values()].sort((a, b) => rank[a.tone] - rank[b.tone] || b.ids.length - a.ids.length || a.label.localeCompare(b.label));
}

/** What a fix does for every product in a group: run it for each, open the bulk picker on them, or show them to do one by one. */
export function groupMode(f: Fix): "each" | "terms" | "one-by-one" {
  if (f.kind === "action" && f.action) return "each";
  if (f.kind === "cats" || f.kind === "brand") return "terms";
  return "one-by-one";
}
