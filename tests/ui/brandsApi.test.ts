import { describe, expect, it } from "vitest";
import { batchesOf, batchKey, bestMatches, brandsIn, brandViewCount, drawerRow, hostOf, makerHosts, shortUrl, sortBrands, sureIds, ticks, toDo, toDoText, type Brand, type BrandSite } from "../../src/workbench/brandsApi";
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

describe("brands & sites: the split view", () => {
  it("sorts the list by a heading, ties A–Z whichever way it runs", () => {
    expect(sortBrands(list, { col: "todo", desc: true }).map((b) => b.id)).toEqual([2, 1, 4, 3]);
    expect(sortBrands(list, { col: "todo", desc: false }).map((b) => b.id)).toEqual([4, 3, 1, 2]);
    expect(sortBrands(list, { col: "brand", desc: false })[0]!.name).toBe("Acme");
    expect(sortBrands(list, null)).toBe(list);
  });
  it("says what a brand's products wait for", () => {
    expect(toDoText(list[0]!)).toBe("2 proposed pages to decide, 1 product with no page");
    expect(toDoText(brand({ failed_open: 1 }))).toBe("1 page gone or moved");
    expect(toDoText(list[3]!)).toBe("");
  });
});

describe("Makers → Pages as batches: rows that share why are one decision", () => {
  const thin = "Our verified page lists several products and its card there has little or no description; this page is its own";
  const notOnCard = "Our verified page lists several products and no card names this one; this page names it";
  const p = (id: number, reason: string, label: string, score: number, proposed = `https://www.ilio.com/p-${id}`) =>
    row({ id, status: "candidate", proposed, reason, reason_label: label, score, current: "https://www.moforte.com/geoshred-studio-store/", verified: true });
  const rows = [
    p(1, "recheck_card", thin, 90), p(2, "recheck", notOnCard, 50), p(3, "recheck_card", thin, 50), p(4, "recheck", notOnCard, 50), p(5, "recheck", notOnCard, 50),
    row({ id: 6 }), // no page
    row({ id: 7, current: "https://maker.example/old", op_group: "broken" }),
    row({ id: 8, fetch_status: "no_page" }),
    row({ id: 9, current: "https://maker.example/", op_wrong: "home" }),
    row({ id: 10, current: "https://maker.example/ten" }),
    row({ id: 11, current: "https://www.shop.example/eleven" }), // on a reseller's site
  ];
  it("groups proposals by reason, biggest first, then the pages that need a hand, then the rest", () => {
    const b = batchesOf(rows, {}, ["shop.example"]);
    expect(b.map((x) => [x.key, x.rows.map((r) => r.id)])).toEqual([
      ["why:recheck", [2, 4, 5]], ["why:recheck_card", [1, 3]], ["broken", [7]], ["away", [11]], ["wrong", [9]], ["none", [6]], ["nopub", [8]], ["has", [10]],
    ]);
    expect(batchesOf(rows).find((x) => x.key === "has")!.rows.map((r) => r.id)).toEqual([10, 11]); // roles unknown: not called away
    expect(b[0]!.label).toBe(notOnCard);
    expect(b[1]).toMatchObject({ kind: "why", hosts: ["ilio.com"], from: ["moforte.com"], sure: 1 });
  });
  it("ticks only the sure proposals (70 or more)", () => {
    const [notCard, card] = batchesOf(rows);
    expect(sureIds(notCard!)).toEqual([]);
    expect(sureIds(card!)).toEqual([1]);
    expect(sureIds(batchesOf(rows).find((x) => x.key === "none")!)).toEqual([]);
  });
  it("keeps a decided row in the batch it was loaded in until the list is read again", () => {
    const placed = Object.fromEntries(rows.map((r) => [r.id, batchKey(r)]));
    const used = rows.map((r) => (r.id === 1 ? { ...r, status: "approved", current: r.proposed } : r));
    expect(batchesOf(used, placed).find((x) => x.key === "why:recheck_card")!.rows.map((r) => r.id)).toEqual([1, 3]);
    expect(batchesOf(used).find((x) => x.key === "why:recheck_card")!.rows.map((r) => r.id)).toEqual([3]);
  });
  it("shortens an address for a one-line row", () => {
    expect(shortUrl("https://www.ilio.com/geoshred-bansuri/")).toBe("ilio.com/geoshred-bansuri");
  });
});
