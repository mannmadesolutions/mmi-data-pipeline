/**
 * A stateful stand-in for admin-ajax.php: a small synthetic catalog and the
 * Workbench endpoints the page calls, shaped like the real ones
 * (WorkbenchController.php). Every request is recorded for assertions.
 */
import type { Page, Route } from "@playwright/test";

export interface Product {
  id: number; title: string; sku: string; status: string; stock: string;
  categories: { id: number; name: string }[]; brands: { id: number; name: string }[]; health: string[]; locked: string[];
  regular: string; meta: Record<string, string>;
}

const CATS = [{ id: 10, name: "Software", parent: 0 }, { id: 11, name: "Bundles", parent: 10 }, { id: 12, name: "Plug-ins", parent: 10 }, { id: 13, name: "Hardware", parent: 0 }];
const BRANDS = [{ id: 20, name: "Acme Audio" }, { id: 21, name: "Example Sound" }];

function catalog(): Product[] {
  return Array.from({ length: 120 }, (_, i) => {
    const id = 1001 + i;
    const bundle = i % 3 === 0;
    return {
      id, title: `${bundle ? "Synth Bundle" : "Test Plug-in"} ${i + 1}`, sku: `SKU-${id}`, status: i % 7 === 0 ? "draft" : "publish",
      stock: i % 5 === 0 ? "outofstock" : "instock",
      categories: [bundle ? { id: 11, name: "Bundles" } : { id: 12, name: "Plug-ins" }],
      brands: [BRANDS[i % 2]!], health: i % 4 === 0 ? ["no_image"] : [], locked: [],
      regular: (19 + i).toFixed(2), meta: (i % 3 === 0 ? { _mmi_platforms: "mac,win" } : {}) as Record<string, string>,
    };
  });
}

export interface MockOptions {
  /** Answer the next N Workbench calls with check_ajax_referer's 403 "-1" (a stale nonce). */
  staleNonce?: number;
  /** Refreshing the nonce fails too (the WordPress session itself has ended). */
  loggedOut?: boolean;
  /** Reply "busy" to this many run batches first (another batch holds the job's lock). */
  busyBatches?: number;
  /** Delay every search reply by this many ms. */
  searchDelayMs?: number;
}

export class MockBackend {
  products = catalog();
  calls: { action: string; body: URLSearchParams }[] = [];
  jobs: Record<string, unknown>[] = [];
  rules: Record<string, unknown>[] = [];
  upkeep = {
    enabled: true,
    schedule: { frequency: "hourly", time: "00:40", next: "20 mins from now", options: { disabled: "Disabled", hourly: "Every hour", daily: "Daily" } },
    steps: [
      { phase: "cleanup", label: "Clean up duplicate stock metadata", about: "Removes duplicate stock rows.", kind: "Maintenance", switch: "cleanup_stock_meta", on: true, skipped: false, skip_reason: "" },
      { phase: "feed_sync", label: "Feed sync: Example", about: "Feed presence.", kind: "From data sources", switch: "", on: null, skipped: false, skip_reason: "" },
      { phase: "override_stock", label: "Apply standing rules", about: "Applies the rules.", kind: "Standing rules", switch: "", on: null, skipped: false, skip_reason: "" },
    ],
    last_run: { at: "2026-10-30 14:43:28", ago: "1 hour ago", duration: "3 min", processed: 6553 },
    health: { level: "ok", text: "", detail: "" },
    running: null,
  };
  nonce = "nonce-1";

  constructor(private o: MockOptions = {}) {}

  async attach(page: Page): Promise<void> {
    await page.route("**/wp-admin/admin-ajax.php", (route) => this.handle(route));
  }

  callsTo(action: string) { return this.calls.filter((c) => c.action === action); }

  private reply(route: Route, data: unknown, status = 200) {
    return route.fulfill({ status, contentType: "application/json", body: JSON.stringify({ success: true, data }) });
  }

