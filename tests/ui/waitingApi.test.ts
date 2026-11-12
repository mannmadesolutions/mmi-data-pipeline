import { describe, expect, it } from "vitest";
import { EMPTY_WAIT, nextSort, step, waitingQuery } from "../../src/workbench/waitingApi";

describe("waitingApi", () => {
  it("asks for the server's order unless a heading was clicked", () => {
    expect(waitingQuery(EMPTY_WAIT)).toBe("page=1");
    expect(waitingQuery({ ...EMPTY_WAIT, kind: "data", q: " freq ", page: 2, orderby: "brand", order: "asc" })).toBe("page=2&kind=data&q=freq&orderby=brand&order=asc");
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
});
