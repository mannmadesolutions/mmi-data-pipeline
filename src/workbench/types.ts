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