  private async handle(route: Route) {
    const body = new URLSearchParams(route.request().postData() ?? "");
    const action = body.get("action") ?? "";
    this.calls.push({ action, body });

    if (action === "mmi_refresh_nonces") {
      if (this.o.loggedOut) return route.fulfill({ status: 400, body: "0" });
      this.nonce = `nonce-${Number(this.nonce.split("-")[1]) + 1}`;
      return this.reply(route, { nonces: { mmi_pipeline_import_settings: this.nonce } });
    }
    if (action.startsWith("mmi_workbench_") && (this.o.staleNonce ?? 0) > 0) {
      this.o.staleNonce!--;
      return route.fulfill({ status: 403, body: "-1" });
    }
    if (action.startsWith("mmi_workbench_") && this.o.loggedOut) return route.fulfill({ status: 403, body: "-1" });

    switch (action) {
      case "mmi_get_custom_rule_source_fields":
        return this.reply(route, { fields: body.get("supplier") === "wp_taxonomy"
          ? [{ value: "product_cat", label: "Product categories" }, { value: "product_brand", label: "Brands" }] : ["vendor", "title"] });
      case "mmi_pipeline_get_taxonomy_terms":
        return this.reply(route, { terms: body.get("taxonomy") === "product_brand" ? BRANDS : CATS });
      case "mmi_get_condition_meta_values":
        return this.reply(route, { values: [] });
      case "mmi_workbench_jobs":
        return this.reply(route, { jobs: this.jobs });
      case "mmi_workbench_search": {
        if (this.o.searchDelayMs) await new Promise((r) => setTimeout(r, this.o.searchDelayMs));
        const ids = this.match(body, "filter");
        const sort = body.get("sort") ?? "title";
        const dir = body.get("dir") === "desc" ? -1 : 1;
        const rows = ids.map((id) => this.products.find((p) => p.id === id)!)
          .sort((a, b) => (sort === "id" ? a.id - b.id : a.title.localeCompare(b.title, undefined, { numeric: true })) * dir);
        const perPage = Number(body.get("per_page")) || 50;
        const pages = Math.max(1, Math.ceil(rows.length / perPage));
        const pageNo = Math.min(Math.max(1, Number(body.get("page")) || 1), pages);
        const facets = new Map<string, number>();
        rows.forEach((r) => r.categories.forEach((c) => facets.set(c.name, (facets.get(c.name) ?? 0) + 1)));
        return this.reply(route, {
          total: rows.length, page: pageNo, pages, per_page: perPage,
          rows: rows.slice((pageNo - 1) * perPage, pageNo * perPage).map((p) => this.row(p, body.getAll("meta_cols[]"))),
          facets: [...facets].map(([name, count]) => ({ id: CATS.find((c) => c.name === name)!.id, name, count })),
          checks: { no_image: { chip: "No image", label: "No featured image" } },
        });
      }
      case "mmi_workbench_preview": {
        const ids = this.selection(body);
        return this.reply(route, {
          selected: ids.length, will_change: ids.length, unchanged: 0, summary: "Set Product categories → Bundles (Replace)",
          sample: ids.slice(0, 3).map((id) => ({ id, title: `#${id}`, before: "Plug-ins", after: "Bundles", locked: false })),
          locked: 0, lock_label: "Product categories", lock_mode: "always",
        });
      }
      case "mmi_workbench_start": {
        const ids = this.selection(body);
        const job = { id: `job-${this.jobs.length + 1}`, created_at: "2026-10-16 12:00:00", user: "Tester", action: body.get("wb_action"),
          summary: "Set Product categories → Bundles (Replace)", filter_label: body.get("filter_label"), total: ids.length, cursor: 0,
          changed: 0, unchanged: 0, failed: 0, skipped: 0, skip_locked: false, add_lock: true, status: "running", undo_cursor: 0, undone: 0, conflicts: 0 };
        this.jobs.unshift(job);
        return this.reply(route, { job });
      }
      case "mmi_workbench_run": {
        const job = this.job(body);
        // As Product_Workbench::run_batch(): resume first, then the lock (busy) check.
        if (body.get("resume") === "1" && job.status === "stopped" && (job.cursor as number) < (job.total as number)) job.status = "running";
        if (job.status !== "running") return this.reply(route, { job });
        if ((this.o.busyBatches ?? 0) > 0) { this.o.busyBatches!--; return this.reply(route, { job, busy: true }); }
        const step = Math.min(50, (job.total as number) - (job.cursor as number));
        job.cursor = (job.cursor as number) + step;
        job.changed = (job.changed as number) + step;
        if ((job.cursor as number) >= (job.total as number)) job.status = "done";
        return this.reply(route, { job });
      }
      case "mmi_workbench_stop": {
        const job = this.job(body);
        job.status = "stopped";
        return this.reply(route, { job });
      }
      case "mmi_workbench_undo": {
        const job = this.job(body);
        job.status = "undone";
        job.undone = job.changed;
        return this.reply(route, { job });
      }
      case "mmi_workbench_set_terms": {
        const p = this.products.find((x) => x.id === Number(body.get("product_id")))!;
        const ids = body.getAll("term_ids[]").map(Number);
        const tax = body.get("taxonomy");
        const pool = tax === "product_brand" ? BRANDS : CATS;
        const terms = pool.filter((t) => ids.includes(t.id)).map((t) => ({ id: t.id, name: t.name }));
        if (tax === "product_brand") p.brands = terms; else p.categories = terms;
        p.locked = [...new Set([...p.locked, tax!])];
        return this.reply(route, { row: this.row(p) });
      }
      case "mmi_workbench_set_field": {
        const p = this.products.find((x) => x.id === Number(body.get("product_id")))!;
        const field = body.get("field") ?? "";
        const value = body.get("value") ?? "";
        if (field === "regular" && !/^\d+(\.\d+)?$/.test(value)) {
          return route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ success: false, data: { message: "Enter a price, e.g. 49.99." } }) });
        }
        if (field === "title") p.title = value;
        if (field === "regular") p.regular = value;
        if (field.startsWith("meta:")) p.meta[field.slice(5)] = value;
        return this.reply(route, { row: this.row(p, body.getAll("meta_cols[]")) });
      }
      case "mmi_workbench_save_search":
        return this.reply(route, { id: "wb:1" });
      case "mmi_workbench_rules":
        return this.reply(route, { rules: this.rules.map((r, i) => ({ ...r, position: i + 1 })) });
      case "mmi_workbench_rule_check":
        return this.reply(route, this.check(body));
      case "mmi_workbench_rule_save": {
        const id = body.get("rule_id") || `r${String(this.rules.length + 1).padStart(12, "0")}`;
        const filter = {
          stock: [], health: [], health_mode: "any", q: body.get("filter[q]") ?? "", q_case: 0, q_in: "title_sku", supplier: "all",
          match_logic: "all", statuses: body.getAll("filter[statuses][]"), conditions: [],
        };
        const rule = {
          id, name: body.get("name") || "Unnamed rule", enabled: true, action: body.get("wb_action"), params: {},
          stock: (body.get("wb_action") ?? "").startsWith("force_"), change: body.get("wb_action"), search: `Title or SKU contains "${filter.q}"`,
          filter, efficacy: this.check(body), last_run: null,
        };
        const at = this.rules.findIndex((r) => r.id === id);
        if (at >= 0) this.rules[at] = rule; else this.rules.push(rule);
        return this.reply(route, { rule: { id, name: rule.name }, efficacy: rule.efficacy });
      }
      case "mmi_workbench_rule_enable": {
        const r = this.rules.find((x) => x.id === body.get("rule_id"))!;
        r.enabled = body.get("enabled") === "1";
        return this.reply(route, null);
      }
      case "mmi_workbench_rule_move": {
        const at = this.rules.findIndex((x) => x.id === body.get("rule_id"));
        const to = at + Number(body.get("by"));
        [this.rules[at], this.rules[to]] = [this.rules[to]!, this.rules[at]!];
        return this.reply(route, null);
      }
      case "mmi_workbench_rule_delete":
        this.rules = this.rules.filter((x) => x.id !== body.get("rule_id"));
        return this.reply(route, null);
      case "mmi_workbench_rule_hold":
        return this.reply(route, { matched: 40, changed: 40, released: 0, deferred: 0 });
      case "mmi_workbench_upkeep":
        return this.reply(route, this.upkeep);
      case "mmi_toggle_catalog_maintenance":
        this.upkeep.enabled = body.get("enabled") === "1";
        return this.reply(route, { enabled: this.upkeep.enabled });
      case "mmi_save_catalog_rules":
        this.upkeep.steps[0]!.on = body.get("toggles[cleanup_stock_meta]") === "1";
        return this.reply(route, { rules: {} });
      case "mmi_autosave_schedule":
        this.upkeep.schedule.frequency = body.get("frequency") ?? "";
        this.upkeep.schedule.time = body.get("time") ?? "";
        return this.reply(route, {});
      case "mmi_catalog_start_run":
        return this.reply(route, { run_id: "run-1", phases: this.upkeep.steps, total: this.upkeep.steps.length });
      case "mmi_catalog_run_phase":
        return this.reply(route, { items_processed: 12, duration_ms: 1500 });
      case "mmi_catalog_finish_run":
        return this.reply(route, body.get("aborted") ? { aborted: true } : { total_processed: 6553 });
      default:
        return route.fulfill({ status: 400, body: "0" });
    }
  }

  /** As Rule_Efficacy::predict(): a Force Out of Stock rule fights feed sync; anything else works. */
  private check(body: URLSearchParams) {
    const matched = this.match(body, "filter").length;
    if (body.get("wb_action") === "force_outofstock") {
      return { grade: "inefficient", matched, will_change: matched, findings: [
        { code: "fights_feed_sync", severity: "inefficient", text: "Feed sync puts 12 of these products back in stock on every run.", fix: "Hold them with their own stock override.", count: 12 },
      ] };
    }
    return { grade: "ok", matched, will_change: matched, findings: [] };
  }

  private row(p: Product, metaCols: string[] = []) {
    return {
      ...p, price: p.regular, regular: p.regular, sale: "", stock_qty: p.id % 2 ? "5" : null, type: "simple", modified: "2026-10-30 12:00:00",
      meta: Object.fromEntries(metaCols.map((k) => [k, p.meta[k] ?? null])), supplier: "all", thumb: "", edit_url: "#edit", view_url: "#view",
    };
  }

  private job(body: URLSearchParams) {
    return this.jobs.find((j) => j.id === body.get("job_id"))!;
  }

  /** Product ids a filter (in jQuery.param form, under `prefix`) matches. */
  private match(body: URLSearchParams, prefix: string): number[] {
    const q = (body.get(`${prefix}[q]`) ?? "").toLowerCase();
    const statuses = body.getAll(`${prefix}[statuses][]`);
    const health = body.getAll(`${prefix}[health][]`);
    const cats: string[] = [];
    for (const [k, v] of body) {
      const m = /^.*\[conditions\]\[(\d+)\]\[value\]$/.exec(k);
      if (m && body.get(k.replace("[value]", "[field]")) === "product_cat") cats.push(v);
    }
    return this.products.filter((p) => (!q || p.title.toLowerCase().includes(q) || p.sku.toLowerCase().includes(q))
      && (!statuses.length || statuses.includes(p.status))
      && (!health.length || health.some((h) => p.health.includes(h)))
      && (!cats.length || cats.every((c) => p.categories.some((pc) => pc.name === c)))).map((p) => p.id);
  }

  private selection(body: URLSearchParams): number[] {
    if (body.get("selection[mode]") === "ids") return body.getAll("selection[ids][]").map(Number);
    const exclude = body.getAll("selection[exclude][]").map(Number);
    return this.match(body, "selection[filter]").filter((id) => !exclude.includes(id));
  }
}
