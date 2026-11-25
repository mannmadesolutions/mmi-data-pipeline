import { describe, expect, it } from "vitest";
import {
  chipCounts, chipOf, fieldOptions, fold, groupAvailability, memberAvailability, nextSort, olderLinkCount, preselectCanonical, priceCell, priceState,
  referencePrice, verdict, visibleRows, winnerDefaults, type Group, type Member,
} from "../../src/workbench/dupesLogic";

const member = (id: number, o: Partial<Member> = {}): Member => ({ supplier: "xchange", sku: `S${id}`, dealer_price: 0, wc_product_id: id, stock_status: "instock", in_feed: true, ...o });

const group = (key: string, source: Group["source"], members: Member[], o: Partial<Group> = {}): Group => ({
  group_key: key, source, brand: "Moog", name: `Group ${key}`, members, price_min: 0, price_max: 0, price_spread: 0, price_count: 0, price_stale: 0,
  price_match: false, already_linked: false, ...o,
});

describe("dupesLogic", () => {
  it("says whether a listing can be bought and whether its supplier still sells it", () => {
    expect(memberAvailability(member(1))).toBe("instock");
    expect(memberAvailability(member(1, { stock_status: "onbackorder" }))).toBe("backorder");
    expect(memberAvailability(member(1, { stock_status: "outofstock", in_feed: false }))).toBe("gone");
    expect(memberAvailability(member(1, { stock_status: "outofstock", held_by: "Out of Stock Rule" }))).toBe("ruled");
    expect(memberAvailability(member(1, { stock_status: "outofstock", in_feed: null }))).toBe("out");
    expect(memberAvailability(member(1, { stock_status: "outofstock" }))).toBe("held");
    expect(memberAvailability(member(0))).toBe("unimported");
  });

  it("calls a group not sold only when every listing is gone or held out by a rule", () => {
    const gone = member(1, { stock_status: "outofstock", in_feed: false });
    const ruled = member(2, { stock_status: "outofstock", held_by: "rule" });
    expect(groupAvailability({ members: [gone, ruled] })).toBe("notsold");
    expect(groupAvailability({ members: [gone, member(3, { stock_status: "outofstock" })] })).toBe("sellable");
    expect(groupAvailability({ members: [gone, member(4)] })).toBe("onsale");
    expect(groupAvailability({ members: [gone, member(5, { stock_status: "outofstock", in_feed: null })] })).toBe("out");
  });

  it("folds a row whose listings all sit in a bigger one, keeping what it replaced", () => {
    const rows = fold([
      group("mpn-1", "mpn", [member(7403), member(18553)], { match: "999-1" }),
      group("cand-1", "candidate", [member(7403), member(18553)]),
      group("title-1", "title", [member(7403), member(18553), member(20000)]),
      group("older-1", "older", [member(7403), member(30000)]),
    ]);
    expect(rows.map((r) => r.group_key)).toEqual(["title-1", "older-1"]);
    const holder = rows[0]!;
    expect(holder.alsoSources.sort()).toEqual(["candidate", "mpn"]);
    expect(holder.coveredKeys.sort()).toEqual(["cand-1", "mpn-1"]);
    expect(holder.covers).toContain("the same part number match (999-1)");
  });

  it("prefers the stronger source on a tie, and a linked group holds only linked rows", () => {
    const tie = fold([group("cand-2", "candidate", [member(1), member(2)]), group("mpn-2", "mpn", [member(1), member(2)])]);
    expect(tie.map((r) => r.group_key)).toEqual(["mpn-2"]);
    const toDecide = fold([group("legacy-1", "legacy", [member(1), member(2), member(3)], { already_linked: true }), group("cand-3", "candidate", [member(1), member(2)])]);
    expect(toDecide).toHaveLength(2);
    const linked = fold([group("legacy-1", "legacy", [member(1), member(2), member(3)], { already_linked: true }), group("cand-3", "candidate", [member(1), member(2)], { already_linked: true })]);
    expect(linked.map((r) => r.group_key)).toEqual(["legacy-1"]);
  });

  it("never folds a group with a listing not imported yet", () => {
    const rows = fold([group("cand-4", "candidate", [member(1), member(0)]), group("title-4", "title", [member(1), member(2), member(3)])]);
    expect(rows).toHaveLength(2);
  });

  it("counts and filters by chip, brand and search", () => {
    const rows = fold([
      group("a", "candidate", [member(1), member(2)], { brand: "Moog", name: "Mother-32" }),
      group("b", "legacy", [member(3), member(4)], { brand: "Acme", name: "Pro Tools", already_linked: true, nothing_on_sale: true }),
      group("c", "older", [member(5), member(6, { stock_status: "outofstock", in_feed: false })], { brand: "Acme", name: "Sibelius" }),
    ]);
    expect(chipCounts(rows)).toEqual({ new: 2, linked: 1, no_sale: 1, not_sold: 0, older: 1, all: 3 });
    expect(visibleRows(rows, "new", "", "", null).map((r) => r.group_key)).toEqual(["a", "c"]);
    expect(visibleRows(rows, "all", "acme", "", null).map((r) => r.group_key)).toEqual(["b", "c"]);
    expect(visibleRows(rows, "all", "", "s3", null).map((r) => r.group_key)).toEqual(["b"]);
    expect(visibleRows(rows, "all", "", "5", null).map((r) => r.group_key)).toEqual(["c"]);
    expect(olderLinkCount(rows)).toBe(1);
    expect(chipOf("no_sale")).toBe("no_sale");
    expect(chipOf("nope")).toBe("");
  });

  it("sorts price by spread with unpriced groups last either way, and headings cycle back to none", () => {
    const rows = fold([
      group("p0", "candidate", [member(1), member(2)], { price_count: 1 }),
      group("p5", "candidate", [member(3), member(4)], { price_count: 2, price_spread: 5 }),
      group("p9", "candidate", [member(5), member(6)], { price_count: 2, price_spread: 9 }),
    ]);
    expect(visibleRows(rows, "all", "", "", { col: "price", desc: true }).map((r) => r.group_key)).toEqual(["p9", "p5", "p0"]);
    expect(visibleRows(rows, "all", "", "", { col: "price", desc: false }).map((r) => r.group_key)).toEqual(["p5", "p9", "p0"]);
    const a = nextSort(null, "price");
    expect(a).toEqual({ col: "price", desc: true });
    const b = nextSort(a, "price");
    expect(b).toEqual({ col: "price", desc: false });
    expect(nextSort(b, "price")).toBeNull();
    expect(nextSort(null, "name")).toEqual({ col: "name", desc: false });
  });

  it("checks prices against the commonest one, never against a leftover price", () => {
    const ms = [member(1, { list_price: 99 }), member(2, { list_price: 99 }), member(3, { list_price: 55, price_stale: true }), member(4, { list_price: 120 })];
    expect(referencePrice(ms, 4)).toBe(99);
    expect(priceState(ms[2]!, 99)).toBe("stale");
    expect(priceState(ms[3]!, 99)).toBe("off");
    expect(priceState(member(5), 99)).toBe("none");
    // A tie goes to the canonical's price.
    expect(referencePrice([member(1, { list_price: 10 }), member(2, { list_price: 20 })], 2)).toBe(20);
  });

  it("words the price check and the list's price cell", () => {
    const g = group("v", "candidate", [member(1, { list_price: 99 }), member(2, { list_price: 120 })], { price_count: 2, price_min: 99, price_max: 120, price_spread: 21 });
    expect(verdict(g, 99)).toMatchObject({ tone: "off", headline: "Prices differ by $21.00", figure: "$99.00 – $120.00" });
    expect(priceCell(g).text).toBe("$99.00 – $120.00");
    const same = { ...g, price_spread: 0, price_max: 99, price_match: true };
    expect(verdict(same, 99)).toMatchObject({ tone: "match", headline: "Same price on all 2 listings" });
    expect(priceCell(same)).toMatchObject({ text: "$99.00", same: true });
    expect(verdict({ ...g, price_count: 0 }, 0)).toMatchObject({ tone: "none", figure: "—" });
  });

  it("preselects the lowest cost, else an existing canonical, else the server's pick", () => {
    expect(preselectCanonical(group("x", "candidate", [member(1, { is_canonical: true }), member(2, { is_recommended: true, dealer_price: 40 })]))).toBe(2);
    expect(preselectCanonical(group("x", "candidate", [member(1, { is_canonical: true }), member(2, { is_recommended: true })]))).toBe(1);
    expect(preselectCanonical(group("x", "candidate", [member(1), member(2, { is_recommended: true })]))).toBe(2);
  });

  it("offers each field's cards, marks repeats, and defaults to the canonical's value", () => {
    const ms = [
      member(1, { assets: { title: "Same", has_image: true, image_id: 7 } }),
      member(2, { assets: { title: "Same", has_image: true, image_id: 8 }, is_recommended: true }),
      member(3, { assets: { title: "Other", has_image: true, image_id: 7 } }),
    ];
    const img = fieldOptions(ms, "image");
    expect(img.allSame).toBe(false);
    expect(img.options.map((o) => o.sameAs)).toEqual([0, 0, 1]);
    expect(fieldOptions(ms.slice(0, 2), "title").allSame).toBe(true);
    expect(winnerDefaults(ms, 3)).toMatchObject({ image: 3, title: 3, gallery: 0 });
    expect(winnerDefaults(ms, 9)).toMatchObject({ image: 2 });
  });
});
