/**
 * Typed client for the Workbench's admin-ajax endpoints.
 *
 * Requests go through the shared MMINonces helper, so a tab left open past the
 * nonce's 12–24 h life refreshes it and resends instead of failing every call.
 * Bodies use jQuery.param's bracket encoding (filter[conditions][0][field]=…),
 * which is what the PHP handlers read from $_POST.
 */
import { NONCE_ACTION, page } from "./globals";
import { MESSAGES } from "./messages";
import type {
  BatchResponse, Efficacy, Filter, HoldResult, Job, PhaseResult, Preview, ProductRow, RunPhase, SearchResult, Selection, SortDir, StandingRule, Upkeep,
} from "./types";

export type ApiErrorKind = "server" | "network" | "auth" | "abort";

export class ApiError extends Error {
  constructor(message: string, readonly kind: ApiErrorKind) {
    super(message);
    this.name = "ApiError";
  }
}

type Scalar = string | number | boolean | null | undefined;
export type FormValue = Scalar | FormValue[] | { [key: string]: FormValue };

/** Encodes nested data the way jQuery.param does, so PHP sees the same $_POST arrays. */
export function toFormBody(data: Record<string, FormValue>): URLSearchParams {
  const body = new URLSearchParams();
  const add = (key: string, value: FormValue): void => {
    if (Array.isArray(value)) {
      value.forEach((v, i) => add(`${key}[${v !== null && typeof v === "object" ? i : ""}]`, v));
    } else if (value !== null && typeof value === "object") {
      for (const [k, v] of Object.entries(value)) add(`${key}[${k}]`, v);
    } else {
      // jQuery.param: null/undefined → "", booleans → "true"/"false".
      body.append(key, value == null ? "" : String(value));
    }
  };
  for (const [k, v] of Object.entries(data)) add(k, v);
  return body;
}

/** Turns a WordPress AJAX reply into its data, or an ApiError that says what went wrong. */
export async function readReply<T>(r: Response): Promise<T> {
  // check_ajax_referer() answers a stale nonce with 403 "-1"; a logged-out session gets 400 "0".
  if (r.status === 403 || r.status === 400) {
    const text = await r.text().catch(() => "");
    if (text.trim() === "-1" || text.trim() === "0") throw new ApiError(MESSAGES.LOGIN_EXPIRED, "auth");
    throw new ApiError(messageFrom(text) ?? MESSAGES.NETWORK, r.status === 403 ? "auth" : "server");
  }
  if (!r.ok) throw new ApiError(MESSAGES.NETWORK, "network");
  let json: { success?: boolean; data?: unknown };
  try { json = await r.json(); } catch { throw new ApiError(MESSAGES.NETWORK, "network"); }
  if (!json.success) {
    const msg = (json.data as { message?: string } | undefined)?.message;
    throw new ApiError(msg || MESSAGES.NETWORK, "server");
  }
  return json.data as T;
}

function messageFrom(text: string): string | null {
  try {
    const j = JSON.parse(text) as { data?: { message?: string } };
    return j.data?.message ?? null;
  } catch {
    return null;
  }
}

/** POSTs one admin-ajax action. Rejects with ApiError; an aborted request rejects with kind "abort". */
export async function post<T>(action: string, data: Record<string, FormValue> = {}, signal?: AbortSignal): Promise<T> {
  const w = page();
  const url = w.mmiRuleBuilder?.ajaxUrl ?? "/wp-admin/admin-ajax.php";
  const body = toFormBody({ action, ...data });
  let r: Response;
  try {
    if (w.MMINonces) {
      r = await w.MMINonces.fetch(NONCE_ACTION, url, body, { signal, credentials: "same-origin" });
    } else {
      body.set("nonce", w.mmiRuleBuilder?.nonce ?? "");
      r = await fetch(url, { method: "POST", body, signal, credentials: "same-origin" });
    }
  } catch (e) {
    if (signal?.aborted || (e as Error)?.name === "AbortError") throw new ApiError("aborted", "abort");
    throw new ApiError(MESSAGES.NETWORK, "network");
  }
  return readReply<T>(r);
}

/* ── Endpoints ──────────────────────────────────────────────────────────── */

