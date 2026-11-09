import { describe, expect, it } from "vitest";
import {
  bulkUseText, confBand, EMPTY_PAGES, groupCount, markedNone, movedTo, pageActions, pageFills, pagesQuery, rowQuery, urlError,
  type PageListReply, type PageRow,
} from "../../src/workbench/pagesApi";

const base: PageRow = {
  id: 7, title: "Example Synth (Download)", edit_url: "", view_url: "", image: "", sku: "EX-1", brand: { id: 3, name: "Example" },
  current: "", verified: false, proposed: "", status: "none", reason: "", reason_label: "", score: 0, note: "",
  page_title: "", page_status: "", result: "not_read", result_label: "Not read yet", result_badge: "info", fetch_status: "",
  read_at: "", read_note: "", read_final: "", coverage: null, op_group: "none", op_conf: null, op_wrong: "", op_wrong_note: "",
};
const row = (o: Partial<PageRow>): PageRow => ({ ...base, ...o });

describe("product pages: what a row offers", () => {
  it("a proposed page: use it or turn it down first", () => {
    expect(pageActions(row({ op_group: "open", status: "candidate", proposed: "https://maker.example/synth" }))).toEqual(["use", "reject", "set", "none"]);
  });
  it("no page: set one, or say the maker doesn't publish it", () => {
    expect(pageActions(row({}))).toEqual(["set", "none"]);
  });
  it("a page gone: read again, change or clear it", () => {
    expect(pageActions(row({ op_group: "broken", current: "https://maker.example/old", result: "gone" }))).toEqual(["read", "set", "clear", "none"]);
  });
  it("a page moved on its own site offers its new address first", () => {
    const r = row({ op_group: "broken", current: "https://maker.example/old", read_final: "https://www.maker.example/new" });
    expect(movedTo(r)).toBe("https://www.maker.example/new");
    expect(pageActions(r)[0]).toBe("useMoved");
  });
  it("a move to another site is never offered", () => {
    expect(movedTo(row({ current: "https://maker.example/old", read_final: "https://store.example/x" }))).toBe("");
  });
  it("not a product page: keep it, change or clear it", () => {
    expect(pageActions(row({ op_group: "wrong", current: "https://maker.example/" }))).toEqual(["keep", "set", "clear", "none"]);
  });
  it("a decided page: scrape again; a rejected one can go back to review", () => {
    expect(pageActions(row({ op_group: "approved", status: "approved", current: "https://maker.example/synth" }))).toEqual(["scrape", "set", "none"]);
    expect(pageActions(row({ op_group: "rejected", status: "rejected", current: "https://maker.example/synth" }))).toEqual(["scrape", "reset", "set", "none"]);
  });
  it("marked as having no page: only look again or set one", () => {
    const r = row({ op_group: "every", fetch_status: "no_page", result: "no_page" });
    expect(markedNone(r)).toBe(true);
    expect(pageActions(r)).toEqual(["look", "set"]);
  });
});

describe("product pages: filters and words", () => {
  it("asks only for the filters set, and one row with them", () => {
    expect(pagesQuery(EMPTY_PAGES)).toBe("page=1&per_page=50");
    expect(pagesQuery({ ...EMPTY_PAGES, group: "open", conf: "high", brand: 3, q: " synth ", page: 2 })).toBe("page=2&per_page=50&group=open&conf=high&brand=3&q=synth");
    expect(rowQuery({ ...EMPTY_PAGES, group: "open", page: 4 }, 9)).toBe("page=1&per_page=50&group=open&ids[]=9");
  });
  it("rates in the badge's bands", () => {
    expect([confBand(90), confBand(85), confBand(70), confBand(55), confBand(54), confBand(null)]).toEqual(["high", "high", "mid", "low", "bad", "unrated"]);
  });
  it("waiting counts the four waiting groups", () => {
    const r = { counts: { open: 1, broken: 2, none: 3, wrong: 4, approved: 50, rejected: 6, every: 900 } } as PageListReply;
    expect(groupCount(r, "")).toBe(10);
    expect(groupCount(r, "every")).toBe(900);
    expect(groupCount(null, "open")).toBeNull();
  });
  it("checks a typed address before saving it", () => {
    expect(urlError("", "")).toMatch(/Type or paste/);
    expect(urlError("maker.example/x", "")).toMatch(/isn't a web address/);
    expect(urlError("ftp://maker.example/x", "")).toMatch(/http/);
    expect(urlError("https://maker.example/x/", "https://maker.example/x")).toMatch(/already its page/);
    expect(urlError(" https://maker.example/y ", "https://maker.example/x")).toBe("");
  });
  it("says what the page would add", () => {
    const r = row({ coverage: { score: 1, attrs: [
      { key: "images", label: "Images", state: "missing", detail: "", page: "2 images", fills: true },
      { key: "description", label: "Description", state: "ok", detail: "", page: "", fills: true },
      { key: "os", label: "System requirements", state: "missing", detail: "", page: "", fills: false },
    ] } });
    expect(pageFills(r)).toEqual(["Images"]);
  });
  it("bulk approval names what it left alone", () => {
    expect(bulkUseText({ done: 1, skipped: [] })).toBe("1 page approved and being read.");
    expect(bulkUseText({ done: 3, skipped: [4, 5] })).toMatch(/^3 pages approved.*2 left as they are/);
  });
});
