/**
 * A stateful stand-in for the title rule REST routes (mmpt/v1) and WordPress's
 * rest-nonce action, shaped like the real ones (CustomRules, Rest). Requests
 * are recorded for assertions.
 */
import type { Page, Route } from "@playwright/test";

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
  constructor(private o: { staleOnce?: boolean; basic?: boolean } = {}) {}

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
    switch (path) {
      case "custom-rules": return this.json(route, { rules: this.list() });
      case "title-words":
        if (req.method() === "POST") {
          for (const k of Object.keys(this.words)) if (Array.isArray(body[k])) this.words[k] = (body[k] as string[]).map((w) => (k === "keep_caps" ? w.toUpperCase() : w.toLowerCase()));
          return this.json(route, { words: { ...this.words, lowercase_styled: {} } });
        }
        return this.json(route, { pack: this.o.basic ? "Basic" : "House style", in_use: !!this.o.basic, words: { ...this.words, lowercase_styled: {} } });
      case "summary": return this.json(route, { rules: this.builtin, by_status: {} });
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
    return this.json(route, { code: "rest_no_route", message: "No route" }, 404);
  }
}
