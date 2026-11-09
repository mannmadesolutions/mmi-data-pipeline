/**
 * Mocked Product Data routes for the Workbench's Maker pages section: GET page-list (groups worked out from each
 * product's state as PageList does), the /urls/* decisions, /urls/evidence, /pages/queue and page-list/{id}/keep.
 * TitleRulesMock hands it every path it doesn't know.
 */
interface MockPage {
  id: number; title: string; current: string; verified: boolean; proposed: string; status: string; conf: number | null;
  broken?: boolean; wrong?: string; kept?: boolean; noPage?: boolean; final?: string; reason?: string;
}

const REASONS: Record<string, string> = { sku_slug: "The page's address is the product's own part number", sitemap_slug: "Found by name in the maker's sitemap (weak match)", manual: "Decided by a person" };
const WRONG: Record<string, string> = { home: "The maker's home page: no product data is read from it." };

export class MakerPagesMock {
  /** The next evidence request answers as the server does under load. */
  busyOnce = false;
  pages: MockPage[] = [
    { id: 4001, title: "Acme Synth 1 (Download)", current: "", verified: false, proposed: "https://maker.example/synth-1", status: "candidate", conf: 92, reason: "sku_slug" },
    { id: 4002, title: "Acme Synth 2 (Download)", current: "https://maker.example/synths", verified: true, proposed: "https://maker.example/synth-2", status: "candidate", conf: 60, reason: "sitemap_slug" },
    { id: 4003, title: "Acme Drum 1 (Download)", current: "https://maker.example/old-drum", verified: true, proposed: "", status: "none", conf: 40, broken: true, final: "https://www.maker.example/drum-1" },
    { id: 4004, title: "Acme Cable 10 ft", current: "", verified: false, proposed: "", status: "none", conf: null },
    { id: 4005, title: "Acme Year Pack (Download)", current: "https://maker.example/", verified: true, proposed: "", status: "none", conf: 0, wrong: "home" },
    { id: 4006, title: "Acme Mic Pack", current: "https://maker.example/mic-pack", verified: true, proposed: "https://maker.example/mic-pack", status: "approved", conf: null, reason: "manual" },
    { id: 4007, title: "Acme Bass (Download)", current: "https://maker.example/bass", verified: true, proposed: "https://shop.example/bass", status: "rejected", conf: null, reason: "sitemap_slug" },
  ];

  brands = [
    { id: 20, name: "Acme Audio", website: "https://acme.example", url: "https://acme.example", logo: "", products: 7, open: 2, failed_open: 1, no_url: 1, no_page: 0, gone: false, bundles: 0, bundles_none: 0,
      sites: [{ host: "acme.example", n: 5, read: 4, unread: 0, failed: 1, unverified: 0, better: 0, bundles: 0, open: 2, role: "official", why: "", saved: true, map: "", shared: 0 },
        { host: "shop.example", n: 1, read: 1, unread: 0, failed: 0, unverified: 0, better: 0, bundles: 0, open: 0, role: "", why: "", saved: false, map: "", shared: 0 }] },
    { id: 21, name: "Example Sound", website: "", url: "", logo: "", products: 3, open: 0, failed_open: 0, no_url: 3, no_page: 0, gone: false, bundles: 0, bundles_none: 0,
      sites: [{ host: "resell.example", n: 3, read: 3, unread: 0, failed: 0, unverified: 0, better: 0, bundles: 0, open: 0, role: "reseller", why: "", saved: true, map: "", shared: 0 }] },
    { id: 22, name: "Old Maker", website: "", url: "", logo: "", products: 2, open: 0, failed_open: 0, no_url: 0, no_page: 2, gone: true, bundles: 0, bundles_none: 0, sites: [] },
  ];
  /** Reading a maker site's product list: the first ask answers "still reading". */
  readsLeft = 1;

  group(p: MockPage): string {
    if (p.status === "candidate" && p.proposed) return "open";
    if (p.noPage) return "";
    if (p.current && p.broken) return "broken";
    if (p.current && p.wrong && !p.kept) return "wrong";
    if (!p.current) return "none";
    return "";
  }

