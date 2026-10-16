import { describe, expect, it } from "vitest";
import {
  EMPTY_QUICK, EMPTY_SELECTION, buildFilter, describeFilter, finishMessage, hasParameters, isSelected, jobActions,
  jobChangedText, lockOptions, orderTicked, progressOf, runFinished, selectAllMatching, selectedCount, selectionPayload,
  sortJobs, targetText, termTree, toggleRows,
} from "../../src/workbench/logic";
import type { Job } from "../../src/workbench/types";

const cfg = {
  suppliers: [{ id: "xchange", name: "XChange" }],
  healthChecks: [{ id: "no_image", label: "No featured image" }],
  lockLabels: { product_cat: "categories", product_brand: "brand", _price: "price" },
  actionLockFields: { set_price: ["_price"], force_in_stock: ["_stock_status"] },
};

const job = (p: Partial<Job>): Job => ({
  id: "j", created_at: "2026-10-16 10:00:00", user: "Mike", action: "set_taxonomy_term", summary: "Set category", filter_label: "",
  total: 10, cursor: 0, changed: 0, unchanged: 0, failed: 0, skipped: 0, skip_locked: false, add_lock: false, status: "running",
  undo_cursor: 0, undone: 0, conflicts: 0, ...p,
});

describe("filters", () => {
  it("the default statuses alone are not a search", () => {
    expect(hasParameters(buildFilter(EMPTY_QUICK, [], "all"))).toBe(false);
    expect(hasParameters(buildFilter({ ...EMPTY_QUICK, statuses: ["publish", "draft"] }, [], "all"))).toBe(false);
  });
  it("anything else is", () => {
    expect(hasParameters(buildFilter({ ...EMPTY_QUICK, q: "bundle" }, [], "all"))).toBe(true);
    expect(hasParameters(buildFilter({ ...EMPTY_QUICK, statuses: ["publish"] }, [], "all"))).toBe(true);
    expect(hasParameters(buildFilter(EMPTY_QUICK, [{ source: "s", field: "f", operator: "equals", value: "x" }], "all"))).toBe(true);
  });
  it("trims the query and falls back to Published when no status is ticked", () => {
    const f = buildFilter({ ...EMPTY_QUICK, q: "  bundle ", statuses: [] }, [], "all");
    expect(f.q).toBe("bundle");
    expect(f.statuses).toEqual(["publish"]);
  });
  it("describes a search the way Recent changes shows it", () => {
    const f = buildFilter({ ...EMPTY_QUICK, q: "bundle", qCase: true, supplier: "xchange", stock: ["outofstock"], health: ["no_image"] }, [], "any");
    const text = describeFilter(f, [{ text: 'Category equals "Bundles"', or: false }, { text: 'Brand equals "Avid"', or: true }], cfg);
    expect(text).toBe('Any of: Title/SKU contains "bundle" (match case) · Category equals "Bundles" · or Brand equals "Avid" · '
      + "Supplier: XChange · Draft, Published · Out of stock · Fails any of: No featured image");
  });
});

describe("selection", () => {
  it("ticks and unticks explicit ids", () => {
    let s = toggleRows(EMPTY_SELECTION, [1, 2, 3], true);
    s = toggleRows(s, [2], false);
    expect(selectedCount(s, 100)).toBe(2);
    expect(selectionPayload(s, buildFilter(EMPTY_QUICK, [], "all"))).toEqual({ mode: "ids", ids: [1, 3] });
  });
  it("'all matching' counts the total minus unticked rows, and sends the filter, not the ids", () => {
    let s = selectAllMatching();
    s = toggleRows(s, [7], false);
    expect(isSelected(s, 7)).toBe(false);
    expect(isSelected(s, 8)).toBe(true);
    expect(selectedCount(s, 5000)).toBe(4999);
    const f = buildFilter({ ...EMPTY_QUICK, q: "x" }, [], "all");
    expect(selectionPayload(s, f)).toEqual({ mode: "filter", filter: f, exclude: [7] });
  });
  it("says what Preview/Apply will touch", () => {
    expect(targetText(EMPTY_SELECTION, 0, false)).toBe("Nothing selected yet.");
    expect(targetText(EMPTY_SELECTION, 12, true)).toMatch(/^Nothing ticked\. Preview checks all 12 results/);
    expect(targetText(toggleRows(EMPTY_SELECTION, [1], true), 12, true)).toBe("1 selected product will be checked.");
    expect(targetText(toggleRows(selectAllMatching(), [1, 2], false), 1200, true))
      .toBe(`All ${(1200).toLocaleString()} products matching the search will be checked, except 2 unticked.`);
  });
});

