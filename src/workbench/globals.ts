/**
 * The page globals the Workbench works with: the shared library's jQuery
 * components and the settings PHP prints. Everything is read through here so
 * the rest of the code never touches `window` directly.
 */
import type { Condition, WorkbenchConfig } from "./types";

export interface RuleBuilderApi {
  SELECTORS: Record<string, string>;
  CHANGE_EVENT: string;
  collectConditions: ($list: JQuery) => Condition[];
  isConditionComplete: ($row: JQuery) => boolean;
  appendConditionRow: ($list: JQuery) => JQuery | null;
  initConditionCascade: ($row: JQuery, source: string, field: string, operator: string, value: string) => void;
  updateActionParamsVisibility: ($scope: JQuery) => void;
  loadTaxonomyTerms: ($group: JQuery, taxonomy: string, savedTermId: string) => void;
  collectActionParams: ($scope: JQuery) => Record<string, string>;
}

export interface ConditionBuilderApi {
  loadConditions: ($root: JQuery, conditions: Condition[], matchLogic: string, replace: boolean) => { loaded: number; skipped: string[] };
  showNotice: ($root: JQuery, text: string) => void;
  updateEmptyHint: ($root: JQuery) => void;
  refreshLibrary: () => void;
}

export interface Pager {
  setTotalPages: (n: number) => void;
  setPage: (n: number) => void;
}

export interface NoncesApi {
  watch: (action: string, target: object, key?: string) => void;
  fetch: (action: string, url: string, body: URLSearchParams, init?: RequestInit) => Promise<Response>;
}

interface WorkbenchWindow {
  jQuery?: JQueryStatic;
  MMIRuleBuilder?: RuleBuilderApi;
  MMIConditionBuilder?: ConditionBuilderApi;
  MMIPagination?: { init: (o: { container: Element; totalPages: number; page: number; onPageChange: (p: number) => void }) => Pager };
  MMINonces?: NoncesApi;
  MMI_ColumnResizer?: { initTable: ($table: JQuery) => void; getTableId?: ($table: JQuery) => string };
  mmiRuleBuilder?: { ajaxUrl: string; nonce: string };
  mmiConditionBuilder?: { nonce: string };
  mmiWorkbench?: WorkbenchConfig;
}

export const page = (): WorkbenchWindow => window as unknown as WorkbenchWindow;

/** The nonce action every Workbench endpoint checks (check_ajax_referer in WorkbenchController.php). */
export const NONCE_ACTION = "mmi_pipeline_import_settings";
