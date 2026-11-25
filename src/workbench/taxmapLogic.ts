/**
 * Category & brand mapping (TaxonomyMapping.tsx): the pure part. A supplier's brand and category values, each
 * mapped to a store term (or skipped, or excluded), as TaxonomyMappingController.php's scan returns them; alias rules
 * that catch spellings; the helpers the term picker and the create-term editor use. Ported from the Import tab's
 * taxonomy-mapping.js (2026-11-25).
 */

/* ── Shapes ──────────────────────────────────────────────────────────────── */

/** A profile-only override of one value's mapping. */
export interface Variant {
  mapping_id: number;
  profile_id: string;
  profile_label: string;
  wc_term_id: number;
  wc_term_name: string;
  missing_term_id?: number;
}

/** One supplier value, as mmi_scan_all_sources returns it. */
export interface ValueRow {
  source_value: string;
  source_field: string;
  /** The taxonomy it maps into (a redirect's, when redirected). */
  wc_taxonomy: string;
  /** The taxonomy its field feeds, when the value is redirected to another ("" otherwise). */
  redirected_from: string;
  supplier: string;
  /** Records in the feed with this value. */
  count: number;
  mapping_id: number;
  /** 0 = unmapped, -1 = skip. */
  wc_term_id: number;
  wc_term_name: string;
  /** The saved term was deleted: its old id (the mapping does nothing until replaced). */
  missing_term_id: number;
  auto_create: boolean;
  /** Mapped by an alias rule, not a saved row. */
  via_rule: boolean;
  source_file: string;
  sample_products: string[];
  variants: Variant[];
  excluded: boolean;
  excluded_at: string;
  excluded_by: string;
}

/** A source the mapper lists: a supplier field that feeds a taxonomy (get_taxonomy_mapping_sources()). */
export interface SourceDef {
  supplier: string;
  source_field: string;
  wc_taxonomy: string;
  label: string;
  /** No import profile maps the field yet (declared in Configure › Taxonomies, or a template's). */
  suggested: boolean;
}

export interface AliasRule {
  label: string;
  supplier_id: string;
  source_field: string;
  operator: RuleOperator;
  value: string;
  wc_taxonomy: string;
  wc_term_id: number;
  wc_term_name: string;
  missing_term_id?: number;
}

export type RuleOperator = "contains" | "starts_with" | "ends_with" | "equals" | "regex";

export const RULE_OPERATORS: { value: RuleOperator; label: string }[] = [
  { value: "contains", label: "Contains" },
  { value: "starts_with", label: "Starts with" },
  { value: "ends_with", label: "Ends with" },
  { value: "equals", label: "Equals (exact)" },
  { value: "regex", label: "Regex (advanced)" },
];

/* ── Rows ────────────────────────────────────────────────────────────────── */

export const rowKey = (r: Pick<ValueRow, "supplier" | "source_field" | "source_value">): string => `${r.supplier}|${r.source_field}|${r.source_value}`;
export const sourceKey = (s: { supplier: string; source_field: string }): string => `${s.supplier}|${s.source_field}`;

export type Status = "excluded" | "skip" | "mapped" | "unmapped";

export function statusOf(r: ValueRow): Status {
  if (r.excluded) return "excluded";
  if (r.wc_term_id === -1) return "skip";
  return r.wc_term_id !== 0 ? "mapped" : "unmapped";
}

/** The saved term, or a variation's, was deleted in WooCommerce. */
export const hasMissingTerm = (r: ValueRow): boolean => r.missing_term_id > 0 || r.variants.some((v) => (v.missing_term_id ?? 0) > 0);

export const STATUS_LABEL: Record<Status, string> = { excluded: "Excluded", skip: "Skip", mapped: "Mapped", unmapped: "Unmapped" };

/** The status badge's words: a rule's mapping and a deleted term say so. */
export function statusLabel(r: ValueRow): string {
  const s = statusOf(r);
  if (s === "mapped" && r.via_rule) return "Mapped (rule)";
  if (s === "unmapped" && r.missing_term_id > 0) return "Term deleted";
  return STATUS_LABEL[s];
}

export type Chip = "unmapped" | "mapped" | "missing" | "excluded" | "all";

export const CHIPS: { value: Chip; label: string; hint: string }[] = [
  { value: "unmapped", label: "Unmapped", hint: "Values no term is mapped to: imports leave their products without this taxonomy's term" },
  { value: "mapped", label: "Mapped", hint: "Values mapped to a term, by a saved mapping or an alias rule, or skipped on purpose" },
  { value: "missing", label: "Deleted terms", hint: "Mappings whose term was deleted in WooCommerce: imports skip the value until you pick another term" },
  { value: "excluded", label: "Excluded", hint: "Values left out of the mapper (a supplier's test vendor): imports leave them unmapped until restored" },
  { value: "all", label: "All", hint: "Every value except the excluded ones" },
];

