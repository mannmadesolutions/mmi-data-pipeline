import { afterEach, describe, expect, it, vi } from "vitest";
import {
  categoryOptions, draftReady, draftToBody, EMPTY_DRAFT, moved, previewSummary, resetNonce, rest, ruleToDraft, unstableNote, type OwnRule,
} from "../../src/workbench/titleRulesApi";

const rule = (o: Partial<OwnRule>): OwnRule => ({
  id: "a", label: "", kind: "replace", from: "Plug-In", to: "Plugin", brand: "", at: "", by: "", example: 0,
  case: "", categories: [], suppliers: [], scope: "", position: 1, ...o,
});

describe("title rule drafts", () => {
  it("needs the words, and a replacement where the kind takes one", () => {
    expect(draftReady(EMPTY_DRAFT)).toBe(false);
    expect(draftReady({ ...EMPTY_DRAFT, from: "Plug-In" })).toBe(false);
    expect(draftReady({ ...EMPTY_DRAFT, from: "Plug-In", to: "Plugin" })).toBe(true);
    expect(draftReady({ ...EMPTY_DRAFT, kind: "remove", from: "Promo" })).toBe(true);
    expect(draftReady({ ...EMPTY_DRAFT, kind: "qualifier", from: "Upgrade" })).toBe(true);
    expect(draftReady({ ...EMPTY_DRAFT, kind: "insert", from: "Bundle" })).toBe(false);
  });

  it("sends what the routes read: case, ids as numbers, no replacement for remove", () => {
    expect(draftToBody({ ...EMPTY_DRAFT, kind: "remove", from: "Promo", to: "left over", anyCase: true, categories: ["12"], suppliers: ["xchange"] }))
      .toEqual({ kind: "remove", from: "Promo", to: "", brand: "", case: "any", categories: [12], suppliers: ["xchange"] });
  });

  it("opens a saved rule as it was written", () => {
    expect(ruleToDraft(rule({ case: "any", categories: [3], suppliers: ["s"] }))).toEqual({
      kind: "replace", from: "Plug-In", to: "Plugin", brand: "", anyCase: true, categories: ["3"], suppliers: ["s"],
    });
    // A move-into-parentheses rule kept as found shows an empty "write them as".
    expect(ruleToDraft(rule({ kind: "qualifier", from: "Upgrade", to: "Upgrade" })).to).toBe("");
    expect(ruleToDraft(rule({ kind: "qualifier", from: "UPG", to: "Upgrade" })).to).toBe("Upgrade");
  });
});

describe("title rule display", () => {
  it("says what saving would do", () => {
    expect(previewSummary({ label: "", count: 1, scanned: 40, unstable: 0, samples: [] })).toBe("Would change 1 product title (40 checked).");
    expect(previewSummary({ label: "", count: 0, scanned: 7, unstable: 0, samples: [] })).toBe("Would change no product titles (7 checked).");
    expect(unstableNote({ label: "", count: 0, scanned: 7, unstable: 0, samples: [] })).toBe("");
    expect(unstableNote({ label: "", count: 0, scanned: 7, unstable: 3, samples: [] })).toContain("Skipped on 3 titles");
  });

  it("indents child categories", () => {
    expect(categoryOptions([{ id: 1, name: "Software", path: "Software", depth: 0 }, { id: 2, name: "Plug-ins", path: "Software › Plug-ins", depth: 1 }]))
      .toEqual({ 1: "Software", 2: "— Plug-ins" });
  });

  it("moves a rule one place, within the list", () => {
    const rules = [rule({ id: "c", position: 3 }), rule({ id: "a", position: 1 }), rule({ id: "b", position: 2 })];
    expect(moved(rules, "b", -1)).toEqual(["b", "a", "c"]);
    expect(moved(rules, "b", 1)).toEqual(["a", "c", "b"]);
    expect(moved(rules, "a", -1)).toBeNull();
    expect(moved(rules, "c", 1)).toBeNull();
  });
});

describe("title rule REST client", () => {
  afterEach(() => { vi.unstubAllGlobals(); resetNonce(); });
  const cfg = { rest: "/wp-json/mmpt/v1/", nonceUrl: "/wp-admin/admin-ajax.php?action=rest-nonce" };

  it("fetches a nonce once, then sends it", async () => {
    const f = vi.fn(async (url: string, _init?: RequestInit) => (url.includes("rest-nonce")
      ? new Response("n1") : new Response(JSON.stringify({ rules: [] }), { status: 200 })));
    vi.stubGlobal("fetch", f);
    await rest(cfg, "GET", "custom-rules");
    await rest(cfg, "GET", "custom-rules");
    expect(f.mock.calls.filter(([u]) => String(u).includes("rest-nonce"))).toHaveLength(1);
    expect(f.mock.calls[1]![1]!.headers).toEqual({ "X-WP-Nonce": "n1" });
  });

  it("refreshes an expired nonce and resends once", async () => {
    let issued = 0;
    const f = vi.fn(async (url: string, init?: RequestInit) => {
      if (url.includes("rest-nonce")) return new Response(`n${++issued}`);
      const n = (init?.headers as Record<string, string>)["X-WP-Nonce"];
      return n === "n2" ? new Response(JSON.stringify({ ok: 1 })) : new Response(JSON.stringify({ code: "rest_cookie_invalid_nonce", message: "x" }), { status: 403 });
    });
    vi.stubGlobal("fetch", f);
    await expect(rest(cfg, "GET", "custom-rules")).resolves.toEqual({ ok: 1 });
  });

  it("passes the server's own message on", async () => {
    vi.stubGlobal("fetch", vi.fn(async (url: string) => (url.includes("rest-nonce")
      ? new Response("n1") : new Response(JSON.stringify({ code: "mmpt_rule_pattern", message: "That pattern is not valid." }), { status: 400 }))));
    await expect(rest(cfg, "POST", "custom-rules/preview", {})).rejects.toMatchObject({ message: "That pattern is not valid.", kind: "server" });
  });

  it("says the session ended when no nonce can be had", async () => {
    vi.stubGlobal("fetch", vi.fn(async () => new Response("0")));
    await expect(rest(cfg, "GET", "custom-rules")).rejects.toMatchObject({ kind: "auth" });
  });
});
