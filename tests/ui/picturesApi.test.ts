import { describe, expect, it } from "vitest";
import { folderLines, groupTable, inPlaceCount, outcomeOf, releaseLine, rowsQuery, runnerLine, saved, size, type PicturesSummary } from "../../src/workbench/picturesApi";

const g = (status: string, mode: string, n: number, pb = 0, pa = 0, bb = 0, ba = 0) => ({ status, mode, reason: "", reason_label: "", n, pics_before: pb, pics_after: pa, bytes_before: bb, bytes_after: ba });
const base: PicturesSummary = {
  groups: [g("skipped", "", 5), g("applied", "in_place", 2, 3, 3, 3000000, 1000000), g("applied", "vendor", 10, 10, 30, 10485760, 5242880), g("error", "vendor", 1)],
  running: false, done_today: 12, cap: 300, waiting: 0, activity: { manual: false, busy: false, reasons: [] },
  pass: { started_at: "2026-11-10 03:03", done_at: "2026-11-10 03:20" }, state: { note: "", last_run: { vendor: 3, in_place: 1, skipped: 0, error: 2 } },
  release: { released: { n: 1, bytes: 1024 }, quarantined: { n: 2, bytes: 2097152 } }, folders: [{ dir: "media-replaced-2026-10-10", n: 991, bytes: 203433597, purge_from: "2026-10-24 23:30" }],
};

describe("product pictures", () => {
  it("a written row's outcome is its mode; others their status", () => {
    expect(outcomeOf("applied", "in_place")).toBe("in_place");
    expect(outcomeOf("error", "vendor")).toBe("error");
  });
  it("sizes in KB, MB and GB", () => {
    expect(size(512000)).toBe("500 KB");
    expect(size(5242880)).toBe("5.0 MB");
    expect(size(1610612736)).toBe("1.50 GB");
  });
  it("says the runner's state, waiting and paused", () => {
    expect(runnerLine(base)).toBe("Runner idle. 12 of 300 products changed in the last 24 hours. Last pass finished 2026-11-10 03:20 UTC; the runner starts again only when you queue products. Last run: 3 vendor, 1 own re-saved, 0 left alone, 2 errors.");
    const busy = { ...base, running: true, waiting: 1200, pass: { started_at: "2026-11-10 03:03" }, state: {} };
    expect(runnerLine(busy)).toBe("Runner working. 12 of 300 products changed in the last 24 hours. 1,200 waiting. Pass started 2026-11-10 03:03 UTC.");
    expect(runnerLine({ ...busy, activity: { manual: true, busy: true, reasons: ["an import", "a fetch"] } })).toMatch(/^Runner waiting: an import; a fetch\./);
  });
  it("orders outcomes and totals only what changed", () => {
    const t = groupTable(base);
    expect(t.rows.map((r) => r.outcome)).toEqual(["vendor", "in_place", "skipped", "error"]);
    expect(t.total).toEqual({ n: 18, pb: 13, pa: 33, bb: 13485760, ba: 6242880 });
    expect(inPlaceCount(base)).toBe(2);
  });
  it("says where replaced media is and which folders may go", () => {
    expect(releaseLine(base)).toBe("Replaced media: 1 waiting 2 days (1 KB) · 0 kept because something else uses them · 2 in quarantine (2.0 MB) · 0 deleted for good (0 KB).");
    expect(folderLines(base)).toEqual({ head: "1 quarantine folder (194.0 MB) may be deleted with the owner’s go-ahead", items: ["media-replaced-2026-10-10: 991 files, 194.0 MB, from 2026-10-24 23:30 UTC"] });
    expect(folderLines({ ...base, folders: [] })).toBeNull();
  });
  it("says what a change saved, or what it added", () => {
    expect(saved(3000000, 1000000)).toBe("1.9 MB");
    expect(saved(0, 28285995)).toBe("+27.0 MB");
    expect(saved(3362115, 3721252)).toBe("+351 KB");
    expect(saved(0, 0)).toBe("—");
  });
  it("asks for a page of the ledger", () => {
    expect(rowsQuery("error", 2, "run_at", "desc")).toBe("show=error&page=2&orderby=run_at&order=desc");
  });
});
