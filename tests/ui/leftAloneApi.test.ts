import { describe, expect, it } from "vitest";
import { brandChoices, leftRows, leftState, saveBody, savedText, type LeftAloneSummary } from "../../src/workbench/leftAloneApi";

const s: LeftAloneSummary = {
  brands: [{ id: 456, name: "Prism Sound", count: 38, fixed: false }, { id: 359, name: "Our Store", count: 12, fixed: true }],
  products: [{ id: 20881, title: "Old Renewal", sku: "1427-101" }],
  excluded: 51,
  not_sold: { count: 1484 },
  all_brands: [{ id: 359, name: "Our Store" }, { id: 456, name: "Prism Sound" }, { id: 7, name: "Acme" }],
};

describe("left alone", () => {
  it("lists brands then products, the store's own brand fixed", () => {
    expect(leftRows(s).map((r) => [r.key, r.label, r.products, r.fixed])).toEqual([
      ["b456", "Prism Sound", "38", false], ["b359", "Our Store", "12", true], ["p20881", "#20881 Old Renewal (1427-101)", "1", false],
    ]);
  });
  it("offers only brands not left alone yet", () => {
    expect(brandChoices(s)).toEqual([{ id: 7, name: "Acme" }]);
  });
  it("sends the whole of both lists after one change, never the fixed brand", () => {
    expect(saveBody(s, {})).toEqual({ brands: [456], products: [20881] });
    expect(saveBody(s, { addBrand: 7 })).toEqual({ brands: [456, 7], products: [20881] });
    expect(saveBody(s, { removeBrand: 456 })).toEqual({ brands: [], products: [20881] });
    expect(saveBody(s, { addProduct: "  SKU-9 " })).toEqual({ brands: [456], products: [20881, "SKU-9"] });
    expect(saveBody(s, { removeProduct: 20881 })).toEqual({ brands: [456], products: [] });
  });
  it("says the state and what a save did", () => {
    expect(leftState(s)).toBe("51 products left alone. Products no supplier sells are skipped too.");
    expect(leftState({ ...s, excluded: 1, not_sold: [] })).toBe("1 product left alone.");
    expect(savedText({ excluded: 89, removed: 3, restored: 0, unknown: ["X1"], summary: s })).toBe("89 products left alone; 3 open proposals removed. Not found: X1.");
    expect(savedText({ excluded: 51, removed: 0, restored: 38, unknown: [], summary: s })).toBe("51 products left alone; 38 products back in Product Data.");
  });
});
