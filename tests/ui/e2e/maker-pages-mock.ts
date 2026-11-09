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
    }
    return null;
  }
}
