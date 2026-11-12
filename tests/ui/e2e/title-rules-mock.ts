/**
 * A stateful stand-in for the title rule REST routes (mmpt/v1) and WordPress's
 * rest-nonce action, shaped like the real ones (CustomRules, Rest). Requests
 * are recorded for assertions.
 */
import type { Page, Route } from "@playwright/test";
import { MakerPagesMock } from "./maker-pages-mock";

interface Rule { id: string; kind: string; from: string; to: string; brand: string; by: string; at: string; example: number; case?: string; categories?: number[]; suppliers?: string[] }

const TITLES = ["Acme Audio Great Plug-In (Download)", "Acme Audio Other Plug-In (Download)", "Example Sound Synth Upgrade (Download)", "Example Sound Pad Vol. 2 (Download)"];
const CATS = [{ id: 10, name: "Software", path: "Software", depth: 0 }, { id: 12, name: "Plug-ins", path: "Software › Plug-ins", depth: 1 }];

function label(r: Rule): string {
  if (r.kind === "remove") return `Remove "${r.from}"`;
  if (r.kind === "insert") return `After "${r.from}", add "${r.to.slice(r.from.length).trim()}"`;
  if (r.kind === "qualifier") return r.to !== r.from ? `Move "${r.from}" into the parentheses as "${r.to}"` : `Move "${r.from}" into the parentheses`;
  if (r.kind === "pattern") return `Pattern ${r.from} → "${r.to}"`;
  return `"${r.from}" → "${r.to}"`;
}

export class TitleRulesMock {
  makerPages = new MakerPagesMock();
  rules: Rule[] = [
    { id: "r1", kind: "replace", from: "Sub", to: "Subscription", brand: "Acme Audio", by: "Owner", at: "2026-10-01 10:00:00", example: 1001 },
    { id: "r2", kind: "remove", from: "Promo", to: "", brand: "", by: "Owner", at: "2026-10-02 10:00:00", example: 1002, suppliers: ["xchange"] },
  ];
  calls: { method: string; path: string; body: Record<string, unknown> }[] = [];
  nonceCalls = 0;
  builtin: Record<string, { label: string; description: string; enabled: boolean; always_on: boolean; approved: boolean; count: number }> = {
    tidy: { label: "Tidy punctuation", description: "Collapses double spaces.", enabled: true, always_on: true, approved: true, count: 3 },
    deshout: { label: "Fix ALL CAPS", description: "Title-cases a title written in capitals.", enabled: true, always_on: false, approved: false, count: 2 },
    custom: { label: "Your rules", description: "Word changes you save.", enabled: true, always_on: false, approved: true, count: 1 },
  };
  words: Record<string, string[]> = { keep_caps: ["MIDI", "HDMI"], small_words: ["the", "box"], minor_words: ["of", "and"], lowercase_small: ["of", "x"] };
  proposals = Array.from({ length: 30 }, (_, i) => {
    const id = 2001 + i;
    const status = i < 22 ? "proposed" : i < 25 ? "accepted" : i < 28 ? "applied" : "rejected";
    const brand = i % 2 ? "Example Sound" : "Acme Audio";
    const current = `${i % 3 ? "SYNTH" : "Synth"} Model ${i + 1} Perp Lic`;
    return {
      id, status, current, proposed: `${brand} Synth Model ${i + 1} (Perpetual License, Download)`, edited: null as string | null,
      original: status === "applied" ? current : null, confidence: 95 - i, rules: i % 3 ? ["deshout", "tidy"] : ["tidy"], flags: i === 3 ? ["over_length"] : i === 13 ? ["bundle_not_in_category"] : i === 11 ? ["no_brand"] : [],
      dupes: 0, brand, stock: "instock", post_status: "publish", sku: `SKU-${id}`, price: "$49.00",
      why: { tidy: { from: "", to: "(Download)", confidence: 95, source: "punctuation" }, deshout: { from: "SYNTH", to: "Synth", confidence: 85, source: "title was in capitals" } },
      renamed: null, error: "", edit_url: `post.php?post=${id}&action=edit`, view_url: "#",
      cats: [{ id: 12, name: "Plug-ins" }], brands: i === 11 ? [] : [{ id: i % 2 ? 21 : 20, name: brand }], cat_locked: i === 1, brand_locked: false, missing: i === 6 ? ["sku"] : i === 11 ? ["brand"] : [] as string[],
    };
  });
  /** Needs a decision (GET waiting): off, the list is empty and the Workbench opens on Find and change. */
  waitingOn = false;
  applyState = { phase: "idle", enabled: true, queue: 3, applied_today: 0, batch_size: 25, next_at: null as number | null, now: 1000, run: null as unknown };
  /** Store::apply_settings(), and Enrichment's settings and sources (Writing settings). */
  applySettings = { enabled: false, auto: false, batch_size: 25, min_confidence: 85 };
  enrichSettings = { enabled: false };
  /** Exclusions: brand 30 is the store's own (fixed). */
  leftBrands = [30, 31];
  leftProducts = [2005];
  /** The picture runner: its ledger and whether products wait. */
  pxWaiting = 0;
  pxRows = Array.from({ length: 60 }, (_, i) => ({
    product_id: String(5001 + i), title: `Acme Pad ${i + 1}`, status: i === 59 ? "error" : "applied", mode: i < 50 ? "vendor" : "in_place",
    reason: i < 50 ? "" : i === 59 ? "vendor_failed" : "page_no_pictures", reason_label: i < 50 ? "" : i === 59 ? "Vendor pictures could not be downloaded" : "The vendor page has no pictures",
    source_url: "https://maker.example/p", pics_before: "1", pics_after: "3", bytes_before: "2048000", bytes_after: "512000", note: "", run_at: `2026-11-10 0${i % 10}:00:00`, thumb: "",
  }));
  enrichRules: Record<string, { label: string; approved: boolean; retired?: boolean }> = {
    "content:vendor_page": { label: "Description from the maker's page", approved: true },
    "content:web_assets": { label: "Description from the feed", approved: false },
    "image:web_assets": { label: "Old feed pictures", approved: false, retired: true },
  };
  enrich = [
    ...Array.from({ length: 8 }, (_, i) => ({
      id: 3001 + i, kind: "content", status: i < 6 ? "proposed" : i === 6 ? "accepted" : "applied", source: i % 2 ? "web_assets" : "vendor_page",
      source_url: i % 2 ? "" : "https://maker.example/p", title: `Acme Synth ${i + 1}`, current: i === 0 ? "" : "Old short text.",
      proposed: `A full description of Acme Synth ${i + 1}.`, chars: `A full description of Acme Synth ${i + 1}.`.length, images: [] as string[], own: [] as { token: string; url: string }[], chosen: [] as string[],
      waiting: false, low_res: [] as string[], edit_url: `post.php?post=${3001 + i}&action=edit`, view_url: "#",
    })),
    ...Array.from({ length: 3 }, (_, i) => ({
      id: 4001 + i, kind: "image", status: "proposed", source: "vendor_page", source_url: "https://maker.example/p", title: `Acme Pad ${i + 1}`,
      current: "", proposed: "", chars: 0, images: ["/wp-includes/images/w-logo-blue.png", "/wp-includes/images/wpicons.png"],
      own: i === 0 ? [{ token: "att:77", url: "/wp-includes/images/arrow-pointer-blue.png" }] : [], chosen: [] as string[],
      waiting: false, low_res: i === 1 ? ["/wp-includes/images/wpicons.png"] : [], edit_url: "#", view_url: "#",
      site: { host: "maker.example", map: "draft", pictures: false },
    })),
  ];
  constructor(private o: { staleOnce?: boolean; basic?: boolean } = {}) {}

