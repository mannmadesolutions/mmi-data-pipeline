import jQuery from "jquery";
import { describe, expect, it } from "vitest";
import { readReply, toFormBody, type FormValue } from "../../src/workbench/api";

/** The PHP handlers read jQuery-encoded $_POST; the new client must send the same bytes. */
describe("toFormBody matches jQuery.param", () => {
  const cases: Record<string, Record<string, FormValue>> = {
    "a search": {
      action: "mmi_workbench_search",
      filter: {
        stock: ["instock", "onbackorder"], health: [], health_mode: "any", q: "bundle & more", q_case: 1, q_in: "title_sku",
        supplier: "all", match_logic: "all", statuses: ["draft", "publish"],
        conditions: [
          { source: "wp_taxonomy", field: "product_cat", operator: "equals", value: "Bundles", case_sensitive: 1 },
          { source: "xchange", field: "vendor", operator: "is_empty", value: "", or: true },
        ],
      },
      page: 2, per_page: 50, sort: "title", dir: "asc", fresh: 1,
    },
    "a filter selection with nulls and booleans": {
      selection: { mode: "filter", exclude: [3, 9], filter: { q: "x", conditions: [] } },
      params: { taxonomy: "product_cat", term_id: "12", mode: "replace", value: null as unknown as string },
      flag: false, other: true,
    },
    "an ids selection": { selection: { mode: "ids", ids: [101, 202, 303] } },
  };
  for (const [name, data] of Object.entries(cases)) {
    it(name, () => {
      // Same key/value pairs in the same order (the two escape spaces and !'()~ differently; PHP decodes both alike).
      expect([...toFormBody(data)]).toEqual([...new URLSearchParams(jQuery.param(data))]);
    });
  }
});

describe("readReply", () => {
  const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { "content-type": "application/json" } });

  it("returns data on success", async () => {
    await expect(readReply(json({ success: true, data: { total: 3 } }))).resolves.toEqual({ total: 3 });
  });
  it("passes the server's own message through", async () => {
    await expect(readReply(json({ success: false, data: { message: "Unknown job." } }))).rejects.toMatchObject({ kind: "server", message: "Unknown job." });
  });
  it("treats check_ajax_referer's 403 -1 as an expired login, not a network error", async () => {
    await expect(readReply(new Response("-1", { status: 403 }))).rejects.toMatchObject({ kind: "auth" });
  });
  it("treats a logged-out 400 0 as an expired login", async () => {
    await expect(readReply(new Response("0", { status: 400 }))).rejects.toMatchObject({ kind: "auth" });
  });
  it("keeps a 403 permission message", async () => {
    await expect(readReply(json({ success: false, data: { message: "Insufficient permissions" } }, 403)))
      .rejects.toMatchObject({ kind: "auth", message: "Insufficient permissions" });
  });
  it("reports a PHP fatal page as a network error", async () => {
    await expect(readReply(new Response("<html>critical error</html>", { status: 500 }))).rejects.toMatchObject({ kind: "network" });
    await expect(readReply(new Response("<b>Warning</b>{", { status: 200 }))).rejects.toMatchObject({ kind: "network" });
  });
});
