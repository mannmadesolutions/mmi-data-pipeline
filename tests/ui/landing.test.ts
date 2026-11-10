import { describe, expect, it } from "vitest";
import { readLanding } from "../../src/workbench/landing";

describe("landing", () => {
  it("reads the wb_ parameters a link sets", () => {
    expect(readLanding("?page=mmi-data-pipeline&pipeline_tab=workbench&wb_sec=maps&wb_site=https%3A%2F%2FWWW.Example.com%2Fx&wb_q=%20175635%20&wb_brand=12&wb_bundle=99"))
      .toEqual({ sec: "maps", q: "175635", brand: 12, site: "www.example.com", bundle: 99 });
  });
  it("ignores unknown sections and bad numbers", () => {
    expect(readLanding("?wb_sec=nope&wb_brand=-3&wb_bundle=x")).toEqual({ sec: "", q: "", brand: 0, site: "", bundle: 0 });
  });
});
