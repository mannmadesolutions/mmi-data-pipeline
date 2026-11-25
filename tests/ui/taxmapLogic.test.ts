import { describe, expect, it } from "vitest";
import {
  applyCase, chipCounts, filterTermTree, inSource, nextSort, offerCreate, ruleMatches, ruleValueMatches, sourceKey, statusLabel, statusOf, suggestTermName,
  toTitleCase, visibleRows, type ValueRow,
} from "../../src/workbench/taxmapLogic";

const row = (value: string, o: Partial<ValueRow> = {}): ValueRow => ({
  source_value: value, source_field: "brand", wc_taxonomy: "product_brand", redirected_from: "", supplier: "xchange", count: 1, mapping_id: 0,
  wc_term_id: 0, wc_term_name: "", missing_term_id: 0, auto_create: false, via_rule: false, source_file: "xchange-products.json", sample_products: [],
  variants: [], excluded: false, excluded_at: "", excluded_by: "", ...o,
});

describe("taxmapLogic", () => {
  it("reads a value's status: excluded first, then skip, mapped, unmapped", () => {
    expect(statusOf(row("A"))).toBe("unmapped");
    expect(statusOf(row("A", { wc_term_id: 5 }))).toBe("mapped");
    expect(statusOf(row("A", { wc_term_id: -1 }))).toBe("skip");
    expect(statusOf(row("A", { wc_term_id: 5, excluded: true }))).toBe("excluded");
    expect(statusLabel(row("A", { wc_term_id: 5, via_rule: true }))).toBe("Mapped (rule)");
    expect(statusLabel(row("A", { missing_term_id: 77 }))).toBe("Term deleted");
  });

  it("counts chips: excluded values only under Excluded, deleted terms in variations count too", () => {
    const rows = [
      row("A"), row("B", { wc_term_id: 3 }), row("C", { wc_term_id: -1 }), row("D", { excluded: true }),
      row("E", { missing_term_id: 9 }), row("F", { wc_term_id: 4, variants: [{ mapping_id: 1, profile_id: "p", profile_label: "P", wc_term_id: 0, wc_term_name: "", missing_term_id: 8 }] }),
    ];
    expect(chipCounts(rows)).toEqual({ unmapped: 2, mapped: 3, missing: 2, excluded: 1, all: 5 });
  });

  it("lists a source's values, most products first; search reads values, sample products and terms", () => {
    const rows = [row("Moog", { count: 3, sample_products: ["Mother-32"] }), row("Roland", { count: 9, wc_term_name: "Roland Corp", wc_term_id: 2 }), row("x", { supplier: "skuport", count: 50 })];
    expect(inSource(rows, sourceKey({ supplier: "xchange", source_field: "brand" })).map((r) => r.source_value)).toEqual(["Moog", "Roland"]);
    expect(visibleRows(rows, "all", "", null).map((r) => r.source_value)).toEqual(["x", "Roland", "Moog"]);
    expect(visibleRows(rows, "all", "mother", null).map((r) => r.source_value)).toEqual(["Moog"]);
    expect(visibleRows(rows, "all", "corp", null).map((r) => r.source_value)).toEqual(["Roland"]);
    expect(visibleRows(rows, "all", "", { col: "value", desc: false }).map((r) => r.source_value)).toEqual(["Moog", "Roland", "x"]);
    expect(nextSort(null, "count")).toEqual({ col: "count", desc: true });
    expect(nextSort({ col: "count", desc: false }, "count")).toBeNull();
    expect(nextSort(null, "value")).toEqual({ col: "value", desc: false });
  });

  it("matches alias rules as the importer does, regex included, and counts what a rule catches", () => {
    expect(ruleValueMatches("contains", "roland", "ROLAND CORP")).toBe(true);
    expect(ruleValueMatches("starts_with", "rol", "Roland")).toBe(true);
    expect(ruleValueMatches("ends_with", "corp", "Roland Corp")).toBe(true);
    expect(ruleValueMatches("equals", "roland", "Roland")).toBe(true);
    expect(ruleValueMatches("regex", "/^r.l/i", "ROLAND")).toBe(true);
    expect(ruleValueMatches("regex", "(", "ROLAND")).toBe(false);
    expect(ruleValueMatches("contains", "", "ROLAND")).toBe(false);
    const rows = [row("Roland"), row("ROLAND CORP", { wc_term_id: 4 }), row("Roland", { supplier: "skuport" }), row("Boss")];
    const rule = { label: "", supplier_id: "xchange", source_field: "", operator: "contains" as const, value: "roland", wc_taxonomy: "product_brand", wc_term_id: 0, wc_term_name: "" };
    expect(ruleMatches(rule, rows)).toEqual({ total: 2, unmapped: 1 });
    expect(ruleMatches({ ...rule, value: "" }, rows)).toBeNull();
  });

  it("suggests a new term's name: long all-caps words title-cased, acronyms kept", () => {
    expect(suggestTermName("EXAMPLE AUDIO WORKS")).toBe("Example Audio Works");
    expect(suggestTermName("AKG")).toBe("AKG");
    expect(suggestTermName("Moog Music")).toBe("Moog Music");
    expect(toTitleCase("KRK SYSTEMS AND THE DJ")).toBe("KRK Systems and the DJ");
    expect(applyCase("upper", "Roland", "ROLAND")).toBe("ROLAND");
    expect(applyCase("source", "Roland", "rOLAND")).toBe("rOLAND");
  });

  it("filters a category tree to matches with their parents and children", () => {
    const tree = [
      { id: 1, name: "Software", path: "Software", depth: 0, count: 9 },
      { id: 2, name: "Plugins", path: "Software › Plugins", depth: 1, count: 5 },
      { id: 3, name: "Reverb", path: "Software › Plugins › Reverb", depth: 2, count: 2 },
      { id: 4, name: "Hardware", path: "Hardware", depth: 0, count: 3 },
    ];
    expect(filterTermTree(tree, "plug").map((x) => [x.term.id, x.kind])).toEqual([[1, "context"], [2, "match"], [3, "child"]]);
    expect(filterTermTree(tree, "")).toHaveLength(4);
    expect(offerCreate({ tree }, "plugins")).toBe("");
    expect(offerCreate({ terms: [] }, " Moog ")).toBe("Moog");
    expect(offerCreate({ terms: [] }, "M")).toBe("");
  });
});
