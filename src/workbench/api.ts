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
import type { BatchResponse, Filter, Job, Preview, ProductRow, SearchResult, Selection, SortDir } from "./types";

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
  search: (filter: Filter, o: { page: number; perPage: number; sort: string; dir: SortDir; fresh: boolean }, signal?: AbortSignal) =>
    post<SearchResult>("mmi_workbench_search", {
      filter: filter as unknown as FormValue, page: o.page, per_page: o.perPage, sort: o.sort, dir: o.dir, fresh: o.fresh ? 1 : 0,
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

  setTerms: (productId: number, taxonomy: string, termIds: number[]) =>
    post<{ row: ProductRow }>("mmi_workbench_set_terms", { product_id: productId, taxonomy, term_ids: termIds }),

  saveSearch: (label: string, matchLogic: string, conditions: unknown[]) =>
    post<{ id: string }>("mmi_workbench_save_search", { label, match_logic: matchLogic, conditions: conditions as FormValue }),
};