export function chipMatches(r: ValueRow, chip: Chip): boolean {
  const s = statusOf(r);
  switch (chip) {
    case "unmapped": return s === "unmapped";
    case "mapped": return s === "mapped" || s === "skip";
    case "missing": return s !== "excluded" && hasMissingTerm(r);
    case "excluded": return s === "excluded";
    default: return s !== "excluded";
  }
}

export function chipCounts(rows: ValueRow[]): Record<Chip, number> {
  const out: Record<Chip, number> = { unmapped: 0, mapped: 0, missing: 0, excluded: 0, all: 0 };
  for (const r of rows) for (const c of CHIPS) if (chipMatches(r, c.value)) out[c.value]++;
  return out;
}

/** The rows of one source ("" = every source). */
export const inSource = (rows: ValueRow[], key: string): ValueRow[] => (key ? rows.filter((r) => sourceKey(r) === key) : rows);

export type SortCol = "value" | "count";
export interface Sort { col: SortCol; desc: boolean }

/** The rows a chip and a search leave, in the chosen order (most products first when none). */
export function visibleRows(rows: ValueRow[], chip: Chip, q: string, sort: Sort | null): ValueRow[] {
  const needle = q.trim().toLowerCase();
  const list = rows.filter((r) => chipMatches(r, chip)
    && (!needle || r.source_value.toLowerCase().includes(needle) || r.sample_products.some((p) => p.toLowerCase().includes(needle))
      || r.wc_term_name.toLowerCase().includes(needle)));
  const s = sort ?? { col: "count", desc: true };
  const dir = s.desc ? -1 : 1;
  const key = (r: ValueRow): number | string => (s.col === "count" ? r.count : r.source_value.toLowerCase());
  return [...list].sort((a, b) => {
    const va = key(a);
    const vb = key(b);
    return va < vb ? -dir : va > vb ? dir : a.source_value.localeCompare(b.source_value);
  });
}

/** Click a heading: products start most first, the rest A–Z; a second click flips, a third goes back to none. */
export function nextSort(s: Sort | null, col: SortCol): Sort | null {
  const firstDesc = col === "count";
  if (!s || s.col !== col) return { col, desc: firstDesc };
  return s.desc === firstDesc ? { col, desc: !s.desc } : null;
}

/** One row changed (a save, a clear, an exclusion): the list with it replaced. */
export const replaceRow = (rows: ValueRow[], key: string, next: (r: ValueRow) => ValueRow): ValueRow[] => rows.map((r) => (rowKey(r) === key ? next(r) : r));

/* ── Alias rules ─────────────────────────────────────────────────────────── */

/** Whether a rule's value catches a source value (MMI_DB::match_taxmap_alias_rule() decides the same at import). */
export function ruleValueMatches(operator: RuleOperator, ruleValue: string, sourceValue: string): boolean {
  if (!ruleValue) return false;
  if (operator === "regex") {
    try {
      const m = ruleValue.match(/^\/(.*)\/([a-z]*)$/i);
      return (m ? new RegExp(m[1]!, m[2]) : new RegExp(ruleValue)).test(sourceValue);
    } catch {
      return false;
    }
  }
  const hay = sourceValue.toLowerCase();
  const needle = ruleValue.toLowerCase();
  switch (operator) {
    case "equals": return hay === needle;
    case "starts_with": return hay.startsWith(needle);
    case "ends_with": return hay.endsWith(needle);
    default: return hay.includes(needle);
  }
}

/** How many listed values a rule catches, and how many of them have no mapping yet; null while it can't match anything. */
export function ruleMatches(rule: AliasRule, rows: ValueRow[]): { total: number; unmapped: number } | null {
  if (!rule.value || !rule.wc_taxonomy) return null;
  let total = 0;
  let unmapped = 0;
  for (const r of rows) {
    if (r.wc_taxonomy !== rule.wc_taxonomy) continue;
    if (rule.supplier_id && r.supplier !== rule.supplier_id) continue;
    if (rule.source_field && r.source_field !== rule.source_field) continue;
    if (!ruleValueMatches(rule.operator, rule.value, r.source_value)) continue;
    total++;
    if (r.wc_term_id === 0) unmapped++;
  }
  return { total, unmapped };
}

