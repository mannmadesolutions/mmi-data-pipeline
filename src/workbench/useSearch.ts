/**
 * Search-as-you-type over the catalog, paged and sorted on the server.
 *
 * - Edits are debounced (≥ 400 ms, AGENTS.md → Server Load) and Enter searches at once.
 * - Only the latest request counts: a newer search aborts the one in flight,
 *   and a late reply for an older one is ignored.
 * - A search identical to the last one is skipped.
 * - While a condition is unfinished the results stay as they were and are
 *   marked pending, so Apply never acts on results the screen no longer describes.
 */
import { useCallback, useEffect, useRef, useState } from "react";
import { ApiError, api } from "./api";
import { MESSAGES, fill } from "./messages";
import { filterKey, hasParameters } from "./logic";
import type { Filter, SearchResult, SortDir } from "./types";

export const SEARCH_DEBOUNCE_MS = 400;

export interface SearchInputs {
  filter: Filter;
  firstIncomplete: number;
  firstWithoutValue: number;
}

export interface SearchView {
  /** The filter the results belong to (what "all matching" means), or null before any search. */
  filter: Filter | null;
  result: SearchResult | null;
  page: number;
  perPage: number;
  sort: string;
  dir: SortDir;
  /** The results don't reflect the inputs yet (a search is due or running, or a condition is unfinished). */
  pending: boolean;
  loading: boolean;
  /** The line under the conditions: a waiting hint, or empty. */
  hint: string;
}

export interface SearchCallbacks {
  /** A new search replaced the last one: drop the selection and any preview. */
  onNewSearch: () => void;
  onError: (message: string, kind: ApiError["kind"]) => void;
}

const INITIAL = (perPage: number): SearchView => ({
  filter: null, result: null, page: 1, perPage, sort: "title", dir: "asc", pending: false, loading: false, hint: "",
});

export function useSearch(inputs: SearchInputs, defaultPerPage: number, callbacks: SearchCallbacks) {
  const [view, setView] = useState<SearchView>(() => INITIAL(defaultPerPage));
  const viewRef = useRef(view);
  viewRef.current = view;
  const inputsRef = useRef(inputs);
  inputsRef.current = inputs;
  const cbRef = useRef(callbacks);
  cbRef.current = callbacks;

  const keyRef = useRef("");                    // key of the last searched filter
  const timer = useRef<number | null>(null);
  const inFlight = useRef<AbortController | null>(null);
  const seq = useRef(0);                        // id of the latest request

  const patch = useCallback((p: Partial<SearchView>) => setView((v) => ({ ...v, ...p })), []);

  /** Fetch the current page of the current filter. */
  const fetchPage = useCallback((o: { filter: Filter; page: number; perPage: number; sort: string; dir: SortDir; fresh: boolean }) => {
    inFlight.current?.abort();
    const ctrl = new AbortController();
    inFlight.current = ctrl;
    const id = ++seq.current;
    patch({ pending: true, loading: true, hint: "" });
    api.search(o.filter, o, ctrl.signal)
      .then((result) => {
        if (id !== seq.current) return;
        patch({ result, page: result.page });
      })
      .catch((e: ApiError) => {
        if (e.kind === "abort" || id !== seq.current) return;
        keyRef.current = ""; // let the same search be retried
        cbRef.current.onError(e.message, e.kind);
      })
      .finally(() => {
        if (id !== seq.current) return; // a newer search took over
        inFlight.current = null;
        patch({ loading: false, pending: timer.current !== null });
      });
  }, [patch]);

  /** Back to "nothing searched": no results, nothing to apply to. */
  const reset = useCallback(() => {
    inFlight.current?.abort();
    seq.current++;
    keyRef.current = "";
    cbRef.current.onNewSearch();
    patch({ filter: null, result: null, pending: false, loading: false, hint: "" });
  }, [patch]);

  /** Search with the inputs as they are now (the debounced step). */
  const runFresh = useCallback(() => {
    const { filter, firstIncomplete, firstWithoutValue } = inputsRef.current;
    if (!firstIncomplete && !hasParameters(filter)) { reset(); return; }
    if (firstIncomplete) { patch({ pending: true, hint: fill(MESSAGES.INCOMPLETE, { n: firstIncomplete }) }); return; }
    if (firstWithoutValue) { patch({ pending: true, hint: fill(MESSAGES.WAITING_VALUE, { n: firstWithoutValue }) }); return; }
    const key = filterKey(filter);
    if (key === keyRef.current) { patch({ pending: viewRef.current.loading, hint: "" }); return; }
    keyRef.current = key;
    cbRef.current.onNewSearch();
    const v = viewRef.current;
    patch({ filter, page: 1 });
    fetchPage({ filter, page: 1, perPage: v.perPage, sort: v.sort, dir: v.dir, fresh: true });
  }, [fetchPage, patch, reset]);

  /** Search a moment after the last edit (0 = now). */
  const schedule = useCallback((delay: number = SEARCH_DEBOUNCE_MS) => {
    const { filter, firstIncomplete } = inputsRef.current;
    // A blur that leaves the search as it was (pressing Preview or Apply after
    // typing) must not disable those buttons mid-press: their click would be lost.
    if (!viewRef.current.pending && keyRef.current && !firstIncomplete && filterKey(filter) === keyRef.current) return;
    if (timer.current !== null) window.clearTimeout(timer.current);
    patch({ pending: true });
    timer.current = window.setTimeout(() => { timer.current = null; runFresh(); }, delay);
  }, [patch, runFresh]);

  /** Re-fetch the same search: a page/sort/size change, or fresh values after a change ran. */
  const refetch = useCallback((o: Partial<Pick<SearchView, "page" | "perPage" | "sort" | "dir">> = {}) => {
    const v = { ...viewRef.current, ...o };
    if (!v.filter) return;
    patch(o);
    fetchPage({ filter: v.filter, page: v.page, perPage: v.perPage, sort: v.sort, dir: v.dir, fresh: false });
  }, [fetchPage, patch]);

  /** Sort by a column: same column again flips the direction. */
  const sortBy = useCallback((col: string) => {
    const v = viewRef.current;
    refetch({ sort: col, dir: v.sort === col && v.dir === "asc" ? "desc" : "asc", page: 1 });
  }, [refetch]);

  /** Replace one row in place after a row edit (it stays visible even if it no longer matches). */
  const replaceRow = useCallback((row: SearchResult["rows"][number]) => {
    setView((v) => (v.result ? { ...v, result: { ...v.result, rows: v.result.rows.map((r) => (r.id === row.id ? row : r)) } } : v));
  }, []);

  useEffect(() => () => {
    if (timer.current !== null) window.clearTimeout(timer.current);
    inFlight.current?.abort();
  }, []);

  return { view, schedule, refetch, sortBy, replaceRow };
}
