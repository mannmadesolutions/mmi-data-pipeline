import { describe, expect, it } from "vitest";
import {
  adds, cellActions, choiceBody, drillRows, findProducts, gapKey, layoutName, noteParts, partRows, partsHint, proposalHosts, fillDone, fillLabel, fillRows, gainOf, idsWith,
  sitesIn, siteViewCount, kindsText, sliceN, sortParts, sortSites, tally, tallyText, tilesOf, waitingParts, waitingSites, type Gaps, type SiteRow,
} from "../../src/workbench/mapsApi";

const site = (host: string, status: string, fields: number): SiteRow => ({
  host, status, pattern: "", render: 0, stats: { products: 5, pages: 5, read: 5, refused: 0, blocked: 0, at: "" },
  gaps: fields < 0 ? [] : { products: 5, gain: fields ? 2 : 0, fields, proposals: 0, pending: 0, at: "" }, map_at: "", checked: "",
});
const rows = [site("b.example", "draft", 10), site("a.example", "confirmed", 3), site("c.example", "auto", 0), site("d.example", "refused", -1)];

describe("site maps: the site list", () => {
  it("gaps to fill: most first; a site never measured has none", () => {
    expect(sitesIn(rows, "gain", "").map((r) => r.host)).toEqual(["b.example", "a.example"]);
    expect(gainOf(rows[3]!)).toBe(0);
  });
  it("by map status, and by name", () => {
    expect(sitesIn(rows, "draft", "").map((r) => r.host)).toEqual(["b.example"]);
    expect(siteViewCount(rows, "all")).toBe(4);
    expect(sitesIn(rows, "all", "c.ex").map((r) => r.host)).toEqual(["c.example"]);
  });
});

describe("site maps: the Fill", () => {
  const g = {
    host: "a.example", status: "draft", products: 3, ready: { read: 2, blocked: 0, unread: 1, broken: 0 }, pending: 0, totals: {}, at: "",
    fill: { confirm: true, products: 2, fields: 3, per: { images: 2, manual: 1 }, waiting: 0, why: "", scrape: 1, pictures: 2 },
    rows: [
      { id: 1, title: "One", url: "", edit: "", page: "read", status: "ok", note: "", cells: { images: ["A", "", "", ""], manual: ["A", "", "", ""], description: ["H", "", "", ""] } },
      { id: 2, title: "Two", url: "", edit: "", page: "read", status: "ok", note: "", cells: { images: ["A", "", "", ""], description: ["G", "", "", ""] } },
      { id: 3, title: "Three", url: "", edit: "", page: "unread", status: "", note: "", cells: { images: ["U", "", "", ""] } },
    ],
  } as unknown as Gaps;
  it("writes to the products with a field their page adds, and says what each gets", () => {
    expect(fillRows(g).map((r) => r.id)).toEqual([1, 2]);
    expect(adds(g.rows[0]!)).toEqual(["Pictures", "Manual"]);
  });
  it("names the button by what it does", () => {
    expect(fillLabel(g.fill)).toBe("Confirm map & fill 3 gaps on 2 products");
    expect(fillLabel({ ...g.fill, confirm: false, fields: 1, products: 1 })).toBe("Fill 1 gap on 1 product");
  });
  it("finds the products the readiness line acts on", () => {
    expect(idsWith(g, "unread")).toEqual([3]);
  });
  it("says what a Fill queued", () => {
    expect(fillDone({ confirmed: true, scrape: 1, pictures: 2 })).toBe("Map confirmed. 1 product queued to read and write; 2 queued for pictures. What it wrote shows under Last fills, with Undo.");
  });
});

describe("site maps: pictures in the Fill review", () => {
  it("shows the pictures it ends up with, then the page's vetoed ones, once each", () => {
    const m = { final: [{ url: "a", kind: "kept", key: "1" }, { url: "b", kind: "new", key: "2" }], page: [{ url: "b", kind: "new", key: "2" }, { url: "c", kind: "vetoed", key: "3", choice: 9 }], featured: 0, counts: {}, summary: "" };
    expect(tilesOf(m).map((p) => p.url)).toEqual(["a", "b", "c"]);
    expect(tilesOf(undefined)).toEqual([]);
  });
  it("names a picture by attachment when it has one, else by address", () => {
    expect(choiceBody("veto", "site", "a.example", 7, { att: 12, url: "x" })).toEqual({ kind: "veto", scope: "site", host: "a.example", product: 7, att: 12, url: "" });
    expect(choiceBody("featured", "product", "a.example", 7, { url: "https://a.example/p.jpg" })).toEqual({ kind: "featured", scope: "product", host: "a.example", product: 7, att: 0, url: "https://a.example/p.jpg" });
  });
  it("says what happens to a picture across products", () => {
    expect(kindsText({ new: 3, kept: 1, duplicate: 0 })).toBe("New on 3, kept on 1");
  });
});

