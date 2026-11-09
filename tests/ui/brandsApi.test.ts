import { describe, expect, it } from "vitest";
import { bestMatches, brandsIn, brandViewCount, drawerRow, hostOf, makerHosts, ticks, toDo, type Brand, type BrandSite } from "../../src/workbench/brandsApi";
import type { PageRow } from "../../src/workbench/pagesApi";

const site = (host: string, role: BrandSite["role"]): BrandSite => ({ host, n: 1, read: 1, unread: 0, failed: 0, unverified: 0, better: 0, bundles: 0, open: 0, role, why: "", saved: true, map: "", shared: 0 });
const brand = (o: Partial<Brand>): Brand => ({ id: 1, name: "Acme", website: "", url: "", logo: "", products: 5, open: 0, failed_open: 0, no_url: 0, no_page: 0, sites: [], gone: false, bundles: 0, bundles_none: 0, ...o });
const list = [
  brand({ id: 1, name: "Zed Audio", open: 2, no_url: 1, sites: [site("zed.example", "official")] }),
  brand({ id: 2, name: "Acme", no_url: 5, sites: [site("shop.example", "reseller")] }),
  brand({ id: 3, name: "Gone Co", gone: true }),
  brand({ id: 4, name: "Fine Ltd", sites: [site("fine.example", "partner")] }),
];
const row = (o: Partial<PageRow>): PageRow => ({
  id: 7, title: "", edit_url: "", view_url: "", image: "", sku: "", brand: null, current: "", verified: false, proposed: "", status: "none", reason: "", reason_label: "",
  score: 0, note: "", page_title: "", page_status: "", result: "not_read", result_label: "", result_badge: "", fetch_status: "", read_at: "", read_note: "", read_final: "",
  op_group: "", op_conf: null, op_wrong: "", op_wrong_note: "", ...o,
});

describe("brands & sites: the brand list", () => {
  it("needs work: most to do first; set-aside brands never", () => {
    expect(brandsIn(list, "work", "").map((b) => b.name)).toEqual(["Acme", "Zed Audio"]);
    expect(toDo(list[0]!)).toBe(3);
  });
  it("no maker site: a reseller isn't the maker's; set aside on its own", () => {
    expect(brandsIn(list, "nomaker", "").map((b) => b.id)).toEqual([2]);
    expect(brandsIn(list, "gone", "").map((b) => b.id)).toEqual([3]);
    expect(brandViewCount(list, "all")).toBe(4);
  });
  it("finds a brand by name or by one of its sites", () => {
    expect(brandsIn(list, "all", "zed").map((b) => b.id)).toEqual([1]);
    expect(brandsIn(list, "all", "fine.ex").map((b) => b.id)).toEqual([4]);
  });
  it("reads products from the maker's own sites and partners only", () => {
    expect(makerHosts(list[3]!)).toEqual(["fine.example"]);
    expect(makerHosts(list[1]!)).toEqual([]);
  });
  it("takes a typed site as its bare host", () => {
    expect(hostOf("https://www.Maker.example/products")).toBe("maker.example");
    expect(hostOf("maker.example")).toBe("maker.example");
    expect(hostOf("not a site")).toBe("");
  });
});

describe("brands & sites: matching products to the maker's pages", () => {
  it("keeps each product's best match across the maker's sites", () => {
    const best = bestMatches([
      { hosts: [], host: "a.example", matches: { "7": { url: "https://a.example/x", name: "X", score: 60, why: "" }, "8": null } },
      { hosts: [], host: "b.example", matches: { "7": { url: "https://b.example/x", name: "X", score: 90, why: "" } } },
    ]);
    expect(best.get(7)?.url).toBe("https://b.example/x");
    expect(best.has(8)).toBe(false);
  });
  it("ticks a sure match, never its own page again, never over a verified page that reads fine", () => {
    const m = { url: "https://a.example/x", name: "X", score: 80, why: "" };
    expect(ticks(row({}), m)).toBe(true);
    expect(ticks(row({}), { ...m, score: 65 })).toBe(false);
    expect(ticks(row({ current: "https://a.example/x/" }), m)).toBe(false);
    expect(ticks(row({ current: "https://shop.example/x", verified: true, result: "read" }), m)).toBe(false);
    expect(ticks(row({ current: "https://shop.example/x", verified: true, result: "gone" }), m)).toBe(true);
  });
  it("gives a drawer row the list group its state means", () => {
    expect(drawerRow(row({ status: "candidate", proposed: "https://a.example/x" })).op_group).toBe("open");
    expect(drawerRow(row({ current: "https://a.example/x", result: "gone" })).op_group).toBe("broken");
    expect(drawerRow(row({})).op_group).toBe("none");
    expect(drawerRow(row({ fetch_status: "no_page" })).op_group).toBe("");
    expect(drawerRow(row({ current: "https://a.example/x", result: "read" })).op_group).toBe("");
  });
});
