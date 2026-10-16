import { act, renderHook } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError, api } from "../../src/workbench/api";
import { EMPTY_QUICK, buildFilter } from "../../src/workbench/logic";
import type { Job, SearchResult } from "../../src/workbench/types";
import { BUSY_RETRY_MS, jobStub, useJobRunner } from "../../src/workbench/useJobRunner";
import { SEARCH_DEBOUNCE_MS, useSearch, type SearchInputs } from "../../src/workbench/useSearch";

const result = (total: number, ids: number[] = []): SearchResult => ({
  total, page: 1, pages: 1, per_page: 50, facets: [], checks: {},
  rows: ids.map((id) => ({ id, title: `P${id}`, status: "publish", sku: "", stock: "instock", price: "", categories: [], brands: [],
    supplier: "all", health: [], locked: [], thumb: "", edit_url: "", view_url: "" })),
});

const inputs = (q: string, extra: Partial<SearchInputs> = {}): SearchInputs => ({
  filter: buildFilter({ ...EMPTY_QUICK, q }, [], "all"), firstIncomplete: 0, firstWithoutValue: 0, ...extra,
});

/** A promise the test resolves by hand, to control reply order. */
function deferred<T>() {
  let resolve!: (v: T) => void, reject!: (e: unknown) => void;
  const promise = new Promise<T>((res, rej) => { resolve = res; reject = rej; });
  return { promise, resolve, reject };
}

beforeEach(() => vi.useFakeTimers());
afterEach(() => { vi.useRealTimers(); vi.restoreAllMocks(); });

describe("useSearch", () => {
  const callbacks = () => ({ onNewSearch: vi.fn(), onError: vi.fn() });

  it("waits for the debounce, then searches once", async () => {
    const spy = vi.spyOn(api, "search").mockResolvedValue(result(3, [1, 2, 3]));
    const cb = callbacks();
    const { result: h, rerender } = renderHook(({ i }) => useSearch(i, 50, cb), { initialProps: { i: inputs("b") } });
    rerender({ i: inputs("bu") });
    act(() => h.current.schedule());
    rerender({ i: inputs("bun") });
    act(() => h.current.schedule());
    expect(h.current.view.pending).toBe(true);
    await act(async () => { vi.advanceTimersByTime(SEARCH_DEBOUNCE_MS - 1); });
    expect(spy).not.toHaveBeenCalled();
    await act(async () => { vi.advanceTimersByTime(1); });
    expect(spy).toHaveBeenCalledTimes(1);
    expect(spy.mock.calls[0]![0].q).toBe("bun");
    expect(h.current.view.result?.total).toBe(3);
    expect(h.current.view.pending).toBe(false);
    expect(cb.onNewSearch).toHaveBeenCalledTimes(1);
  });

  it("ignores a slow reply for an older search and aborts it", async () => {
    const first = deferred<SearchResult>();
    const signals: AbortSignal[] = [];
    const spy = vi.spyOn(api, "search").mockImplementation((_f, _o, signal) => {
      signals.push(signal!);
      return spy.mock.calls.length === 1 ? first.promise : Promise.resolve(result(1, [9]));
    });
    const { result: h, rerender } = renderHook(({ i }) => useSearch(i, 50, callbacks()), { initialProps: { i: inputs("old") } });
    act(() => h.current.schedule(0));
    await act(async () => { vi.advanceTimersByTime(0); });
    rerender({ i: inputs("new") });
    act(() => h.current.schedule(0));
    await act(async () => { vi.advanceTimersByTime(0); });
    expect(signals[0]!.aborted).toBe(true);
    await act(async () => { first.resolve(result(500, [1, 2])); });
    expect(h.current.view.result?.total).toBe(1); // the newer search's answer stands
  });

  it("does not search while a condition is unfinished, and says which", async () => {
    const spy = vi.spyOn(api, "search").mockResolvedValue(result(0));
    const { result: h } = renderHook(() => useSearch(inputs("x", { firstIncomplete: 2 }), 50, callbacks()));
    act(() => h.current.schedule(0));
    await act(async () => { vi.advanceTimersByTime(0); });
    expect(spy).not.toHaveBeenCalled();
    expect(h.current.view.hint).toBe("Condition 2: pick a Source and a Field, or remove it.");
    expect(h.current.view.pending).toBe(true); // Apply stays off: results don't match the inputs
  });

  it("skips a repeat of the same search", async () => {
    const spy = vi.spyOn(api, "search").mockResolvedValue(result(2, [1, 2]));
    const { result: h, rerender } = renderHook(({ i }) => useSearch(i, 50, callbacks()), { initialProps: { i: inputs("same") } });
    act(() => h.current.schedule(0));
    await act(async () => { vi.advanceTimersByTime(0); });
    rerender({ i: inputs("same") });
    act(() => h.current.schedule(0));
    await act(async () => { vi.advanceTimersByTime(0); });
    expect(spy).toHaveBeenCalledTimes(1);
    expect(h.current.view.pending).toBe(false);
  });

  it("clears results when nothing is left to search for", async () => {
    vi.spyOn(api, "search").mockResolvedValue(result(2, [1, 2]));
    const cb = callbacks();
    const { result: h, rerender } = renderHook(({ i }) => useSearch(i, 50, cb), { initialProps: { i: inputs("x") } });
    act(() => h.current.schedule(0));
    await act(async () => { vi.advanceTimersByTime(0); });
    rerender({ i: inputs("") });
    act(() => h.current.schedule(0));
    await act(async () => { vi.advanceTimersByTime(0); });
    expect(h.current.view.result).toBeNull();
    expect(h.current.view.filter).toBeNull();
  });

  it("reports a failed search and lets the same search be retried", async () => {
    const spy = vi.spyOn(api, "search").mockRejectedValueOnce(new ApiError("Network error — please try again.", "network"))
      .mockResolvedValueOnce(result(1, [5]));
    const cb = callbacks();
    const { result: h } = renderHook(() => useSearch(inputs("x"), 50, cb));
    act(() => h.current.schedule(0));
    await act(async () => { vi.advanceTimersByTime(0); });
    expect(cb.onError).toHaveBeenCalledWith("Network error — please try again.", "network");
    act(() => h.current.schedule(0));
    await act(async () => { vi.advanceTimersByTime(0); });
    expect(spy).toHaveBeenCalledTimes(2);
    expect(h.current.view.result?.total).toBe(1);
  });
});

