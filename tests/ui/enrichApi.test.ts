import { describe, expect, it } from "vitest";
import { effectivePick, EMPTY_ENRICH, sourceShort, enrichActions, enrichCount, enrichQuery, enrichSources, makeFeatured, nudgePick, placePick, tileOrder, togglePick, writeResult, type EnrichSummary } from "../../src/workbench/enrichApi";

const s: EnrichSummary = {
  counts: { "content:vendor_page": { proposed: 10, applied: 5 }, "content:web_assets": { proposed: 2 }, "image:vendor_page": { proposed: 3, stale: 1 } },
  rules: { "content:vendor_page": { label: "Description from the maker's page", approved: true } },
};

describe("description and picture proposals", () => {
  it("counts per kind, over its sources, or one source", () => {
    expect(enrichCount(s, "content", "proposed")).toBe(12);
    expect(enrichCount(s, "content", "proposed", "web_assets")).toBe(2);
    expect(enrichCount(s, "content", "all")).toBe(17);
    expect(enrichCount(s, "image", "stale")).toBe(1);
  });
  it("names a kind's sources by their rule where there is one", () => {
    expect(enrichSources(s, "content")).toEqual([{ value: "vendor_page", label: "Description from the maker's page" }, { value: "web_assets", label: "web assets" }]);
  });
  it("asks for has_images only for pictures", () => {
    expect(enrichQuery({ ...EMPTY_ENRICH, has_images: "no" })).toBe("kind=content&status=proposed&page=1&per_page=25");
    expect(enrichQuery({ ...EMPTY_ENRICH, kind: "image", has_images: "no", source: "vendor_page" })).toBe("kind=image&status=proposed&page=1&per_page=25&source=vendor_page&has_images=no");
  });
  it("offers what each status allows", () => {
    expect(enrichActions("proposed")).toEqual(["accept", "reject"]);
    expect(enrichActions("accepted")).toEqual(["apply", "reset"]);
    expect(enrichActions("applied")).toEqual(["revert"]);
  });
  it("picks pictures in order; the star moves one first", () => {
    expect(togglePick(["a", "b"], "c")).toEqual(["a", "b", "c"]);
    expect(togglePick(["a", "b"], "a")).toEqual(["b"]);
    expect(makeFeatured(["a", "b", "c"], "c")).toEqual(["c", "a", "b"]);
    expect(makeFeatured(["a"], "z")).toEqual(["z", "a"]);
  });
  it("orders the pick by dragging: before or after a used picture, or onto a left-out one to leave it out", () => {
    expect(placePick(["a", "b", "c"], "c", "a", "before")).toEqual(["c", "a", "b"]); // the featured place
    expect(placePick(["a", "b", "c"], "a", "b", "after")).toEqual(["b", "a", "c"]);
    expect(placePick(["a", "b"], "z", "b", "before")).toEqual(["a", "z", "b"]); // a left-out one is used there
    expect(placePick(["a", "b"], "b", "z", "before")).toEqual(["a"]); // onto a left-out one: left out
    expect(placePick(["a", "b"], "y", "z", "after")).toEqual(["a", "b"]); // both left out: nothing changes
    expect(placePick(["a", "b"], "a", "a", "after")).toEqual(["a", "b"]);
  });
  it("moves a used picture one place with the keyboard, never past either end", () => {
    expect(nudgePick(["a", "b", "c"], "b", -1)).toEqual(["b", "a", "c"]);
    expect(nudgePick(["a", "b", "c"], "c", 1)).toEqual(["a", "b", "c"]);
    expect(nudgePick(["a", "b"], "z", 1)).toEqual(["a", "b"]);
  });
  it("shows the pick first, featured leading, then the pictures left out as listed", () => {
    const t = ["a", "b", "c", "d"].map((token) => ({ token }));
    expect(tileOrder(t, ["c", "a"]).map((x) => x.token)).toEqual(["c", "a", "b", "d"]);
    expect(tileOrder(t, ["gone", "b"]).map((x) => x.token)).toEqual(["b", "a", "c", "d"]);
  });
  it("says what write-now did", () => {
    expect(writeResult({ result: "applied", message: "" }, "content")).toEqual({ text: "Description written to the product.", ok: true });
    expect(writeResult({ result: "waiting", message: "AVIF later" }, "image")).toEqual({ text: "AVIF later", ok: true });
    expect(writeResult({ result: "stale", message: "The product has an image now." }, "image")).toEqual({ text: "The product has an image now.", ok: false });
  });
  it("starts an unsaved pick with the product's own pictures, so adding one never drops them", () => {
    const base = { chosen: [] as string[], own: [{ token: "att:1", url: "" }], images: ["a", "b", "c", "d", "e", "f"] } as Parameters<typeof effectivePick>[0];
    expect(effectivePick(base)).toEqual(["att:1"]);
    expect(togglePick(effectivePick(base), "a")).toEqual(["att:1", "a"]);
    expect(effectivePick({ ...base, own: [] })).toEqual(["a", "b", "c", "d", "e"]);
    expect(effectivePick({ ...base, chosen: ["c"] })).toEqual(["c"]);
    expect(sourceShort("vendor_page")).toBe("Maker's page");
    expect(sourceShort("odd_source")).toBe("odd source");
  });
});
