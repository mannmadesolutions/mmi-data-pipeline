import { describe, expect, it } from "vitest";
import { alertKeys, EMPTY_WAIT, nextSort, step, waitingQuery, type WaitKey } from "../../src/workbench/waitingApi";

describe("waitingApi", () => {
  it("asks for the server's order unless a heading was clicked", () => {
    expect(waitingQuery(EMPTY_WAIT)).toBe("page=1");
    expect(waitingQuery({ ...EMPTY_WAIT, kind: "key:no_picture", q: " freq ", page: 2, orderby: "brand", order: "asc" })).toBe("page=2&kind=key%3Ano_picture&q=freq&orderby=brand&order=asc");
  });
  it("a heading sorts, flips, then goes back to errors first", () => {
    const a = nextSort(EMPTY_WAIT, "title");
    expect(a).toEqual({ orderby: "title", order: "asc" });
    const b = nextSort({ ...EMPTY_WAIT, ...a }, "title");
    expect(b).toEqual({ orderby: "title", order: "desc" });
    expect(nextSort({ ...EMPTY_WAIT, ...b }, "title")).toEqual({ orderby: "", order: "desc" });
    expect(nextSort(EMPTY_WAIT, "waiting")).toEqual({ orderby: "waiting", order: "desc" });
  });
  it("steps through the rows on screen, stopping at the ends", () => {
    const rows = [{ id: 5 }, { id: 9 }, { id: 2 }];
    expect(step(rows, 9, 1)).toBe(2);
    expect(step(rows, 9, -1)).toBe(5);
    expect(step(rows, 2, 1)).toBeNull();
    expect(step(rows, 5, -1)).toBeNull();
    expect(step(rows, 77, 1)).toBe(5);   // opened from a link: › starts the list
    expect(step(rows, 77, -1)).toBeNull();
  });
  it("gives every alert its own pill, never the queues', and keeps the one a person is on", () => {
    const keys: WaitKey[] = [
      { key: "no_category", label: "No category", tone: "error", n: 2, issue: "category" },
      { key: "description", label: "Description", tone: "info", n: 9, issue: "description" },
      { key: "no_picture", label: "No picture", tone: "warning", n: 5, issue: "no_picture" },
      { key: "page", label: "Maker page", tone: "info", n: 4, issue: "page" },
    ];
    expect(alertKeys(keys, "").map((k) => k.key)).toEqual(["no_category", "no_picture"]);
    const kept = alertKeys(keys, "key:flag:bundle_not_in_category");
    expect(kept.at(-1)).toMatchObject({ key: "flag:bundle_not_in_category", n: 0, label: "Bundle not in category", issue: "bundle_not_in_category" });
  });
});