export const EMPTY_RULE: AliasRule = { label: "", supplier_id: "", source_field: "", operator: "contains", value: "", wc_taxonomy: "product_brand", wc_term_id: 0, wc_term_name: "" };

/* ── New term names ──────────────────────────────────────────────────────── */

const TITLE_SMALL_WORDS = new Set(["a", "an", "and", "as", "at", "by", "for", "from", "in", "of", "on", "or", "the", "to", "vs", "with"]);

/**
 * Capitalizes each word. Connector words after the first stay lowercase; words of 1-2 letters or with no vowels stay
 * uppercase, since in brand and category names those are nearly always acronyms (IK, TC, DJ, KRK, MK2).
 */
export function toTitleCase(value: string): string {
  let index = 0;
  return value.replace(/[\p{L}\p{N}']+/gu, (word) => {
    const lower = word.toLowerCase();
    const first = index++ === 0;
    if (!first && TITLE_SMALL_WORDS.has(lower)) return lower;
    if (/\p{L}/u.test(word) && (word.length <= 2 || !/[aeiouy]/i.test(word))) return word.toUpperCase();
    return lower.charAt(0).toUpperCase() + lower.slice(1);
  });
}

/** All-caps values with a word of 4+ characters are title-cased ("ROLAND" → "Roland"); short ones are usually acronyms ("AKG"). */
export function suggestTermName(value: string): string {
  const longWord = (value.match(/[\p{L}\p{N}']+/gu) ?? []).some((w) => w.length >= 4);
  return /\p{Lu}/u.test(value) && !/\p{Ll}/u.test(value) && longWord ? toTitleCase(value) : value;
}

export type CaseMode = "title" | "upper" | "lower" | "source";

export const CASE_BUTTONS: { mode: CaseMode; label: string; title: string }[] = [
  { mode: "title", label: "Title Case", title: "Capitalize each word; short words without vowels (DJ, KRK) stay uppercase" },
  { mode: "upper", label: "UPPER", title: "All uppercase" },
  { mode: "lower", label: "lower", title: "All lowercase" },
  { mode: "source", label: "As source", title: "Exactly as the source value" },
];

export function applyCase(mode: CaseMode, value: string, sourceValue: string): string {
  if (mode === "title") return toTitleCase(value);
  if (mode === "upper") return value.toUpperCase();
  if (mode === "lower") return value.toLowerCase();
  return sourceValue;
}

/* ── Term picker ─────────────────────────────────────────────────────────── */

export interface TreeTerm { id: number; name: string; path: string; depth: number; count: number }
export interface FlatTerm { id: number; name: string; count: number }
/** A hierarchical taxonomy arrives whole as a tree (filtered here); a flat one is searched on the server. */
export type FoundTerms = { tree: TreeTerm[] } | { terms: FlatTerm[] };

export type TreeKind = "match" | "child" | "context" | "all";

/**
 * The tree rows for a query: every term when it is empty; otherwise each term whose name matches, its ancestors
 * (where it sits) and its descendants (typing a parent lists its children). The tree comes depth-first, so one pass
 * with an ancestor stack is enough.
 */
export function filterTermTree(tree: TreeTerm[], query: string): { term: TreeTerm; kind: TreeKind }[] {
  const q = query.trim().toLowerCase();
  if (!q) return tree.map((term) => ({ term, kind: "all" as const }));
  const kinds = new Map<number, TreeKind>();
  const stack: number[] = [];
  let underHit = -1;
  tree.forEach((term, i) => {
    stack.length = term.depth;
    if (underHit >= 0 && term.depth <= underHit) underHit = -1;
    if (term.name.toLowerCase().includes(q)) {
      kinds.set(i, "match");
      for (const a of stack) if (!kinds.has(a)) kinds.set(a, "context");
      if (underHit < 0) underHit = term.depth;
    } else if (underHit >= 0) {
      kinds.set(i, "child");
    }
    stack.push(i);
  });
  return tree.flatMap((term, i) => (kinds.has(i) ? [{ term, kind: kinds.get(i)! }] : []));
}

/** The names a picker shows, for the "Create term" offer (none when the typed name already exists). */
export const foundNames = (f: FoundTerms): string[] => ("tree" in f ? f.tree.map((t) => t.name) : f.terms.map((t) => t.name));

/** Offer to create the typed name: two characters or more, and no term has it already (any case). */
export function offerCreate(f: FoundTerms, query: string): string {
  const name = query.trim();
  return name.length >= 2 && !foundNames(f).some((n) => n.toLowerCase() === name.toLowerCase()) ? name : "";
}
