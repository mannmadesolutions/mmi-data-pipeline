import { describe, expect, it } from "vitest";
import { readLanding, sectionKey } from "../../src/workbench/landing";

describe("landing", () => {
  it("reads the wb_ parameters a link sets", () => {
    expect(readLanding("?page=mmi-data-pipeline&pipeline_tab=workbench&wb_sec=maps&wb_site=https%3A%2F%2FWWW.Example.com%2Fx&wb_q=%20175635%20&wb_brand=12&wb_bundle=99"))
      .toEqual({ sec: "maps", q: "175635", brand: 12, site: "www.example.com", bundle: 99 });
  });
  it("ignores unknown sections and bad numbers", () => {
    expect(readLanding("?wb_sec=nope&wb_brand=-3&wb_bundle=x")).toEqual({ sec: "", q: "", brand: 0, site: "", bundle: 0 });
  });
  it("opens the section a link's other parameter belongs to when it names none", () => {
    expect(readLanding("?wb_brand=12").sec).toBe("sites");
    expect(readLanding("?wb_site=acme.example").sec).toBe("maps");
    expect(readLanding("?wb_bundle=4").sec).toBe("bundles");
    expect(readLanding("?wb_q=Synth").sec).toBe("data");
    expect(readLanding("?wb_sec=titles&wb_q=Synth").sec).toBe("titles");
  });
  it("keeps older keys and section hashes working", () => {
    expect(sectionKey("proposals")).toBe("descriptions");
    expect(sectionKey("parts")).toBe("part-numbers");
    expect(sectionKey("changes")).toBe("changes");
    expect(readLanding("?page=x", "#mmi-wb-rules").sec).toBe("standing");
    expect(readLanding("?page=x", "#mmi-wb-find").sec).toBe("data");
    expect(readLanding("?wb_sec=upkeep", "#mmi-wb-rules").sec).toBe("upkeep");
  });
});