  /**
   * The server's follow-up to a maker-page change (PageEvents): a cleared page drops the pictures proposed from it at
   * once; a page set and read rebuilds them from the new page, here a moment later, as the background reader does.
   */
  private afterPageChange(path: string, body: Record<string, unknown>): void {
    const one = path.match(/^urls\/(\d+)\/(set|clear|none)$/);
    if (one && one[2] !== "set") {
      this.enrich = this.enrich.filter((r) => !(r.id === Number(one[1]) && r.kind === "image" && r.source === "vendor_page"));
      return;
    }
    const id = one ? Number(one[1]) : path === "pages/queue" ? Number((body.ids as number[] | undefined)?.[0] ?? 0) : 0;
    const row = this.enrich.find((r) => r.id === id && r.kind === "image");
    if (row) setTimeout(() => { row.images = ["/wp-includes/images/media/default.svg", ...row.images.slice(0, 1)]; if (one) row.source_url = String(body.url); }, 1500);
  }

  private openOf(s: string) { return ["proposed", "stale", "error"].includes(s); }

  /** Issues::for_row(): live gaps, then the flags (a stored no_brand is dropped: the live check says it). */
  issuesOf(r: { missing: string[]; flags: string[] }) {
    const miss: Record<string, [string, string, string, string]> = {
      brand: ["No brand", "The brand can’t be put first, the brand page doesn’t list it and Reverb won’t list it.", "brand", "Set the brand…"],
      category: ["No category", "Only the default category.", "cats", "Set the category…"],
      sku: ["No SKU", "Without a SKU the supplier’s data can’t be matched to it.", "sku", "Add a SKU…"],
    };
    const flags: Record<string, { label: string; help: string; fixes: { kind: string; label: string; action?: string; primary?: boolean }[] }> = {
      over_length: { label: "Too long", help: "Longer than search results show.", fixes: [{ kind: "title", label: "Edit the title" }] },
      bundle_not_in_category: { label: "Not in Software Bundles", help: "XChange files it as a bundle, but our category doesn’t say so.", fixes: [{ kind: "action", action: "add_cat:11", label: "Add to Software Bundles", primary: true }, { kind: "cats", label: "Change the category…" }, { kind: "title", label: "Not a bundle: edit the title" }] },
    };
    return [
      ...r.missing.map((k) => ({ key: k, label: miss[k]![0], help: miss[k]![1], tone: "error", fixes: [{ kind: miss[k]![2], label: miss[k]![3], primary: true }] })),
      ...r.flags.filter((f) => f !== "no_brand").map((f) => ({ key: f, tone: "warning", ...(flags[f] ?? { label: f, help: "", fixes: [{ kind: "title", label: "Edit the title" }] }) })),
    ];
  }
  shaped<T extends { missing: string[]; flags: string[] }>(r: T) { return { ...r, issues: this.issuesOf(r) }; }

