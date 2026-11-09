/**
 * Title rules a store writes (Product Data: CustomRules), for the Workbench's
 * Title rules section: types, the REST client, and the pure helpers the
 * section and its tests use.
 *
 * The routes are WordPress REST (mmpt/v1). The REST nonce isn't printed into
 * the page: it is fetched from WordPress's own rest-nonce action on first use
 * and fetched again when the server says it has expired, so a tab left open
 * for a day keeps working.
 */
import { ApiError } from "./api";
import { MESSAGES } from "./messages";

export interface TitleRulesConfig {
  /** Site-relative REST base, ending in "/" (e.g. "/wp-json/mmpt/v1/"). */
  rest: string;
  /** Site-relative admin-ajax URL of WordPress's rest-nonce action. */
  nonceUrl: string;
}

export type RuleKind = "replace" | "remove" | "insert" | "qualifier" | "pattern";

export const KINDS: { value: RuleKind; label: string; from: string; to: string; toHint: string }[] = [
  { value: "replace", label: "Replace words", from: "Words to look for", to: "Replace with", toHint: "" },
  { value: "remove", label: "Remove words", from: "Words to remove", to: "", toHint: "" },
  { value: "insert", label: "Add words after", from: "After these words", to: "Add", toHint: "" },
  { value: "qualifier", label: "Move into the parentheses", from: "Words to move", to: "Write them as", toHint: "Leave empty to keep them as found" },
  { value: "pattern", label: "Pattern (advanced)", from: "Regular expression", to: "Replace with", toHint: "$1, $2 … repeat what the brackets matched; empty deletes the match" },
];

export interface OwnRule {
  id: string;
  label: string;
  kind: RuleKind;
  from: string;
  /** As typed: for "insert", only the words added. */
  to: string;
  brand: string;
  at: string;
  by: string;
  example: number;
  case: string;
  categories: number[];
  suppliers: string[];
  scope: string;
  /** Where it applies in order, 1 = first. */
  position: number;
}

export interface RuleDraft {
  kind: RuleKind;
  from: string;
  to: string;
  brand: string;
  anyCase: boolean;
  categories: string[];
  suppliers: string[];
}

export interface RulePreview {
  label: string;
  count: number;
  scanned: number;
  unstable: number;
  samples: { id: number; before: string; after: string }[];
}

export interface TermOption { id: number; name: string; path: string; depth: number }

export const EMPTY_DRAFT: RuleDraft = { kind: "replace", from: "", to: "", brand: "", anyCase: false, categories: [], suppliers: [] };

/** A saved rule as a form draft. */
export function ruleToDraft(r: OwnRule): RuleDraft {
  return {
    kind: r.kind, from: r.from, to: r.kind === "qualifier" && r.to === r.from ? "" : r.to, brand: r.brand,
    anyCase: r.case === "any", categories: r.categories.map(String), suppliers: [...r.suppliers],
  };
}

/** The body the preview, add and update routes read. */
export function draftToBody(d: RuleDraft): Record<string, unknown> {
  return {
    kind: d.kind, from: d.from, to: d.kind === "remove" ? "" : d.to, brand: d.brand,
    case: d.anyCase ? "any" : "", categories: d.categories.map(Number), suppliers: d.suppliers,
  };
}

/** Whether a draft has what its kind needs before it is worth asking the server. */
export function draftReady(d: RuleDraft): boolean {
  if (d.from.trim() === "") return false;
  if (d.kind === "replace" || d.kind === "insert") return d.to.trim() !== "";
  return true;
}

/** One line for the preview: what saving would do. */
export function previewSummary(p: RulePreview): string {
  const n = p.count;
  const head = n === 0 ? "Would change no product titles" : `Would change ${n} product title${n === 1 ? "" : "s"}`;
  return `${head} (${p.scanned} checked).`;
}

/** Why a pattern skipped titles, when it did. */
export function unstableNote(p: RulePreview): string {
  if (!p.unstable) return "";
  return `Skipped on ${p.unstable} title${p.unstable === 1 ? "" : "s"}: the pattern would change its own result again on every run `
    + "(for example \"Pro\" → \"Pro Edition\"). Add a look-ahead so it stops once done, such as Pro(?! Edition).";
}

/** Category options for the multi-select: id → indented name. */
export function categoryOptions(terms: TermOption[]): Record<string, string> {
  const out: Record<string, string> = {};
  for (const t of terms) out[String(t.id)] = `${"— ".repeat(t.depth)}${t.name}`;
  return out;
}

/** Rules in a new order after moving one up or down; null when it can't move. */
export function moved(rules: OwnRule[], id: string, by: -1 | 1): string[] | null {
  const ordered = [...rules].sort((a, b) => a.position - b.position).map((r) => r.id);
  const i = ordered.indexOf(id);
  const j = i + by;
  if (i < 0 || j < 0 || j >= ordered.length) return null;
  [ordered[i], ordered[j]] = [ordered[j]!, ordered[i]!];
  return ordered;
}

let nonce = "";
let pending: Promise<string> | null = null;

/** The REST nonce: cached, fetched once for calls made together, fetched again when $fresh. */
function restNonce(cfg: TitleRulesConfig, fresh = false): Promise<string> {
  if (nonce && !fresh) return Promise.resolve(nonce);
  if (!pending) pending = fetchNonce(cfg).finally(() => { pending = null; });
  return pending;
}

async function fetchNonce(cfg: TitleRulesConfig): Promise<string> {
  let r: Response;
  try {
    r = await fetch(cfg.nonceUrl, { credentials: "same-origin" });
  } catch {
    throw new ApiError(MESSAGES.NETWORK, "network");
  }
  const text = r.ok ? (await r.text()).trim() : "";
  if (!text || text === "0" || text === "-1") throw new ApiError(MESSAGES.LOGIN_EXPIRED, "auth");
  nonce = text;
  return nonce;
}

/** One REST call. Rejects with ApiError carrying the server's own message when it gives one. */
export async function rest<T>(cfg: TitleRulesConfig, method: "GET" | "POST", path: string, body?: Record<string, unknown>): Promise<T> {
  const send = (n: string) => fetch(cfg.rest + path, {
    method, credentials: "same-origin",
    headers: body ? { "X-WP-Nonce": n, "Content-Type": "application/json" } : { "X-WP-Nonce": n },
    body: body ? JSON.stringify(body) : undefined,
  });
  let r: Response;
  try {
    r = await send(await restNonce(cfg));
    if (r.status === 403) {
      const j = await r.clone().json().catch(() => null) as { code?: string } | null;
      if (j?.code === "rest_cookie_invalid_nonce") r = await send(await restNonce(cfg, true));
    }
  } catch (e) {
    if (e instanceof ApiError) throw e;
    throw new ApiError(MESSAGES.NETWORK, "network");
  }
  const json = await r.json().catch(() => null) as (T & { message?: string }) | null;
  if (!r.ok || json === null) {
    const auth = r.status === 401 || r.status === 403;
    throw new ApiError(json?.message || (auth ? MESSAGES.LOGIN_EXPIRED : MESSAGES.NETWORK), auth ? "auth" : "server");
  }
  return json;
}

/** Forget the cached nonce (tests). */
export function resetNonce(): void {
  nonce = "";
  pending = null;
}