  private row(p: MockPage, group: string) {
    const read = p.noPage ? "no_page" : p.broken ? "moved" : p.current ? "read" : "not_read";
    return {
      id: p.id, title: p.title, edit_url: `post.php?post=${p.id}&action=edit`, view_url: "#", image: "", sku: `SKU-${p.id}`, brand: { id: 20, name: "Acme Audio" },
      url: p.current, current: p.current, verified: p.verified, proposed: p.proposed, status: p.status, reason: p.reason ?? "", reason_label: REASONS[p.reason ?? ""] ?? "",
      score: p.conf ?? 0, note: "", vendor_name: "", page_title: p.proposed ? `${p.title} | Acme` : "", page_status: "ok", page_method: "http", offer_url: p.current,
      result: read, result_label: { no_page: "No vendor page", moved: "Moved: decide on its new address", read: "Read", not_read: "Not read yet" }[read],
      result_badge: read === "read" ? "success" : read === "moved" ? "warning" : "info", fetch_status: p.noPage ? "no_page" : p.broken ? "moved" : p.current ? "ok" : "",
      read_at: "", read_note: p.final ? `Now at ${p.final}` : "", read_final: p.final ?? "", bundle: null, proposals: [], pictures: null, queued: "", map: "",
      coverage: { score: 1, attrs: [{ key: "images", label: "Images", icon: "", state: "missing", detail: "", page: "2 images", fills: true }] },
      op_group: group, op_conf: p.conf, op_wrong: p.wrong && !p.kept ? p.wrong : "", op_wrong_note: p.wrong && !p.kept ? WRONG[p.wrong] : "",
    };
  }