  /** What waits on a proposal's product, as Waiting::badges() gives it: gaps, then an open title. */
  waitingOf(r: { status: string; missing: string[] }) {
    const out: { key: string; label: string; tone: string }[] = [];
    if (r.missing.includes("brand")) out.push({ key: "no_brand", label: "No brand", tone: "error" });
    if (r.missing.includes("sku")) out.push({ key: "no_sku", label: "No SKU", tone: "warning" });
    if (["proposed", "stale", "error"].includes(r.status)) out.push({ key: "title", label: "Title", tone: "success" });
    return out;
  }

  /** GET waiting: the proposals something waits on, errors (no brand) first, then by ID; chips, search and the ID heading. */
  waiting(u: URL) {
    const all = this.waitingOn ? this.proposals.map((r) => ({ r, w: this.waitingOf(r) })).filter((x) => x.w.length) : [];
    const q = (u.searchParams.get("q") ?? "").toLowerCase();
    const found = all.filter((x) => !q || String(x.r.id) === q || x.r.current.toLowerCase().includes(q));
    const has = (x: { w: { key: string }[] }, keys: string[]) => x.w.some((b) => keys.includes(b.key));
    const counts = { all: found.length, titles: found.filter((x) => has(x, ["title"])).length, descriptions: 0, pictures: 0, pages: 0, data: found.filter((x) => has(x, ["no_brand", "no_sku"])).length, picture_errors: 0 };
    const kind = u.searchParams.get("kind") ?? "";
    let rows = kind === "titles" ? found.filter((x) => has(x, ["title"])) : kind === "data" ? found.filter((x) => has(x, ["no_brand", "no_sku"])) : kind ? [] : found;
    const err = (x: { w: { key: string }[] }) => (has(x, ["no_brand"]) ? 0 : 1);
    rows = [...rows].sort((a, b) => err(a) - err(b) || a.r.id - b.r.id);
    if (u.searchParams.get("orderby") === "id") rows.sort((a, b) => (a.r.id - b.r.id) * (u.searchParams.get("order") === "asc" ? 1 : -1));
    const page = Number(u.searchParams.get("page") ?? 1);
    return {
      rows: rows.slice((page - 1) * 25, page * 25).map((x) => ({ id: x.r.id, title: x.r.current, status: "publish", sku: x.r.sku, cats: x.r.cats, brands: x.r.brands, waiting: x.w })),
      total: rows.length, pages: Math.max(1, Math.ceil(rows.length / 25)), page, counts,
    };
  }
  /** Other products sending Xchange's "Freqport": a brand fix that maps it gives them the brand too (Issues::fix_terms()). */
  freqportSiblings: number[] = [];
  /** Brands created through the fix panel. */
  createdBrands: { id: number; name: string }[] = [];

