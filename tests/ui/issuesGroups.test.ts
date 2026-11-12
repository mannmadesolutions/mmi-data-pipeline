import { describe, expect, it } from "vitest";
import { groupIssues, groupMode, type Issue } from "../../src/workbench/issuesApi";

const bundle: Issue = { key: "bundle_not_in_category", label: "Not in Software Bundles", help: "", tone: "warning", fixes: [{ kind: "action", action: "add_cat:11", label: "Add to Software Bundles", primary: true }, { kind: "cats", label: "Change the category…" }, { kind: "title", label: "Not a bundle: edit the title" }] };
const noBrand: Issue = { key: "no_brand", label: "No brand", help: "", tone: "error", fixes: [{ kind: "brand", label: "Set the brand…" }] };
const long: Issue = { key: "over_length", label: "Too long", help: "", tone: "warning", fixes: [{ kind: "title", label: "Edit the title" }] };

describe("alerts fixed alike", () => {
  it("groups the same alert across rows: errors first, then the commonest", () => {
    const g = groupIssues([{ id: 1, issues: [bundle] }, { id: 2, issues: [bundle, long] }, { id: 3, issues: [noBrand, bundle] }, { id: 4 }]);
    expect(g.map((x) => [x.key, x.ids])).toEqual([["no_brand", [3]], ["bundle_not_in_category", [1, 2, 3]], ["over_length", [2]]]);
    expect(g[1]!.fixes).toHaveLength(3);
  });
  it("runs one-click fixes for each product, opens the bulk picker for terms, and shows the rest one by one", () => {
    expect(bundle.fixes.map(groupMode)).toEqual(["each", "terms", "one-by-one"]);
    expect(groupMode({ kind: "brand", label: "" })).toBe("terms");
    expect(groupMode({ kind: "action", label: "" })).toBe("one-by-one"); // an action without its code can't run
  });
  it("has nothing to group when no row has an alert", () => {
    expect(groupIssues([{ id: 1, issues: [] }, { id: 2 }])).toEqual([]);
  });
});
