import { describe, expect, it } from "vitest";
import { adds, fillDone, fillLabel, fillRows, gainOf, idsWith, sitesIn, siteViewCount, type Gaps, type SiteRow } from "../../src/workbench/mapsApi";

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