  private queue(u: URL) {
    const status = u.searchParams.get("status") ?? "open";
    const q = (u.searchParams.get("q") ?? "").toLowerCase();
    const brand = u.searchParams.get("brand") ?? "";
    const rule = u.searchParams.get("rule") ?? "";
    let rows = this.proposals.filter((r) => (status === "open" ? this.openOf(r.status) : status === "queued" ? ["accepted", "edited"].includes(r.status) : status === "auto" ? r.status === "proposed" && r.confidence >= this.applySettings.min_confidence && !r.flags.length && r.rules.every((x) => this.builtin[x]?.approved) : status === "any" ? true : r.status === status));
    if (q) rows = rows.filter((r) => r.current.toLowerCase().includes(q) || r.proposed.toLowerCase().includes(q) || String(r.id) === q);
    if (brand) rows = rows.filter((r) => r.brand === brand);
    if (rule) rows = rows.filter((r) => r.rules.includes(rule));
    const key = u.searchParams.get("orderby") ?? "confidence";
    const dir = u.searchParams.get("order") === "asc" ? 1 : -1;
    rows = [...rows].sort((a, b) => (key === "id" ? a.id - b.id : key === "brand" ? a.brand.localeCompare(b.brand) : a.confidence - b.confidence) * dir || a.id - b.id);
    const per = Number(u.searchParams.get("per_page")) || 25;
    const page = Number(u.searchParams.get("page")) || 1;
    return { rows: rows.slice((page - 1) * per, page * per).map((r) => this.shaped(r)), total: rows.length, pages: Math.max(1, Math.ceil(rows.length / per)) };
  }

  summary() {
    const by: Record<string, number> = {};
    for (const r of this.proposals) by[r.status] = (by[r.status] ?? 0) + 1;
    return {
      rules: this.builtin, by_status: by, brands: ["Acme Audio", "Example Sound"], auto: this.queue(new URL("http://x/?status=auto&per_page=999")).total,
      apply: this.applySettings, today: "2026-11-10",
      gen_state: { status: "complete", completed_at: "2026-11-10 09:09:24", stats: { products: 7298, proposals: 30, new: 0, changed: 2, reopened: 0, kept_decisions: 1 } },
      app_state: { status: "complete", completed_at: "2026-11-10 00:15:07", day: "2026-11-10", applied_today: 3, applied_total: 7142, failed_total: 0, last_error: null },
      desc: { status: { candidate: 12, needs_ai: 2 }, fetch: { http_403: 4 } }, fetch: { status: "complete", completed_at: "2026-11-10 03:30:00", totals: { read: 40 } },
      urls: { auto: 1, review: 2, approved: 3, last_run: "2026-11-10 03:30" }, picker: { last_run: "", totals: { picked: 0, none: 0 } },
    };
  }

  private brandNames: Record<number, [string, number]> = { 30: ["Our Store", 12], 31: ["Prism Example", 38], 20: ["Acme Audio", 15], 21: ["Example Sound", 15] };
  leftCount() { return this.leftBrands.reduce((n, b) => n + this.brandNames[b]![1], 0) + this.leftProducts.length; }
  leftSummary() {
    return {
      brands: this.leftBrands.map((id) => ({ id, name: this.brandNames[id]![0], count: this.brandNames[id]![1], fixed: id === 30 })),
      products: this.leftProducts.map((id) => ({ id, title: this.proposals.find((p) => p.id === id)!.proposed, sku: `SKU-${id}` })),
      excluded: this.leftCount(), not_sold: { count: 1484 },
      all_brands: Object.entries(this.brandNames).map(([id, [name]]) => ({ id: Number(id), name })),
    };
  }

  pxSummary() {
    const g = (status: string, mode: string, reason: string, reason_label: string, n: number) => ({ status, mode, reason, reason_label, n, pics_before: n, pics_after: n * 3, bytes_before: n * 2048000, bytes_after: n * 512000 });
    return {
      groups: [g("applied", "in_place", "page_no_pictures", "The vendor page has no pictures", 9), g("error", "vendor", "vendor_failed", "Vendor pictures could not be downloaded", 1), g("applied", "vendor", "", "", 50)],
      running: this.pxWaiting > 0, done_today: 12, cap: 300, waiting: this.pxWaiting, activity: { manual: false, busy: false, reasons: [] },
      pass: { started_at: "2026-11-10 03:03:20", done_at: this.pxWaiting ? "" : "2026-11-10 03:20:00" }, state: { note: "", last_run: { vendor: 3, in_place: 1, skipped: 0, error: 0 } },
      release: { kept: { n: 42, bytes: 81314529 }, quarantined: { n: 2377, bytes: 1531109953 } }, folders: [],
    };
  }

  enrichSummary() {
    const counts: Record<string, Record<string, number>> = {};
    for (const r of this.enrich) { const k = `${r.kind}:${r.source}`; counts[k] = counts[k] ?? {}; counts[k]![r.status] = (counts[k]![r.status] ?? 0) + 1; }
    return { counts, rules: this.enrichRules, settings: this.enrichSettings, state: { day: "2026-11-10", today: { content: 2, image: 0 }, last_batch_at: "2026-11-10 05:00:00" }, today: "2026-11-10" };
  }