export const api = {
  search: (filter: Filter, o: { page: number; perPage: number; sort: string; dir: SortDir; fresh: boolean; metaCols?: string[] }, signal?: AbortSignal) =>
    post<SearchResult>("mmi_workbench_search", {
      filter: filter as unknown as FormValue, page: o.page, per_page: o.perPage, sort: o.sort, dir: o.dir, fresh: o.fresh ? 1 : 0,
      meta_cols: o.metaCols ?? [],
    }, signal),

  preview: (selection: Selection, action: string, params: Record<string, string>) =>
    post<Preview>("mmi_workbench_preview", { selection: selection as unknown as FormValue, wb_action: action, params }),

  start: (selection: Selection, action: string, params: Record<string, string>, filterLabel: string, lock: { skip: boolean; add: boolean }) =>
    post<{ job: Job }>("mmi_workbench_start", {
      selection: selection as unknown as FormValue, wb_action: action, params, filter_label: filterLabel,
      skip_locked: lock.skip ? 1 : 0, add_lock: lock.add ? 1 : 0,
    }),

  run: (jobId: string, resume = false) => post<BatchResponse>("mmi_workbench_run", { job_id: jobId, ...(resume ? { resume: 1 } : {}) }),
  undo: (jobId: string) => post<BatchResponse>("mmi_workbench_undo", { job_id: jobId }),
  stop: (jobId: string) => post<{ job: Job }>("mmi_workbench_stop", { job_id: jobId }),
  jobs: () => post<{ jobs: Job[] }>("mmi_workbench_jobs"),

  terms: (taxonomy: string) =>
    post<{ terms: { id: number; name: string; parent?: number }[] }>("mmi_pipeline_get_taxonomy_terms", { taxonomy }),

  setTerms: (productId: number, taxonomy: string, termIds: number[], metaCols: string[] = []) =>
    post<{ row: ProductRow }>("mmi_workbench_set_terms", { product_id: productId, taxonomy, term_ids: termIds, meta_cols: metaCols }),

  /** Edit one cell: "title", "regular", "sale", "stock_qty" or "meta:{key}". */
  setField: (productId: number, field: string, value: string, lock: boolean, metaCols: string[] = []) =>
    post<{ row: ProductRow }>("mmi_workbench_set_field", { product_id: productId, field, value, lock: lock ? 1 : 0, meta_cols: metaCols }),

  saveSearch: (label: string, matchLogic: string, conditions: unknown[]) =>
    post<{ id: string }>("mmi_workbench_save_search", { label, match_logic: matchLogic, conditions: conditions as FormValue }),

  /* Standing rules */
  rules: () => post<{ rules: StandingRule[] }>("mmi_workbench_rules"),
  ruleCheck: (r: RuleDraft) => post<Efficacy>("mmi_workbench_rule_check", ruleBody(r)),
  ruleSave: (r: RuleDraft) => post<{ rule: { id: string; name: string }; efficacy: Efficacy | null }>("mmi_workbench_rule_save", ruleBody(r)),
  ruleEnable: (id: string, enabled: boolean) => post<null>("mmi_workbench_rule_enable", { rule_id: id, enabled: enabled ? 1 : 0 }),
  ruleMove: (id: string, by: -1 | 1) => post<null>("mmi_workbench_rule_move", { rule_id: id, by }),
  ruleDelete: (id: string) => post<null>("mmi_workbench_rule_delete", { rule_id: id }),
  ruleHold: (id: string) => post<HoldResult>("mmi_workbench_rule_hold", { rule_id: id }),

  /* Catalog upkeep (its writes are the endpoints the scheduled run shares) */
  upkeep: () => post<Upkeep>("mmi_workbench_upkeep"),
  upkeepEnable: (enabled: boolean) => post<{ enabled: boolean }>("mmi_toggle_catalog_maintenance", { enabled: enabled ? 1 : "" }),
  upkeepSwitches: (switches: Record<string, boolean>) =>
    post<{ rules: Record<string, boolean> }>("mmi_save_catalog_rules", { toggles: Object.fromEntries(Object.entries(switches).map(([k, v]) => [k, v ? 1 : ""])) }),
  upkeepSchedule: (frequency: string, time: string) =>
    post<unknown>("mmi_autosave_schedule", { process: "catalog_update", frequency, time }),
  catalogStart: () => post<{ run_id: string; phases: RunPhase[]; total: number }>("mmi_catalog_start_run"),
  catalogPhase: (runId: string, index: number) => post<PhaseResult>("mmi_catalog_run_phase", { run_id: runId, index }),
  catalogFinish: (runId: string, aborted = false) => post<unknown>("mmi_catalog_finish_run", { run_id: runId, ...(aborted ? { aborted: 1 } : {}) }),
};

/** A search + change about to become (or replace) a standing rule. */
export interface RuleDraft { id: string; name: string; filter: Filter; action: string; params: Record<string, string> }

const ruleBody = (r: RuleDraft): Record<string, FormValue> =>
  ({ rule_id: r.id, name: r.name, filter: r.filter as unknown as FormValue, wb_action: r.action, params: r.params });
