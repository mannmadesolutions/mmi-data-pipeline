import { describe, expect, it } from "vitest";
import { adds, cellActions, choiceBody, proposalHosts, fillDone, fillLabel, fillRows, gainOf, idsWith, sitesIn, siteViewCount, kindsText, tilesOf, type Gaps, type SiteRow } from "../../src/workbench/mapsApi";

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
