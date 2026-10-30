/**
 * Shapes of the Workbench AJAX responses (includes/controllers/ajax/WorkbenchController.php,
 * includes/workbench/class-product-workbench.php). Keep in step with those.
 */

export type ProductStatus = "publish" | "draft" | "pending" | "private" | string;
export type StockStatus = "instock" | "outofstock" | "onbackorder" | string;
export type HealthMode = "any" | "all" | "none";
export type QuickSearchIn = "title_sku" | "title" | "sku";
export type SortDir = "asc" | "desc";
export type MatchLogic = "all" | "any";
export type EditableTaxonomy = "product_cat" | "product_brand";

/** One condition as the shared condition builder collects it. */
export interface Condition {
  source: string;
  field: string;
  operator: string;
  value: string;
  [key: string]: unknown;
}

/** What mmi_workbench_search filters on (Product_Workbench::sanitize_filter()). */
export interface Filter {
  stock: string[];
  health: string[];
  health_mode: HealthMode;
  q: string;
  q_case: 0 | 1;
  q_in: QuickSearchIn;
  supplier: string;
  match_logic: MatchLogic;
  statuses: string[];
  conditions: Condition[];
}

export interface Term { id: number; name: string }

export interface ProductRow {
  id: number;
  title: string;
  status: ProductStatus;
  sku: string;
  stock: StockStatus;
  price: string;
  regular: string;
  sale: string;
  /** Null when the product doesn't manage stock (it has no quantity). */
  stock_qty: string | null;
  type: string;
  modified: string;
  /** The chosen meta columns: key → value, null when the product has no such key. */
  meta: Record<string, string | null>;
  categories: Term[];
  brands: Term[];
  supplier: string;
  health: string[];
  locked: EditableTaxonomy[];
  thumb: string;
  edit_url: string;
  view_url: string;
}

export interface Facet { id: number; name: string; count: number }

export interface SearchResult {
  total: number;
  page: number;
  pages: number;
  per_page: number;
  rows: ProductRow[];
  facets: Facet[];
  checks: Record<string, { chip: string; label: string }>;
}

export type Selection =
  | { mode: "ids"; ids: number[] }
  | { mode: "filter"; filter: Filter; exclude: number[] };

export interface PreviewSample { id: number; title: string; before: string; after: string; locked: boolean }

export interface Preview {
  selected: number;
  will_change: number;
  unchanged: number;
  summary: string;
  sample: PreviewSample[];
  locked: number;
  lock_label: string;
  lock_mode: "always" | "optional" | "";
}

export type JobStatus = "running" | "done" | "stopped" | "undoing" | "undone";

export interface Job {
  id: string;
  created_at: string;
  user: string;
  action: string;
  summary: string;
  filter_label: string;
  total: number;
  cursor: number;
  changed: number;
  unchanged: number;
  failed: number;
  skipped: number;
  skip_locked: boolean;
  add_lock: boolean;
  status: JobStatus;
  undo_cursor: number;
  undone: number;
  conflicts: number;
}

export interface BatchResponse { job: Job; busy?: boolean }

/** The tab's settings, printed by PHP as window.mmiWorkbench. */
export interface WorkbenchConfig {
  suppliers: { id: string; name: string }[];
  statuses: Record<string, string>;
  stock: Record<string, string>;
  healthChecks: { id: string; label: string }[];
  perPageOptions: number[];
  defaultPerPage: number;
  maxSelection: number;
  lockLabels: Record<string, string>;
  actionLockFields: Record<string, string[]>;
  /** Changes that only hold as a standing rule (Force In/Out of Stock). */
  stockActions: string[];
  /** WooCommerce's own meta keys (lowercase): shown as columns, never edited as meta. */
  reservedMetaKeys: string[];
  maxMetaColumns: number;
}

/** The shared action picker's current choice, read from its markup. */
export interface ActionChoice {
  action: string;
  params: Record<string, string>;
  /** "Set taxonomy term → Software Bundles (Replace)" */
  summary: string;
}

/** What the shared condition builder currently holds. */
export interface ConditionsState {
  conditions: Condition[];
  matchLogic: MatchLogic;
  /** 1-based position of the first row missing a source/field, or 0. */
  firstIncomplete: number;
  /** 1-based position of the first complete row still waiting for its value, or 0. */
  firstWithoutValue: number;
  /** One readable phrase per complete condition, for the job's "searched for" label; `or` = joined by OR. */
  phrases: { text: string; or: boolean }[];
}

/* ── Standing rules and catalog upkeep (StandingRulesController.php) ────── */

export type Grade = "ok" | "info" | "review" | "inefficient";

/** One thing the efficacy check found (Rule_Efficacy). */
export interface Finding { code: string; severity: Grade; text: string; fix: string; count: number }

export interface Efficacy { grade: Grade; matched: number; will_change: number; findings: Finding[]; at?: number }

export interface RuleRun { at: number; matched: number; changed: number; deferred: number; ms: number }

/** A standing rule as mmi_workbench_rules lists it (Standing_Rules::listing()). */
export interface StandingRule {
  id: string;
  position: number;
  name: string;
  enabled: boolean;
  action: string;
  params: Record<string, string>;
  stock: boolean;
  change: string;
  search: string;
  filter: Filter;
  efficacy: Efficacy | null;
  last_run: RuleRun | null;
}

export interface HoldResult { matched: number; changed: number; released: number; deferred: number }

export interface UpkeepStep {
  phase: string;
  label: string;
  about: string;
  kind: string;
  /** mmi_catalog_rules key this step's switch writes, or "" when it has none. */
  switch: string;
  on: boolean | null;
  skipped: boolean;
  skip_reason: string;
}

/** Catalog upkeep's state (mmi_workbench_upkeep). */
export interface Upkeep {
  enabled: boolean;
  schedule: { frequency: string; time: string; next: string; options: Record<string, string> };
  steps: UpkeepStep[];
  last_run: { at: string; ago: string; duration: string; processed: number } | null;
  health: { level: "ok" | "disabled" | "warning" | "error"; text: string; detail: string };
  running: { owner: string; started_at: string } | null;
}

/** One phase of a catalog run as mmi_catalog_start_run lists it. */
export interface RunPhase { phase: string; label: string; skipped: boolean; skip_reason: string }

/** mmi_catalog_run_phase's reply (Catalog_Phase_Runner::run_phase()). */
export interface PhaseResult { skipped?: boolean; error?: string; message?: string; items_processed?: number; duration_ms?: number }