  async attach(page: Page): Promise<void> {
    await page.route("**/wp-admin/admin-ajax.php?action=rest-nonce", (route) => { this.nonceCalls++; return route.fulfill({ body: `rn-${this.nonceCalls}` }); });
    await page.route("**/wp-json/mmpt/v1/**", (route) => this.handle(route));
  }

  callsTo(path: string) { return this.calls.filter((c) => c.path === path); }

  private list() {
    return [...this.rules].reverse().map((r) => ({
      id: r.id, label: label(r), kind: r.kind, from: r.from, to: r.kind === "insert" ? r.to.slice(r.from.length).trim() : r.to, brand: r.brand,
      at: r.at, by: r.by, example: r.example, case: r.case ?? "", categories: r.categories ?? [], suppliers: r.suppliers ?? [],
      scope: [r.categories?.length ? "in " + r.categories.map((c) => CATS.find((x) => x.id === c)?.name).join(", ") : "", r.suppliers?.length ? "from " + r.suppliers.join(", ") : "", r.case === "any" ? "any capitals" : ""].filter(Boolean).join("; "),
      position: this.rules.indexOf(r) + 1,
    }));
  }

  private json(route: Route, data: unknown, status = 200) {
    return route.fulfill({ status, contentType: "application/json", body: JSON.stringify(data) });
  }

  private normalize(b: Record<string, unknown>): Rule | { code: string; message: string } {
    const kind = String(b.kind ?? ""); const from = String(b.from ?? "").trim(); let to = String(b.to ?? "").trim();
    if (kind === "pattern" && (from.split("(").length !== from.split(")").length)) {
      return { code: "mmpt_rule_pattern", message: "That pattern is not a valid regular expression (Compilation failed: missing closing parenthesis at offset 9)." };
    }
    if (!from) return { code: "mmpt_rule_from", message: "Type the words the rule looks for (up to 100 characters)." };
    if (kind === "insert") to = `${from} ${to}`;
    if (kind === "qualifier" && !to) to = from;
    return {
      id: "", kind, from, to, brand: String(b.brand ?? ""), by: "Tester", at: "2026-11-09 12:00:00", example: 0,
      ...(b.case === "any" ? { case: "any" } : {}), ...((b.categories as number[] | undefined)?.length ? { categories: b.categories as number[] } : {}),
      ...((b.suppliers as string[] | undefined)?.length ? { suppliers: b.suppliers as string[] } : {}),
    };
  }

  private apply(title: string, r: Rule): string {
    if (r.kind === "pattern") return title.replace(new RegExp(r.from, r.case === "any" ? "gi" : "g"), r.to);
    if (r.kind === "qualifier") return title.includes(r.from) ? title.replace(` ${r.from}`, "").replace("(", `(${r.to}, `) : title;
    return title.includes(r.from) ? title.replace(r.from, r.kind === "remove" ? "" : r.to).replace(/\s{2,}/g, " ") : title;
  }

