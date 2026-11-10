import { describe, expect, it } from "vitest";
import { autoGate, checkNumbers, enrichToday, liveSources, runRows, sourceRows, sourcesState, titlesState, type EnrichWriteSummary, type TitleSummary } from "../../src/workbench/writingApi";

const rules = {
  tidy: { label: "Tidy", enabled: true, approved: true },
  caps: { label: "Caps", enabled: true, approved: false },
  off: { label: "Off", enabled: false, approved: true },
};

describe("writing: titles", () => {
  it("says the writer's state in one line", () => {
    expect(titlesState({ enabled: true, auto: false, batch_size: 25, min_confidence: 85 })).toBe("Writing titles On · auto-apply Off at confidence 85+ · 25 per batch");
  });
  it("counts only rules in use for the auto-apply gate, and says when nothing may", () => {
    expect(autoGate(rules)).toContain("(1 of the 2 rules in use may)");
    expect(autoGate({ caps: rules.caps })).toBe("No rule may auto-apply yet, so nothing is written without review.");
  });
  it("keeps the numbers inside what the store saves", () => {
    expect(checkNumbers("25", "85")).toEqual({ batch_size: 25, min_confidence: 85 });
    expect(checkNumbers("0", "85")).toBeNull();
    expect(checkNumbers("51", "85")).toBeNull();
    expect(checkNumbers("10", "49")).toBeNull();
    expect(checkNumbers("2.5", "90")).toBeNull();
  });
});

describe("writing: descriptions and pictures", () => {
  const e: EnrichWriteSummary = {
    counts: { "content:vendor_page": { applied: 4, proposed: 2 } },
    rules: { "content:vendor_page": { label: "Maker page", approved: true }, "content:feed": { label: "Feed", approved: false }, "image:old": { label: "Old", approved: true, retired: true } },
    settings: { enabled: true },
    state: { day: "2026-11-10", today: { content: 3, image: 1 }, last_batch_at: "2026-11-10 05:00:00" },
    today: "2026-11-10",
  };
  it("leaves retired sources out and counts each source's rows", () => {
    expect(liveSources(e).map(([id]) => id)).toEqual(["content:vendor_page", "content:feed"]);
    expect(sourceRows(e, "content:vendor_page")).toBe(6);
    expect(sourceRows(e, "content:feed")).toBe(0);
  });
  it("says the state with the sources that write without review", () => {
    expect(sourcesState(e)).toBe("Writing to products On · 1 source writes without review");
    expect(sourcesState({ ...e, settings: { enabled: false }, rules: {} })).toBe("Writing to products Off · 0 sources write without review");
  });
  it("shows today's counts only when the state is today's", () => {
    expect(enrichToday(e)).toBe("Today: 3 descriptions, 1 pictures written. Last batch: 2026-11-10 05:00:00");
    expect(enrichToday({ ...e, state: { ...e.state, day: "2026-11-09" } })).toBe("Today: 0 descriptions, 0 pictures written. Last batch: 2026-11-10 05:00:00");
    expect(enrichToday({ ...e, state: { today: [] } })).toBe("Today: 0 descriptions, 0 pictures written. Last batch: never");
  });
});

describe("Product Data runs", () => {
  const s = {
    apply: { enabled: true, auto: false, batch_size: 25, min_confidence: 85 }, rules, auto: 3, today: "2026-11-10",
    gen_state: { status: "complete", completed_at: "2026-11-10 09:09", stats: { products: 7298, proposals: 13, new: 0, changed: 1, reopened: 0, kept_decisions: 0 } },
    app_state: { status: "complete", completed_at: "2026-11-10 00:15", day: "2026-11-09", applied_today: 5, applied_total: 7142, failed_total: 0, last_error: null },
    desc: { status: { candidate: 3, needs_ai: 7 }, fetch: { http_403: 2 } },
    fetch: { status: "complete", completed_at: "2026-11-10 03:30", totals: { read: 40 } },
    urls: { auto: 1, review: 2, approved: 3, last_run: "2026-11-10" },
    picker: { last_run: "", totals: { picked: 4, none: 1 } },
  } as unknown as TitleSummary;
  const rows = Object.fromEntries(runRows(s));
  it("lists what ran, with yesterday's writes not counted as today's", () => {
    expect(rows["Last composition"]).toBe("complete 2026-11-10 09:09");
    expect(rows["Proposals"]).toBe("13 (new 0, changed 1, reopened 0, decisions kept 0)");
    expect(rows["Titles written today"]).toBe("0");
    expect(rows["Titles written in total"]).toBe("7,142");
    expect(rows["Maker page fetch"]).toBe("complete 2026-11-10 03:30: read 40");
    expect(rows["AI picks"]).toBe("last run never, 4 picked, 1 none, waiting 7");
    expect("Last error" in rows).toBe(false);
  });
});