describe("site maps: deciding a cell", () => {
  const r = { id: 1, title: "", url: "", edit: "", page: "read", status: "ok", note: "", none: ["manual"],
    cells: { os: ["R", "", "", ""], description: ["G", "", "", ""], audio: ["S", "", "", ""], manual: ["-", "", "", ""], images: ["H", "", "", ""] } } as unknown as Parameters<typeof cellActions>[0];
  it("a requirement to review: the page's or ours; a gap: its page has none; a closed one: take it back", () => {
    expect(cellActions(r, "os")).toEqual(["use", "keep"]);
    expect(cellActions(r, "description")).toEqual(["none"]);
    expect(cellActions(r, "audio")).toEqual(["none"]);
    expect(cellActions(r, "manual")).toEqual(["unnone"]);
    expect(cellActions(r, "images")).toEqual([]);
  });
});

describe("site maps: proposed maps", () => {
  it("lists the sites with a part waiting for approval", () => {
    const p = (host: string, status: string) => ({ id: 1, host, field: "pictures", layout: -1, scope: "site", sel: "", mode: "img", found: 1, of: 1, note: "", checked: "", status, samples: [] });
    expect([...proposalHosts([p("a.example", "proposed"), p("b.example", "approved"), p("a.example", "proposed")])]).toEqual(["a.example"]);
  });
});