describe("field locks", () => {
  it("a taxonomy change locks that taxonomy, always", () => {
    const l = lockOptions({ action: "set_taxonomy_term", params: { taxonomy: "product_cat" } }, { skip: false, add: false }, cfg);
    expect(l).toMatchObject({ fields: ["product_cat"], label: "categories", always: true, add: true, skip: false });
  });
  it("other changes lock only when the switch is on, and only lockable fields count", () => {
    const off = lockOptions({ action: "set_price", params: {} }, { skip: true, add: false }, cfg);
    expect(off).toMatchObject({ fields: ["_price"], always: false, add: false, skip: true });
    const unlockable = lockOptions({ action: "force_in_stock", params: {} }, { skip: true, add: true }, cfg);
    expect(unlockable).toMatchObject({ fields: [], skip: false, add: false });
  });
});

describe("jobs", () => {
  it("sorts in memory: numbers numerically, text naturally", () => {
    const jobs = [job({ id: "a", changed: 9 }), job({ id: "b", changed: 10 }), job({ id: "c", changed: 2 })];
    expect(sortJobs(jobs, "changed", "desc").map((j) => j.id)).toEqual(["b", "a", "c"]);
    const named = [job({ id: "a", summary: "item 10" }), job({ id: "b", summary: "Item 9" })];
    expect(sortJobs(named, "summary", "asc").map((j) => j.id)).toEqual(["b", "a"]);
  });
  it("offers Undo only for finished runs that changed something, and Resume for unfinished ones", () => {
    expect(jobActions(job({ status: "done", changed: 3 }))).toEqual({ undo: true, finishUndo: false, resume: false });
    expect(jobActions(job({ status: "done", changed: 0 })).undo).toBe(false);
    expect(jobActions(job({ status: "stopped", changed: 2, cursor: 4, total: 10 }))).toEqual({ undo: true, finishUndo: false, resume: true });
    expect(jobActions(job({ status: "undoing" })).finishUndo).toBe(true);
  });
  it("reports progress and the finishing message", () => {
    expect(progressOf(job({ cursor: 5, total: 10, changed: 4 }), "apply")).toEqual({ pct: 50, text: "5 of 10 · 4 changed" });
    expect(progressOf(job({ changed: 0 }), "undo").pct).toBe(0); // no divide-by-zero
    expect(runFinished(job({ status: "running" }), "apply")).toBe(false);
    expect(runFinished(job({ status: "stopped" }), "apply")).toBe(true);
    expect(runFinished(job({ status: "undoing" }), "undo")).toBe(false);
    expect(finishMessage(job({ status: "done", changed: 3, unchanged: 1, failed: 1, skipped: 2 }), "apply"))
      .toEqual({ text: "Done: 3 changed, 1 already matched, 2 skipped (locked against imports), 1 failed (see the sync log).", ok: false });
    expect(finishMessage(job({ status: "undone", undone: 4, conflicts: 1 }), "undo").text)
      .toBe("Undone: 4 restored; 1 left alone because they were edited again since.");
    expect(jobChangedText(job({ status: "undone", undone: 4, conflicts: 1 }))).toBe("4 restored, 1 kept");
  });
});

describe("term trees", () => {
  const terms = [
    { id: 1, name: "Software", parent: 0 }, { id: 2, name: "Bundles", parent: 1 }, { id: 3, name: "Hardware", parent: 0 },
    { id: 4, name: "Plug-ins", parent: 1 },
  ];
  it("orders parents before children with a path", () => {
    expect(termTree(terms, true).map((t) => `${t.depth}:${t.path}`))
      .toEqual(["0:Software", "1:Software › Bundles", "1:Software › Plug-ins", "0:Hardware"]);
  });
  it("survives a parent loop instead of hanging", () => {
    const loop = [{ id: 1, name: "A", parent: 2 }, { id: 2, name: "B", parent: 1 }, { id: 3, name: "C", parent: 0 }];
    expect(termTree(loop, true).map((t) => t.name)).toEqual(["C"]);
  });
  it("puts ticked terms first", () => {
    expect(orderTicked(termTree(terms, true), new Set([3])).map((t) => t.id)).toEqual([3, 1, 2, 4]);
  });
});
