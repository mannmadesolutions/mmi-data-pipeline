/**
 * A stateful stand-in for admin-ajax.php: a small synthetic catalog and the
 * Workbench endpoints the page calls, shaped like the real ones
 * (WorkbenchController.php). Every request is recorded for assertions.
 */
import type { Page, Route } from "@playwright/test";

export interface Product {
  id: number; title: string; sku: string; status: string; stock: string;
  categories: { id: number; name: string }[]; brands: { id: number; name: string }[]; health: string[]; locked: string[];
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
          rows: rows.slice((pageNo - 1) * perPage, pageNo * perPage).map((p) => this.row(p)),
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
      case "mmi_workbench_save_search":
        return this.reply(route, { id: "wb:1" });
      default:
        return route.fulfill({ status: 400, body: "0" });
    }
  }

  private row(p: Product) {
    return { ...p, price: "9.99", supplier: "all", thumb: "", edit_url: "#edit", view_url: "#view" };
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