  private async handle(route: Route) {
    const req = route.request();
    const path = new URL(req.url()).pathname.replace(/^.*\/wp-json\/mmpt\/v1\//, "");
    const body = (req.postData() ? JSON.parse(req.postData()!) : {}) as Record<string, unknown>;
    this.calls.push({ method: req.method(), path, body });
    if (this.o.staleOnce && req.headers()["x-wp-nonce"] === "rn-1") {
      return this.json(route, { code: "rest_cookie_invalid_nonce", message: "Cookie check failed" }, 403);
    }
    const e = path.match(/^enrich\/(\d+)\/(content|image)\/(apply|revert|choose)$/);
    if (e) {
      const row = this.enrich.find((r) => r.id === Number(e[1]) && r.kind === e[2])!;
      if (e[3] === "choose") { row.chosen = (body.images as string[]).filter((t) => [...row.images, ...row.own.map((o) => o.token)].includes(t)); return this.json(route, { chosen: row.chosen }); }
      if (e[3] === "apply") { if (row.kind === "image" && !row.chosen.length) return this.json(route, { result: "error", message: "Pick the pictures for this product first." }); row.status = "applied"; return this.json(route, { result: "applied", message: "" }); }
      row.status = "reverted"; return this.json(route, { result: "reverted", message: "" });
    }
    const pw = path.match(/^products\/(\d+)\/workbench$/);
    if (pw) {
      const row = this.proposals.find((r) => r.id === Number(pw[1]));
      if (!row) return this.json(route, { code: "mmpt_not_found", message: "There is no such product." }, 404);
      return this.json(route, {
        id: row.id, title: row.current, status: "publish", stock: "instock", sku: row.sku, edit_url: row.edit_url, view_url: row.view_url,
        waiting: this.waitingOf(row), issues: this.issuesOf(row), proposal: this.shaped(row), cats: row.cats, brands: row.brands,
        locks: { cats: row.cat_locked, brands: row.brand_locked },
        feed: { brands: row.brands.length ? [] : [{ supplier: "xchange", label: "Xchange", field: "brand", value: "Freqport", mapped: null, others: 2 }], cats: [] },
      });
    }
    const fx = path.match(/^products\/(\d+)\/(fix-context|fix)$/);
    if (fx) {
      const id = Number(fx[1]);
      const row = this.proposals.find((r) => r.id === id);
      if (fx[2] === "fix-context") {
        const kind = new URL(req.url()).searchParams.get("kind");
        const base = { id, title: row?.current ?? "", can_create: true };
        if (kind === "brand") return this.json(route, { ...base, feed: [{ supplier: "xchange", label: "Xchange", field: "brand", value: "Freqport", mapped: null, others: 2 }], similar: [], suggested: "Freqport" });
        if (kind === "cats") return this.json(route, { ...base, feed: [{ supplier: "xchange", label: "Xchange", field: "master_category+sub_category", value: "Software / Effects", mapped: null, others: 4 }] });
        return this.json(route, { ...base, blocks: [{ index: 0, type: "xchange_descript", label: "The XChange description", url: "", text: "True analog tube saturation plugin with optional hardware" }] });
      }
      const kind = String(body.kind);
      if (kind === "brand" && (body.create as { name?: string } | undefined)?.name === "Acme Audio") {
        return this.json(route, { code: "mmpt_exists", message: "A brand named “Acme Audio” already exists. Choose it instead." }, 409);
      }
      let message = "Done.";
      if (kind === "brand") {
        const made = body.create as { name: string } | undefined;
        const b = made ? { id: 90 + this.createdBrands.length, name: made.name } : { id: (body.ids as number[])[0]!, name: (body.ids as number[])[0] === 20 ? "Acme Audio" : "Example Sound" };
        if (made) this.createdBrands.push(b);
        if (row) { row.brands = [b]; row.brand_locked = true; row.missing = row.missing.filter((k) => k !== "brand"); row.flags = row.flags.filter((f) => f !== "no_brand"); }
        message = `${made ? "Created and set" : "Set"} brand ${b.name}.${(body.remember as string[]).length ? " Mapped Xchange “Freqport” to it; 2 other products got it too." : ""}`;
        if ((body.remember as string[]).length && this.freqportSiblings.length) {
          for (const s of this.proposals.filter((r) => this.freqportSiblings.includes(r.id))) {
            s.brands = [b]; s.missing = s.missing.filter((k) => k !== "brand"); s.flags = s.flags.filter((f) => f !== "no_brand");
          }
          const others = { updated: this.freqportSiblings.length, locked: 0, queued: false, ids: this.freqportSiblings };
          return this.json(route, { id, message, row: row ? this.shaped(row) : null, removed: false, missing: row?.missing ?? [], others });
        }
      }
      if (row && kind === "action") { row.cats = [...row.cats, { id: 11, name: "Bundles" }]; row.flags = row.flags.filter((f) => f !== "bundle_not_in_category"); message = "Added to Software Bundles."; }
      if (row && kind === "sku") { row.sku = String(body.sku); row.missing = row.missing.filter((k) => k !== "sku"); message = `SKU set to ${String(body.sku)}.`; }
      if (kind === "image") message = "Featured picture set.";
      return this.json(route, { id, message, row: row ? this.shaped(row) : null, removed: false, missing: row?.missing ?? [], others: { updated: 2, locked: 0, queued: false } });
    }
    const m = path.match(/^proposals\/(\d+)\/(suggest-rules|edit|apply|revert|categories|brands)$/);
    if (m) {
      const row = this.proposals.find((r) => r.id === Number(m[1]))!;
      if (m[2] === "categories" || m[2] === "brands") {
        const ids = body.ids as number[];
        const names: Record<number, string> = { 10: "Software", 12: "Plug-ins", 20: "Acme Audio", 21: "Example Sound" };
        if (m[2] === "categories") { row.cats = ids.map((i) => ({ id: i, name: names[i] ?? String(i) })); row.cat_locked = true; }
        else { row.brands = ids.map((i) => ({ id: i, name: names[i] ?? String(i) })); row.brand_locked = true; }
        return this.json(route, this.shaped(row));
      }
      if (m[2] === "suggest-rules") {
        return this.json(route, { suggestions: [{ key: "a".repeat(32), kind: "replace", from: "Model", to: "Mk", label: '"Model" → "Mk"', brand: row.brand, reach: { brand: 14, all: 29 }, samples: {} }] });
      }
      if (m[2] === "edit") {
        row.status = "edited"; row.edited = String(body.title);
        this.applyState.queue = this.proposals.filter((r) => ["accepted", "edited"].includes(r.status)).length;
        const saved = (body.rules as unknown[] | undefined)?.length ?? 0;
        return this.json(route, { ...row, rules_saved: saved, recomposed: saved ? { changed: 12, new: 0 } : null });
      }
      if (m[2] === "apply") { row.original = row.current; row.current = row.edited ?? row.proposed; row.status = "applied"; this.applyState.applied_today++; return this.json(route, { result: "applied", message: row.current }); }
      if (row.status !== "applied") return this.json(route, { result: "error", message: "Only an applied title can be reverted." });
      row.current = row.original ?? row.current; row.status = "reverted";
      return this.json(route, { result: "reverted", message: row.current });
    }
    const px = /^pictures\/(\d+)\/revert$/.exec(path);
    if (px) {
      const r = this.pxRows.find((x) => x.product_id === px[1]);
      if (r) r.status = "reverted";
      return this.json(route, { message: "Old pictures restored (3 → 1)." });
    }
    switch (path) {
      case "custom-rules": return this.json(route, { rules: this.list() });
      case "title-words":
        if (req.method() === "POST") {
          for (const k of Object.keys(this.words)) if (Array.isArray(body[k])) this.words[k] = (body[k] as string[]).map((w) => (k === "keep_caps" ? w.toUpperCase() : w.toLowerCase()));
          return this.json(route, { words: { ...this.words, lowercase_styled: {} } });
        }
        return this.json(route, { pack: this.o.basic ? "Basic" : "House style", in_use: !!this.o.basic, words: { ...this.words, lowercase_styled: {} } });
      case "summary": {
        const by: Record<string, number> = {};
        for (const r of this.proposals) by[r.status] = (by[r.status] ?? 0) + 1;
        return this.json(route, this.summary());
      }
      case "settings":
        for (const k of ["enabled", "auto"] as const) if (k in body) this.applySettings[k] = !!body[k];
        for (const k of ["batch_size", "min_confidence"] as const) if (k in body) this.applySettings[k] = Number(body[k]);
        return this.json(route, this.summary());
      case "enrich/settings":
        if ("enabled" in body) this.enrichSettings.enabled = !!body.enabled;
        return this.json(route, this.enrichSummary());
      case "enrich/rules":
        this.enrichRules[String(body.rule)]!.approved = !!body.approved;
        return this.json(route, this.enrichSummary());
      case "proposals": return this.json(route, this.queue(new URL(req.url())));
      case "waiting": return this.json(route, this.waiting(new URL(req.url())));
      case "decide": {
        const to = { accept: "accepted", reject: "rejected", reset: "proposed" }[String(body.action)];
        let n = 0;
        for (const r of this.proposals) if ((body.ids as number[]).includes(r.id) && r.status !== "applied") { r.status = r.status === "edited" && body.action === "accept" ? "edited" : to!; if (body.action === "reset") r.edited = null; n++; }
        this.applyState.queue = this.proposals.filter((r) => ["accepted", "edited"].includes(r.status)).length;
        return this.json(route, { updated: n });
      }
      case "enrich": {
        const u = new URL(req.url());
        const kind = u.searchParams.get("kind") ?? "content";
        const status = u.searchParams.get("status") ?? "proposed";
        const source = u.searchParams.get("source") ?? "";
        const product = Number(u.searchParams.get("product") ?? 0);
        const rows = this.enrich.filter((r) => r.kind === kind && (status === "all" || r.status === status) && (!source || r.source === source) && (!product || r.id === product));
        return this.json(route, { rows, total: rows.length, pages: 1 });
      }
      case "enrich/summary": return this.json(route, this.enrichSummary());
      case "pictures": return this.json(route, this.pxSummary());
      case "pictures/rows": {
        const u = new URL(req.url());
        const show = u.searchParams.get("show") ?? "";
        const rows = this.pxRows.filter((r) => !show || (r.status === "applied" ? r.mode : r.status) === show);
        const p = Number(u.searchParams.get("page")) || 1;
        return this.json(route, { rows: rows.slice((p - 1) * 50, p * 50), total: rows.length, per_page: 50 });
      }
      case "pictures/stop": { const removed = this.pxWaiting; this.pxWaiting = 0; return this.json(route, { removed, summary: this.pxSummary() }); }
      case "exclusions": {
        if (req.method() === "POST") {
          const unknown: string[] = [];
          const ids: number[] = [];
          for (const x of (body.products as (number | string)[]) ?? []) { const n = Number(x); if (n >= 2001 && n <= 2030) ids.push(n); else { const r = this.proposals.find((p) => p.sku === x); if (r) ids.push(r.id); else unknown.push(String(x)); } }
          const before = this.leftCount();
          this.leftBrands = [30, ...((body.brands as number[]) ?? []).filter((b) => b !== 30)];
          this.leftProducts = ids;
          const now = this.leftCount();
          return this.json(route, { brands: this.leftBrands, products: ids, excluded: now, removed: Math.max(0, now - before), restored: Math.max(0, before - now), unknown, summary: this.leftSummary() });
        }
        return this.json(route, this.leftSummary());
      }
      case "enrich/decide": {
        const to = { accept: "accepted", reject: "rejected", reset: "proposed" }[String(body.action)]!;
        let n = 0;
        for (const r of this.enrich) if (r.kind === body.kind && (body.ids as number[]).includes(r.id) && r.status !== "applied") { r.status = to; n++; }
        return this.json(route, { updated: n });
      }
      case "proposals/bulk-terms": {
        const rows = this.proposals.filter((r) => (body.products as number[]).includes(r.id));
        return this.json(route, { rows: rows.map((r) => this.shaped(r)), removed: [], errors: {} });
      }
      case "apply-status": return this.json(route, this.applyState);
      case "apply-run":
        this.applyState = { ...this.applyState, phase: "waiting", next_at: this.applyState.now + 95, run: { total: this.applyState.queue, done: 0, finished_at: null } };
        return this.json(route, this.applyState);
      case "apply-stop":
        this.applyState = { ...this.applyState, phase: "idle", next_at: null };
        return this.json(route, this.applyState);
      case "rules": {
        const r = this.builtin[String(body.rule)];
        if (!r) return this.json(route, { code: "mmpt_bad", message: "Unknown rule." }, 400);
        if ("enabled" in body) {
          if (r.always_on) return this.json(route, { code: "mmpt_bad", message: "This rule is always on." }, 400);
          r.enabled = !!body.enabled;
          return this.json(route, { rules: this.builtin, recompose: { changed: 2, new: 0, removed: 0 } });
        }
        r.approved = !!body.approved;
        return this.json(route, { rules: this.builtin });
      }
      case "brands": return this.json(route, { brands: [{ id: 20, name: "Acme Audio", path: "Acme Audio", depth: 0 }, { id: 21, name: "Example Sound", path: "Example Sound", depth: 0 }] });
      case "categories": return this.json(route, { categories: CATS });
      case "suppliers": return this.json(route, { suppliers: { xchange: 40, skuport: 12 } });
      case "custom-rules/preview": {
        const r = this.normalize(body);
        if ("code" in r) return this.json(route, r, 400);
        const changed = TITLES.map((t, i) => ({ id: 1001 + i, before: t, after: this.apply(t, r) })).filter((x) => x.after !== x.before);
        return this.json(route, { rule: r, label: label(r), count: changed.length, scanned: TITLES.length, unstable: 0, samples: changed.slice(0, 5) });
      }
      case "custom-rules/add": {
        const r = this.normalize(body);
        if ("code" in r) return this.json(route, r, 400);
        r.id = `r${this.rules.length + 1}`;
        this.rules.push(r);
        return this.json(route, { rules: this.list(), saved: r, recomposed: { products: 2, changed: 2, new: 0, queued: 0 } });
      }
      case "custom-rules/update": {
        const r = this.normalize(body);
        if ("code" in r) return this.json(route, r, 400);
        const i = this.rules.findIndex((x) => x.id === body.id);
        r.id = String(body.id);
        this.rules[i] = r;
        return this.json(route, { rules: this.list(), saved: r, recomposed: { products: 1, changed: 1, new: 0, queued: 0 } });
      }
      case "custom-rules/delete":
        this.rules = this.rules.filter((x) => x.id !== body.id);
        return this.json(route, { rules: this.list(), recomposed: { products: 1, changed: 1, new: 0, queued: 0 } });
      case "custom-rules/order": {
        const ids = body.ids as string[];
        this.rules = [...ids.map((id) => this.rules.find((r) => r.id === id)!), ...this.rules.filter((r) => !ids.includes(r.id))];
        return this.json(route, { rules: this.list() });
      }
    }
    const mp = this.makerPages.handle(req.method(), new URL(req.url()), path, body);
    if (mp && (mp.status ?? 200) < 300) this.afterPageChange(path, body);
    if (mp) return this.json(route, mp.data, mp.status ?? 200);
    return this.json(route, { code: "rest_no_route", message: "No route" }, 404);
  }
}
