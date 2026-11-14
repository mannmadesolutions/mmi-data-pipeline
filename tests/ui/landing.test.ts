import { describe, expect, it } from "vitest";
import { readLanding, sectionKey } from "../../src/workbench/landing";

describe("landing", () => {
  it("reads the wb_ parameters a link sets", () => {
    expect(readLanding("?page=mmi-data-pipeline&pipeline_tab=workbench&wb_sec=maps&wb_site=https%3A%2F%2FWWW.Example.com%2Fx&wb_q=%20175635%20&wb_brand=12&wb_bundle=99"))
      .toEqual({ sec: "makers", q: "175635", brand: 12, site: "www.example.com", bundle: 99, product: 0, kind: "", bySite: false });
  });
  it("ignores unknown sections and bad numbers", () => {
    expect(readLanding("?wb_sec=nope&wb_brand=-3&wb_bundle=x")).toEqual({ sec: "", q: "", brand: 0, site: "", bundle: 0, product: 0, kind: "", bySite: false });
  });
  it("opens the section a link's other parameter belongs to when it names none", () => {
    expect(readLanding("?wb_brand=12")).toMatchObject({ sec: "makers", bySite: false });
    expect(readLanding("?wb_site=acme.example")).toMatchObject({ sec: "makers", bySite: true });
    expect(readLanding("?wb_bundle=4").sec).toBe("bundles");
    expect(readLanding("?wb_q=Synth").sec).toBe("data");
    expect(readLanding("?wb_sec=titles&wb_q=Synth")).toMatchObject({ sec: "decide", kind: "titles", q: "Synth" });
    expect(readLanding("?wb_product=226967")).toMatchObject({ sec: "decide", product: 226967 });
  });
  it("keeps older keys and section hashes working", () => {
    expect(sectionKey("proposals")).toBe("decide");
    expect(sectionKey("parts")).toBe("part-numbers");
    expect(sectionKey("changes")).toBe("changes");
    expect(readLanding("?page=x", "#mmi-wb-rules").sec).toBe("standing");
    expect(readLanding("?page=x", "#mmi-wb-find").sec).toBe("data");
    expect(readLanding("?wb_sec=upkeep", "#mmi-wb-rules").sec).toBe("upkeep");
  });
  it("lands Brands & sites and Site maps links on Makers, listed the way each listed", () => {
    expect(readLanding("?wb_sec=sites")).toMatchObject({ sec: "makers", bySite: false });
    expect(readLanding("?wb_sec=maps")).toMatchObject({ sec: "makers", bySite: true });
    expect(readLanding("?wb_sec=sites&wb_brand=5612&wb_site=ilio.com")).toMatchObject({ sec: "makers", brand: 5612, site: "ilio.com", bySite: false });
    expect(readLanding("?page=x", "#mmi-wb-brand-sites")).toMatchObject({ sec: "makers", bySite: false });
    expect(readLanding("?page=x", "#mmi-wb-site-maps")).toMatchObject({ sec: "makers", bySite: true });
  });
  it("opens Needs a decision on a review queue's pill (the queues were the Review menu)", () => {
    expect(readLanding("?wb_sec=picks")).toMatchObject({ sec: "decide", kind: "pictures" });
    expect(readLanding("?wb_sec=pages")).toMatchObject({ sec: "decide", kind: "pages" });
    expect(readLanding("?wb_sec=proposals")).toMatchObject({ sec: "decide", kind: "descriptions" });
    expect(readLanding("?page=x", "#mmi-wb-proposals")).toMatchObject({ sec: "decide", kind: "titles" });
    expect(readLanding("?wb_sec=decide&wb_kind=key:flag:bundle_not_in_category")).toMatchObject({ sec: "decide", kind: "key:flag:bundle_not_in_category" });
    expect(readLanding("?wb_sec=decide&wb_kind=key:<x>").kind).toBe("");
  });
});

describe("Map parts, a pill of Needs a decision", () => {
  it("a link's wb_kind=maps opens it, and a Site maps link still opens the site", () => {
    expect(readLanding("?wb_sec=decide&wb_kind=maps")).toMatchObject({ sec: "decide", kind: "maps" });
    expect(readLanding("?wb_kind=maps").kind).toBe("maps");
    expect(readLanding("?wb_sec=maps&wb_site=a.example")).toMatchObject({ sec: "makers", site: "a.example", kind: "", bySite: true });
  });
});