  /** The reply to a path this mock knows, else null. */
  handle(method: string, url: URL, path: string, body: Record<string, unknown>): { data: unknown; status?: number } | null {
    const find = (id: number) => this.pages.find((p) => p.id === id)!;
    if (path === "page-list" && method === "GET") {
      const g = url.searchParams.get("group") ?? "";
      const conf = url.searchParams.get("conf") ?? "";
      const q = (url.searchParams.get("q") ?? "").toLowerCase();
      const ids = url.searchParams.getAll("ids[]").map(Number);
      const scope = this.pages.filter((p) => !q || p.title.toLowerCase().includes(q) || String(p.id) === q);
      const sets: Record<string, MockPage[]> = { open: [], broken: [], none: [], wrong: [] };
      for (const p of scope) { const k = this.group(p); if (k) sets[k]!.push(p); }
      sets.approved = scope.filter((p) => p.status === "approved");
      sets.rejected = scope.filter((p) => p.status === "rejected");
      sets.every = scope;
      const waiting = [...sets.open!, ...sets.broken!, ...sets.none!, ...sets.wrong!];
      let list = g ? sets[g]! : waiting;
      const band = (n: number | null) => (n === null ? "unrated" : n >= 85 ? "high" : n >= 70 ? "mid" : n >= 55 ? "low" : "bad");
      const rated = !["approved", "rejected", "every"].includes(g);
      const cc: Record<string, number> = { high: 0, mid: 0, low: 0, bad: 0, unrated: 0 };
      if (rated) for (const p of list) if (p.conf !== null) cc[band(p.conf)]!++;
      if (conf) list = list.filter((p) => p.conf !== null && band(p.conf) === conf);
      const slice = ids.length ? list.filter((p) => ids.includes(p.id)) : list;
      const why = (p: MockPage) => (["approved", "rejected", "every"].includes(g) ? g : this.group(p));
      return {
        data: {
          rows: url.searchParams.get("rows") === "0" ? [] : slice.map((p) => this.row(p, why(p))), total: list.length, page: 1, pages: 1,
          counts: Object.fromEntries(Object.entries(sets).map(([k, v]) => [k, v.length])), conf_counts: cc, group: g, conf,
          reasons: [{ key: "sku_slug", label: REASONS.sku_slug, n: 1 }, { key: "sitemap_slug", label: REASONS.sitemap_slug, n: 1 }],
          results: [{ key: "read", label: "Read", n: 3 }, { key: "moved", label: "Moved: decide on its new address", n: 1 }],
        },
      };
    }
    const keep = path.match(/^page-list\/(\d+)\/keep$/);
    if (keep) { find(Number(keep[1])).kept = true; return { data: { kept: find(Number(keep[1])).current } }; }
    const one = path.match(/^urls\/(\d+)\/(set|clear|none|look)$/);
    if (one) {
      const p = find(Number(one[1]));
      switch (one[2]) {
        case "set":
          if (!/^https?:\/\//.test(String(body.url))) return { data: { message: "That address can't be read." }, status: 400 };
          Object.assign(p, { current: String(body.url), verified: true, status: "approved", broken: false, wrong: undefined, final: undefined, proposed: String(body.url) });
          break;
        case "clear": Object.assign(p, { current: "", verified: false, status: "rejected", broken: false, wrong: undefined }); break;
        case "none": Object.assign(p, { current: "", verified: false, status: "rejected", noPage: true, broken: false, wrong: undefined }); break;
        case "look":
          if (!p.noPage) return { data: { message: "This product isn’t marked as having no vendor page." }, status: 400 };
          p.noPage = false; p.status = "none"; break;
      }
      return { data: { rows: [] } };
    }
    switch (path) {
      case "urls/decide": {
        const ids = body.ids as number[];
        const skipped: number[] = [];
        let done = 0;
        for (const id of ids) {
          const p = find(id);
          if (body.action === "approve" && ids.length > 1 && p.verified && p.current && p.current !== p.proposed) { skipped.push(id); continue; }
          if (body.action === "approve") Object.assign(p, { status: "approved", current: p.proposed, verified: true });
          else if (body.action === "reject") p.status = "rejected";
          else p.status = "candidate";
          done++;
        }
        return { data: { done, skipped, offsite: {}, status: body.action, rows: [], queue: null } };
      }
      case "urls/none-many": {
        const done: number[] = [];
        const kept: number[] = [];
        for (const id of body.ids as number[]) {
          const p = find(id);
          if (p.verified && p.current && !p.broken && !p.wrong) { kept.push(id); continue; }
          Object.assign(p, { current: "", verified: false, status: "rejected", noPage: true, broken: false, wrong: undefined });
          done.push(id);
        }
        return { data: { done, kept } };
      }
      case "urls/evidence": {
        if (this.busyOnce) { this.busyOnce = false; return { data: { message: "The server is busy right now. Try again in a minute." }, status: 503 }; }
        const rows: Record<string, unknown> = {};
        for (const id of body.ids as number[]) {
          const p = find(id);
          rows[id] = p.status === "candidate" ? {
            ours: { image: "", pictures: 0, chars: 0 },
            proposed: { url: p.proposed, host: "maker.example", images: [], text: `All about ${p.title}.`, chars: 1234, status: "ok", title: `${p.title} | Acme`, stored_at: "2026-11-05 18:50:04", pending: false },
            reason_label: REASONS[p.reason ?? ""] ?? "", score: p.conf ?? 0,
            notes: [["good", "Its title names it", 25], ["info", "Its text 1,234 characters; ours 0"]],
            confidence: { n: p.conf ?? 0, why: ["+25 Its title names it"] },
          } : null;
        }
        return { data: { rows } };
      }
      case "pages/queue": return { data: { queued: (body.ids as number[]).length, started: true, status: {}, states: {} } };
      case "brand-sites": return { data: { brands: this.brands, shared: {}, find: {} } };
      case "urls/set-many": {
        const done: number[] = [];
        for (const it of body.items as { id: number; url: string }[]) { Object.assign(find(it.id), { current: it.url, verified: true, status: "approved", broken: false, wrong: undefined }); done.push(it.id); }
        return { data: { done, errors: {}, queue: null } };
      }
    }
    const bs = path.match(/^brand-sites\/(\d+)(?:\/(products|pages))?$/);
    if (bs) {
      const b = this.brands.find((x) => x.id === Number(bs[1]))!;
      if (!bs[2] && method === "POST") {
        if (body.website !== undefined) b.website = String(body.website);
        if (body.gone !== undefined) b.gone = !!body.gone;
        for (const [host, role] of Object.entries((body.roles ?? {}) as Record<string, string>)) {
          const s = b.sites.find((x) => x.host === host);
          if (s) s.role = role === "unknown" ? "" : role; else b.sites.push({ host, n: 0, read: 0, unread: 0, failed: 0, unverified: 0, better: 0, bundles: 0, open: 0, role, why: "", saved: true, map: "", shared: 0 });
        }
        return { data: { brand: b, find: null } };
      }
      if (bs[2] === "products") {
        const g = url.searchParams.get("group") ?? "work";
        const mine = b.id === 20 ? this.pages : [];
        const rows = mine.filter((p) => (g === "all" ? true : g === "nopub" ? !!p.noPage : g === "work" ? ["open", "broken", "none", "wrong"].includes(this.group(p)) : false))
          .map((p) => { const r = this.row(p, ""); return { ...r, op_group: undefined, op_conf: undefined, op_wrong: undefined, op_wrong_note: undefined, bs: { kind: "site" }, groups: ["all"], shared: null }; });
        return { data: { group: g, rows, total: rows.length, page: 1, per_page: 50, brand: b } };
      }
      if (bs[2] === "pages") {
        const host = url.searchParams.get("host") ?? "";
        if (this.readsLeft > 0) { this.readsLeft--; return { data: { pending: true, hosts: [host], host, reading: { at: "", source: "sitemap", page: 2 } } }; }
        const ids = url.searchParams.getAll("ids[]").map(Number);
        const known: Record<number, { url: string; name: string; score: number; why: string }> = {
          4004: { url: "https://acme.example/cable-10", name: "Cable 10 ft", score: 88, why: "its name names it" },
          4001: { url: "https://acme.example/synth-one", name: "Synth One", score: 62, why: "a weak match" },
        };
        return { data: { hosts: [host], host, source: "sitemap", blocked: false, base: "https://acme.example/", items: null, cards: [], matches: Object.fromEntries(ids.map((id) => [id, known[id] ?? null])) } };
      }
    }
    return null;
  }
}