describe("useJobRunner", () => {
  const j = (p: Partial<Job>): Job => ({ ...jobStub("job1"), total: 4, ...p });

  it("asks batch after batch until done, backing off while the server is busy", async () => {
    const run = vi.spyOn(api, "run")
      .mockResolvedValueOnce({ job: j({ cursor: 2, changed: 2 }) })
      .mockResolvedValueOnce({ job: j({ cursor: 2, changed: 2 }), busy: true })
      .mockResolvedValueOnce({ job: j({ cursor: 4, changed: 3, unchanged: 1, status: "done" }) });
    const done = vi.fn();
    const { result: h } = renderHook(() => useJobRunner(done));
    let finished!: Promise<void>;
    act(() => { finished = h.current.run(j({}), "apply"); });
    expect(h.current.running).toBe("job1");
    await act(async () => { await vi.advanceTimersByTimeAsync(0); });
    expect(run).toHaveBeenCalledTimes(2);
    expect(h.current.progress?.text).toBe("2 of 4 · 2 changed");
    await act(async () => { await vi.advanceTimersByTimeAsync(BUSY_RETRY_MS - 1); });
    expect(run).toHaveBeenCalledTimes(2); // still waiting out the busy back-off
    await act(async () => { await vi.advanceTimersByTimeAsync(1); await finished; });
    expect(run).toHaveBeenCalledTimes(3);
    expect(done).toHaveBeenCalledWith({ text: "Done: 3 changed, 1 already matched.", ok: true });
    expect(h.current.running).toBeNull();
  });

  it("Stop asks the server to stop and says where it stopped", async () => {
    const gate = deferred<{ job: Job }>();
    vi.spyOn(api, "run").mockReturnValueOnce(gate.promise);
    const stop = vi.spyOn(api, "stop").mockResolvedValue({ job: j({ cursor: 2, status: "stopped" }) });
    const done = vi.fn();
    const { result: h } = renderHook(() => useJobRunner(done));
    let finished!: Promise<void>;
    act(() => { finished = h.current.run(j({}), "apply"); });
    act(() => h.current.stop());
    await act(async () => { gate.resolve({ job: j({ cursor: 2 }) }); await vi.advanceTimersByTimeAsync(0); await finished; });
    expect(stop).toHaveBeenCalledWith("job1");
    expect(done).toHaveBeenCalledWith({ text: "Stopped after 2 of 4. Resume it from Recent changes.", ok: true });
  });

  it("a dropped connection keeps the job resumable and says so", async () => {
    vi.spyOn(api, "run").mockRejectedValueOnce(new ApiError("Network error — please try again.", "network"));
    const done = vi.fn();
    const { result: h } = renderHook(() => useJobRunner(done));
    await act(async () => { await h.current.run(j({}), "apply"); });
    expect(done).toHaveBeenCalledWith({ text: "Network error — please try again. The run is saved — resume it from Recent changes.", ok: false });
  });

  it("sends resume only with the first batch of a resumed run", async () => {
    const run = vi.spyOn(api, "run")
      .mockResolvedValueOnce({ job: j({ cursor: 3 }) })
      .mockResolvedValueOnce({ job: j({ cursor: 4, status: "done" }) });
    const { result: h } = renderHook(() => useJobRunner(vi.fn()));
    let finished!: Promise<void>;
    act(() => { finished = h.current.run(j({}), "apply", true); });
    await act(async () => { await vi.advanceTimersByTimeAsync(0); await finished; });
    expect(run.mock.calls).toEqual([["job1", true], ["job1", false]]);
  });
});