describe("site maps: the open site, part by part", () => {
  const cell = (c: string, now = "", why = "") => [c, "", now, why] as [string, string, string, string];
  const g = {
    host: "a.example", status: "draft", products: 4, ready: { read: 4, blocked: 0, unread: 0, broken: 0 }, pending: 1, totals: {}, at: "",
    fill: { confirm: true, products: 2, fields: 3, per: [], waiting: 0, why: "", scrape: 1, pictures: 2 },
    parts: { pictures: { sel: "div.gallery img", mode: "img", broken: false }, manual: { sel: "a.pdf", mode: "links", broken: true } },
    rows: [
      { id: 1, title: "Synth One", url: "", edit: "", page: "read", status: "ok", note: "", cells: { images: cell("A"), manual: cell("H"), size: cell("-") } },
      { id: 2, title: "Drum Two", url: "", edit: "", page: "read", status: "ok", note: "", cells: { images: cell("H"), manual: cell("G"), size: cell("-") } },
      { id: 3, title: "Bass Three", url: "", edit: "", page: "read", status: "ok", note: "", cells: { images: cell("C"), manual: cell("A"), size: cell("-") } },
      { id: 4, title: "Keys Four", url: "", edit: "", page: "read", status: "ok", note: "", cells: { images: cell("A"), manual: cell("P"), size: cell("-") } },
    ],
  } as unknown as Gaps;
  const prop = (id: number, field: string, status = "proposed", layout = -1) =>
    ({ id, host: "a.example", field, layout, scope: layout < 0 ? "site" : "layout", sel: "x", mode: "", found: 1, of: 2, note: "", checked: "", status, samples: [] });

  it("names a proposal's part by the gaps' column", () => {
    expect(gapKey("pictures")).toBe("images");
    expect(gapKey("requirements")).toBe("os");
    expect(gapKey("manual")).toBe("manual");
  });
  it("counts a part's products per slice, leaving out the ones it doesn't apply to", () => {
    const t = tally(g.rows, "images");
    expect(t.applies).toBe(4);
    expect(sliceN(t, "add")).toBe(2);
    expect(sliceN(t, "check")).toBe(1);
    expect(tallyText(t)).toBe("Page adds 2 · Has it 1 · Being checked 1");
    expect(tally(g.rows, "size").applies).toBe(0);
  });
  it("one row per part that applies, has a saved part or a proposal, with its proposals under it", () => {
    const rows = partRows(g, g.rows, [prop(7, "pictures"), prop(8, "requirements"), prop(9, "pictures", "approved"), prop(10, "disclaimer")]);
    expect(rows.map((r) => r.field)).toEqual(["images", "os", "manual", "disclaimer"]);
    expect(rows[0]!.sel).toBe("div.gallery img");
    expect(rows[0]!.proposals.map((p) => p.id)).toEqual([7]);
    expect(rows[1]!.proposals.map((p) => p.id)).toEqual([8]);
    expect(rows[2]!.broken).toBe(true);
    expect(rows[3]!.label).toBe("Disclaimer");
  });
  it("sorts parts by a heading, and goes back to the gaps' order", () => {
    const rows = partRows(g, g.rows, [prop(8, "requirements")]);
    expect(sortParts(rows, { col: "adds", desc: true }).map((r) => r.field)).toEqual(["images", "manual", "os"]);
    expect(sortParts(rows, { col: "map", desc: true })[0]!.field).toBe("os");
    expect(sortParts(rows, null)).toBe(rows);
  });
  it("lists a part's products in the bar's order, one slice or all", () => {
    expect(drillRows(g.rows, "manual", "", null).map((r) => r.id)).toEqual([3, 1, 4, 2]);
    expect(drillRows(g.rows, "manual", "gap", null).map((r) => r.id)).toEqual([2]);
    expect(drillRows(g.rows, "manual", "", { col: "id", desc: true }).map((r) => r.id)).toEqual([4, 3, 2, 1]);
    expect(drillRows(g.rows, "size", "", null)).toEqual([]);
  });
  it("keeps a cell a person closed in view, as its own slice", () => {
    const closed = [{ ...g.rows[1]!, none: ["manual"], cells: { ...g.rows[1]!.cells, manual: cell("-") } }];
    expect(sliceN(tally(closed, "manual"), "none")).toBe(1);
    expect(drillRows(closed, "manual", "none", null).map((r) => r.id)).toEqual([2]);
  });
  it("finds products by title or ID", () => {
    expect(findProducts(g.rows, "drum").map((r) => r.id)).toEqual([2]);
    expect(findProducts(g.rows, "3").map((r) => r.id)).toEqual([3]);
    expect(findProducts(g.rows, " ")).toBe(g.rows);
  });
  it("names a proposal's place, splits its note, and says what waiting parts mean for a Fill", () => {
    expect(layoutName({ scope: "site", layout: -1 })).toBe("Whole site");
    expect(layoutName({ scope: "layout", layout: 2 })).toBe("Layout C");
    expect(noteParts("Changed: took the first slide. This takes every slide.")).toEqual(["Changed: took the first slide.", "This takes every slide."]);
    expect(noteParts("Checked")).toEqual(["Checked", ""]);
    expect(partsHint(0)).toBe("");
    expect(partsHint(1)).toContain("1 proposed part waits under Parts");
  });
  it("sorts the site list by a heading", () => {
    expect(sortSites(rows, { col: "gaps", desc: true }).map((r) => r.host)).toEqual(["b.example", "a.example", "c.example", "d.example"]); // ties A–Z
    expect(sortSites(rows, { col: "site", desc: false })[0]!.host).toBe("a.example");
  });
});

describe("map parts under Needs a decision", () => {
  const p = (id: number, host: string, field: string, status = "proposed", found = 1) => ({ id, host, field, layout: -1, scope: "site", sel: "", mode: "", found, of: 4, note: "", checked: "", status, samples: [] });
  const all = [p(1, "b.example", "manual", "proposed", 4), p(2, "a.example", "pictures", "proposed", 1), p(3, "a.example", "manual", "approved"), p(4, "a.example", "videos", "proposed", 2)];
  it("lists the waiting parts, by site or part", () => {
    expect(waitingParts(all, "", "", null).map((x) => x.id)).toEqual([1, 2, 4]);
    expect(waitingParts(all, "a.example", "", null).map((x) => x.id)).toEqual([2, 4]);
    expect(waitingParts(all, "", "manual", null).map((x) => x.id)).toEqual([1]);
    expect(waitingParts(all, "", "", { col: "found", desc: true }).map((x) => x.id)).toEqual([1, 4, 2]);
    expect(waitingParts(all, "", "", { col: "site", desc: false }).map((x) => x.id)).toEqual([2, 4, 1]);
  });
  it("lists the sites with parts waiting, and how many", () => {
    expect(waitingSites(all)).toEqual([["a.example", 2], ["b.example", 1]]);
  });
});
