import { describe, expect, it } from "vitest";
import { actionsFor, applyLine, confKind, EMPTY_FILTER, filterQuery, target, viewCount, wordDiff, type ApplyStatus, type ProposalRow } from "../../src/workbench/proposalsApi";

const text = (parts: { text: string; kind: string }[], kind: string) => parts.filter((p) => p.kind === kind).map((p) => p.text.trim()).join("|");

describe("title proposal diff", () => {
  it("marks words taken out of the title and words added", () => {
    const d = wordDiff("LOOPER - Re-Sequencer Plug-In", "Acme Audio LOOPER - Re-Sequencer Plug-In (Download)");
    expect(text(d.after, "ins")).toBe("Acme Audio|(Download)");
    expect(text(d.before, "del")).toBe("");
    const e = wordDiff("Synth Perp Lic (Download)", "Synth (Perpetual License, Download)");
    expect(text(e.before, "del")).toBe("Perp Lic (Download)");
    expect(e.after.map((p) => p.text).join("")).toBe("Synth (Perpetual License, Download)");
  });
  it("shows an unchanged title whole", () => {
    expect(wordDiff("Same Title", "Same Title")).toEqual({ before: [{ text: "Same Title", kind: "same" }], after: [{ text: "Same Title", kind: "same" }] });
  });
});

describe("title proposal rows", () => {
  it("offers what each status allows", () => {
    expect(actionsFor("proposed")).toEqual(["accept", "reject", "edit"]);
    expect(actionsFor("edited")).toEqual(["apply", "edit", "reset"]);
    expect(actionsFor("applied")).toEqual(["revert"]);
    expect(actionsFor("reverted")).toEqual([]);
  });
  it("writes a person's edit rather than the proposal", () => {
    const row = { status: "edited", edited: "Mine", proposed: "Theirs" } as ProposalRow;
    expect(target(row)).toBe("Mine");
    expect(target({ ...row, status: "accepted" })).toBe("Theirs");
  });
  it("counts each view from the stored statuses", () => {
    const by = { proposed: 10, stale: 2, error: 1, accepted: 4, edited: 3, applied: 100 };
    expect(viewCount(by, "open")).toBe(13);
    expect(viewCount(by, "queued")).toBe(7);
    expect(viewCount(by, "any")).toBe(120);
  });
  it("asks for the filter's page, sort and only the filters set", () => {
    expect(filterQuery({ ...EMPTY_FILTER, q: " Synth ", brand: "Acme Audio" })).toBe("status=open&page=1&per_page=25&orderby=confidence&order=desc&q=Synth&brand=Acme+Audio");
  });
  it("grades confidence", () => {
    expect([95, 75, 60, 40].map(confKind)).toEqual(["success", "info", "warning", "error"]);
  });
});

describe("the writer's status line", () => {
  const base: ApplyStatus = { phase: "idle", enabled: true, queue: 0, applied_today: 0, batch_size: 25, next_at: null, now: 1000, run: null };
  it("says what waits and what was written", () => {
    expect(applyLine(base, 1000)).toBe("Nothing waiting to be written.");
    expect(applyLine({ ...base, queue: 1, applied_today: 4 }, 1000)).toBe("1 title waiting to be written. 4 written today.");
    expect(applyLine({ ...base, enabled: false, phase: "disabled" }, 1000)).toBe("Writing titles to products is off.");
  });
  it("counts down to the next batch", () => {
    expect(applyLine({ ...base, phase: "waiting", queue: 30, next_at: 1095 }, 1000)).toBe("Next batch of up to 25 in 1 min 35 s. 30 titles waiting to be written.");
    expect(applyLine({ ...base, phase: "running", queue: 5, run: { total: 30, done: 25, finished_at: null } }, 1000)).toBe("Writing… 25 of 30 written. 5 titles waiting to be written.");
  });
});
