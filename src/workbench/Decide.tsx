/**
 * Needs a decision: every product something waits on, in one place, with the product drawer beside the list.
 *
 * Pills (owner 2026-11-13):
 * - All waiting: one table of every product, across the queues.
 * - The review queues (Title proposals, Descriptions, Pictures to pick, Maker pages) were the menu's Review group;
 *   they are subsets of this list, so they are its pills. Each shows its own tools under the pill (the queue's
 *   table, writer and bulk buttons), mounted on first open and kept, so its filters and page stay put.
 * - One pill per alert the listed products carry (No category, No picture, Not in Software Bundles…), from the
 *   server's `keys`. Choosing one lists the products with it and opens its fixes for all of them on the page.
 *
 * The table:
 * - Each row starts with the product's picture: its featured picture, a dashed one when only the gallery has
 *   pictures (the shop shows a placeholder), an empty box when it has none.
 * - Under an alert's pill, "Alerts on this page" (IssueGroups.tsx) fixes alike alerts at once: a one-click fix runs for each product, a
 *   category or brand fix opens the bulk picker on them, and the rest are fixed one by one in the drawer.
 * - Order: errors first, then the most things waiting, then newest; every heading sorts, a third click goes back.
 * - Clicking a row opens the drawer on it; ‹ › and J / K step through the rows on screen. A decision in the drawer
 *   redraws that row only; a row nothing waits on any more leaves the table.
 * - The Workbench opens here when something waits; when nothing does (and no link chose a section), it moves on to
 *   Find and change. A link's wb_product opens the drawer, its wb_kind (or an old review queue's wb_sec) the pill.
 */
import { useCallback, useEffect, useRef, useState, type KeyboardEvent } from "react";
import { ApiError } from "./api";
import { EnrichProposals } from "./EnrichProposals";
import { IssueGroups } from "./IssueGroups";
import { groupIssues, groupMode, runFix, type Fix, type IssueGroup } from "./issuesApi";
import { landing } from "./landing";
import { MakerPages } from "./MakerPages";
import { COUNTS_EVENT, PILL_EVENT, openSection, refreshCounts, SectionEmbedContext, SectionVisibleContext, useSectionVisible } from "./nav";
import { ProductDrawer } from "./ProductDrawer";
import type { TermKind } from "./proposalsApi";
import { rest, type TermOption, type TitleRulesConfig } from "./titleRulesApi";
import { TermPicker, TitleProposals } from "./TitleProposals";
import { Dashicon, ProductThumb, Section, SortTh, useColumnResize } from "./ui";
import {
  alertKeys, announceChanged, EMPTY_WAIT, isQueue, nextSort, PRODUCT_CHANGED, step, waitingQuery, WAIT_KINDS,
  type DrawerProduct, type QueueKind, type WaitFilter, type WaitingPage, type WaitKind, type WaitSort,
} from "./waitingApi";

const COLS: { key: Exclude<WaitSort, "">; label: string }[] = [
  { key: "id", label: "ID" },
  { key: "title", label: "Product" },
  { key: "brand", label: "Brand" },
  { key: "waiting", label: "Waiting on you" },
];

interface Notice { text: string; kind: "ok" | "error" }
interface BulkTerms { kind: TermKind; ids: number[]; mode: "replace" | "add" }

const msg = (e: unknown) => (e instanceof ApiError ? e.message : String(e));
const plural = (n: number, one: string, many = `${one}s`) => `${n.toLocaleString("en-US")} ${n === 1 ? one : many}`;

export function Decide({ cfg }: { cfg: TitleRulesConfig }) {
  const [filter, setFilter] = useState<WaitFilter>(() => ({ ...EMPTY_WAIT, kind: (landing().kind as WaitKind) || "" }));
  const [q, setQ] = useState("");
  const [page, setPage] = useState<WaitingPage | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [open, setOpen] = useState<number | null>(() => landing().product || null);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [groupOpen, setGroupOpen] = useState<string | null>(null);
  const [only, setOnly] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [progress, setProgress] = useState("");
  const [bulk, setBulk] = useState<BulkTerms | null>(null);
  const [termLists, setTermLists] = useState<Record<TermKind, TermOption[] | null>>({ cats: null, brands: null });
  const queue = isQueue(filter.kind) ? filter.kind : null;
  // A queue's tools are mounted the first time its pill is chosen, then kept (hidden) like the Workbench's sections.
  const [mounted, setMounted] = useState<Set<QueueKind>>(() => new Set(queue ? [queue] : []));
  const [enrichKind, setEnrichKind] = useState<"content" | "image">(filter.kind === "pictures" ? "image" : "content");
  const seq = useRef(0);
  const first = useRef(true);
  const visible = useSectionVisible();
  const visibleRef = useRef(visible);
  visibleRef.current = visible;

  const load = useCallback(async (f: WaitFilter) => {
    const mine = ++seq.current;
    setLoading(true);
    try {
      const d = await rest<WaitingPage>(cfg, "GET", `waiting?${waitingQuery(f)}`);
      if (mine !== seq.current) return;
      setPage(d);
      setError("");
      // Nothing waits and no link chose this section: the Workbench opens on Find and change instead.
      if (first.current && d.total === 0 && !f.kind && !f.q && !landing().sec && visibleRef.current) openSection("data", true);
    } catch (e) {
      if (mine === seq.current) setError(msg(e));
    } finally {
      first.current = false;
      if (mine === seq.current) setLoading(false);
    }
  }, [cfg]);

  useEffect(() => { void load(filter); }, [filter, load]);

  /** Choose a pill. */
  const choose = useCallback((kind: WaitKind) => {
    setFilter((f) => (f.kind === kind ? f : { ...f, kind, page: 1 }));
    setOnly(null);
    setBulk(null);
    setNotice(null);
    if (isQueue(kind)) {
      setMounted((s) => (s.has(kind) ? s : new Set(s).add(kind)));
      if (kind === "descriptions") setEnrichKind("content");
      if (kind === "pictures") setEnrichKind("image");
    }
  }, []);

  // Another part of the page asks for a pill (openSection("titles"), a queue's own switch).
  useEffect(() => {
    const on = (e: Event) => choose((e as CustomEvent<string>).detail as WaitKind);
    window.addEventListener(PILL_EVENT, on);
    return () => window.removeEventListener(PILL_EVENT, on);
  }, [choose]);

  // An alert's pill opens its fixes once its products are listed.
  const keyInfo = filter.kind.startsWith("key:") ? page?.keys.find((k) => `key:${k.key}` === filter.kind) ?? null : null;
  const autoOpened = useRef("");
  useEffect(() => {
    if (!keyInfo || autoOpened.current === filter.kind) return;
    autoOpened.current = filter.kind;
    setGroupOpen(keyInfo.issue);
  }, [keyInfo, filter.kind]);
  useEffect(() => { if (!filter.kind.startsWith("key:")) autoOpened.current = ""; }, [filter.kind]);

  // The open product (wb_product) and pill (wb_kind) are in the address, so a reload or a shared link opens them.
  useEffect(() => {
    if (!visible) return;
    const u = new URL(window.location.href);
    if (open === null || queue) u.searchParams.delete("wb_product");
    else u.searchParams.set("wb_product", String(open));
    if (filter.kind) u.searchParams.set("wb_kind", filter.kind);
    else u.searchParams.delete("wb_kind");
    if (u.toString() !== window.location.href) window.history.replaceState(window.history.state, "", u.toString());
  }, [open, visible, filter.kind, queue]);
  useEffect(() => {
    if (q === filter.q) return;
    const t = window.setTimeout(() => setFilter((f) => ({ ...f, q, page: 1 })), 400);
    return () => window.clearTimeout(t);
  }, [q, filter.q]);

  /** The drawer decided something: redraw that row, or drop it when nothing waits on it any more. */
  const changed = (p: DrawerProduct) => {
    setPage((pg) => {
      if (!pg) return pg;
      const i = pg.rows.findIndex((r) => r.id === p.id);
      if (i < 0) return pg;
      if (!p.waiting.length) return { ...pg, rows: pg.rows.filter((r) => r.id !== p.id), total: pg.total - 1 };
      const rows = [...pg.rows];
      rows[i] = { ...rows[i]!, title: p.title, cats: p.cats, brands: p.brands, waiting: p.waiting, issues: p.issues, thumb: p.thumb };
      return { ...pg, rows };
    });
  };

  // Other products a decision changed (a brand mapped to the supplier's other products, a queue's decision): when
  // any is on this page, the page is read again; the pills' counts are read again after any change.
  const pageRef = useRef({ page, filter, open });
  pageRef.current = { page, filter, open };
  useEffect(() => {
    const on = (e: Event) => {
      const ids = (e as CustomEvent<number[] | number>).detail;
      const { page: pg, filter: f, open: o } = pageRef.current;
      const shown = new Set((pg?.rows ?? []).map((r) => r.id));
      if ((Array.isArray(ids) ? ids : [ids]).some((id) => id !== o && shown.has(id))) void load(f);
    };
    let later = 0;
    const stale = () => { window.clearTimeout(later); later = window.setTimeout(() => void load(pageRef.current.filter), 1500); };
    window.addEventListener(PRODUCT_CHANGED, on);
    window.addEventListener(COUNTS_EVENT, stale);
    return () => { window.removeEventListener(PRODUCT_CHANGED, on); window.removeEventListener(COUNTS_EVENT, stale); window.clearTimeout(later); };
  }, [load]);

  const rows = page?.rows ?? [];
  // Alerts on this page, alike ones together; "only" shows the rows with one of them (it ends once none is left).
  const groups = groupIssues(rows);
  const onlyKey = only && groups.some((g) => g.key === only) ? only : null;
  const listed = onlyKey ? rows.filter((r) => r.issues.some((i) => i.key === onlyKey)) : rows;

  /** An alert's fix for every product on the page that carries it. */
  const fixGroup = async (g: IssueGroup, f: Fix) => {
    const mode = groupMode(f);
    if (mode === "terms") {
      const kind: TermKind = f.kind === "cats" ? "cats" : "brands";
      setGroupOpen(null);
      setBulk({ kind, ids: g.ids, mode: "replace" });
      if (!termLists[kind]) {
        const path = kind === "cats" ? "categories" : "brands";
        rest<Record<string, TermOption[]>>(cfg, "GET", path).then((d) => setTermLists((l) => ({ ...l, [kind]: d[path] ?? [] }))).catch((e) => setNotice({ text: msg(e), kind: "error" }));
      }
      return;
    }
    if (mode !== "each" || !f.action) return;
    setBusy(true);
    setNotice(null);
    const failed: string[] = [];
    const done: number[] = [];
    for (const [i, id] of g.ids.entries()) {
      setProgress(`${f.label}: ${i + 1} of ${g.ids.length}…`);
      try { await runFix(cfg, id, { kind: "action", action: f.action }); done.push(id); } catch (e) { failed.push(`#${id}: ${msg(e)}`); }
    }
    setProgress("");
    setBusy(false);
    setGroupOpen(null);
    setNotice({
      text: `${f.label}: done for ${done.length} of ${plural(g.ids.length, "product")}.${failed.length ? ` Not done: ${failed.join("; ")}` : ""}`,
      kind: failed.length ? "error" : "ok",
    });
    if (done.length) announceChanged(done);
    void load(filter);
    refreshCounts();
  };

  const saveBulk = async (ids: number[]) => {
    if (!bulk) return;
    const noun = bulk.kind === "cats" ? "Categories" : "Brands";
    setBusy(true);
    try {
      const d = await rest<{ rows: unknown[]; removed: number[]; errors: Record<string, string> }>(cfg, "POST", "proposals/bulk-terms",
        { kind: bulk.kind, mode: bulk.mode, products: bulk.ids, ids });
      const n = d.rows.length + d.removed.length;
      const failed = Object.keys(d.errors).length;
      setNotice({ text: `${noun} saved on ${plural(n, "product")}, locked against imports; their titles were recomposed.${failed ? ` ${failed} failed: ${Object.values(d.errors)[0]}` : ""}`, kind: failed ? "error" : "ok" });
      setBulk(null);
      announceChanged(bulk.ids);
      void load(filter);
      refreshCounts();
    } catch (e) { setNotice({ text: msg(e), kind: "error" }); } finally { setBusy(false); }
  };

  const tableRef = useRef<HTMLTableElement>(null);
  useColumnResize(tableRef, rows.length > 0);
  const sortBy = (col: Exclude<WaitSort, "">) => setFilter((f) => ({ ...f, ...nextSort(f, col), page: 1 }));
  const onRowKey = (e: KeyboardEvent, id: number) => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); setOpen(id); } };
  const alerts = page ? alertKeys(page.keys, filter.kind) : [];
  const pill = (active: boolean) => `mmi-pill${active ? " active" : ""}`;

  const queuePane = (k: QueueKind | "enrich", on: boolean, body: JSX.Element) => (
    <div className="mmi-wb-dc-queue" data-queue={k} hidden={!on}>
      <SectionVisibleContext.Provider value={visible && on}>{body}</SectionVisibleContext.Provider>
    </div>
  );

  return (
    <Section id="mmi-wb-decide" icon="flag" title="Needs a decision"
      desc="Every product something waits on. The first pills are the review queues, each with its own tools; after them, one pill per alert, with its fixes for every product that carries it. Click a product to decide everything about it in the drawer beside the list">
      <div className="mmi-toolbar mmi-wb-dc-toolbar">
        {!queue && <input type="search" id="mmi-wb-dc-q" placeholder="Search titles, SKU or ID" aria-label="Search the products waiting" value={q} onChange={(e) => setQ(e.target.value)} />}
        <div className="mmi-filter-pills mmi-wb-dc-pills" role="group" aria-label="What waits">
          {WAIT_KINDS.map((k) => (
            <button key={k.value || "all"} type="button" className={pill(filter.kind === k.value)} aria-pressed={filter.kind === k.value}
              data-kind={k.value || "all"} title={k.hint} onClick={() => choose(k.value)}>
              {k.label} <span className="mmi-pill-count">{page ? page.counts[k.count].toLocaleString("en-US") : "…"}</span>
            </button>
          ))}
        </div>
        {alerts.length > 0 && (
          <div className="mmi-filter-pills mmi-wb-dc-alert-pills" role="group" aria-label="Alerts">
            <span className="mmi-wb-dc-pills-lead">Alerts</span>
            {alerts.map((k) => {
              const kind = `key:${k.key}` as WaitKind;
              return (
                <button key={k.key} type="button" className={`${pill(filter.kind === kind)} mmi-wb-dc-alert-pill is-${k.tone}`} aria-pressed={filter.kind === kind}
                  data-kind={kind} title={`Products with “${k.label}”, and its fixes for all of them`} onClick={() => choose(filter.kind === kind ? "" : kind)}>
                  <span className="mmi-wb-dc-tone" aria-hidden="true" />{k.label} <span className="mmi-pill-count">{k.n.toLocaleString("en-US")}</span>
                </button>
              );
            })}
          </div>
        )}
      </div>
      {error && <div className="notice notice-error inline"><p>{error}</p></div>}

      <SectionEmbedContext.Provider value={true}>
        {mounted.has("titles") && queuePane("titles", queue === "titles", <TitleProposals cfg={cfg} />)}
        {(mounted.has("descriptions") || mounted.has("pictures")) && queuePane("enrich", queue === "descriptions" || queue === "pictures",
          <EnrichProposals cfg={cfg} kind={enrichKind} onKind={(k) => choose(k === "image" ? "pictures" : "descriptions")} />)}
        {mounted.has("pages") && queuePane("pages", queue === "pages", <MakerPages cfg={cfg} />)}
      </SectionEmbedContext.Provider>

      <div className="mmi-wb-dc-all" hidden={queue !== null}>
        {notice && <div className={`notice notice-${notice.kind === "error" ? "error" : "success"} inline`} role="status" id="mmi-wb-dc-notice"><p>{notice.text}</p></div>}
        {/* The alert pills lead here: once one is chosen, its fixes (and the other alerts on its products) show. */}
        {filter.kind.startsWith("key:") && <IssueGroups groups={groups} open={groupOpen} onOpen={(k) => { setGroupOpen(k); if (k) setBulk(null); }}
          only={onlyKey} onOnly={(k) => { setOnly(k); if (k) setGroupOpen(null); }} busy={busy} progress={progress} onFix={(g, f) => void fixGroup(g, f)}
          oneByOneHint="click each product to fix it in the drawer beside the list" />}
        {bulk && (
          <TermPicker key={`bulk-${bulk.kind}`} terms={termLists[bulk.kind]} chosen={[]} kind={bulk.kind} bulk={bulk.ids.length} mode={bulk.mode} busy={busy}
            onMode={(m) => setBulk({ ...bulk, mode: m })} onSave={(ids) => void saveBulk(ids)} onCancel={() => setBulk(null)} />
        )}

        <div className={`mmi-wb-dc${open !== null ? " has-drawer" : ""}`}>
          <div className="mmi-wb-dc-list">
            <div className="mmi-table-scroll-wrapper">
              <table ref={tableRef} className={`wp-list-table widefat striped mmi-wb-dc-table${loading ? " mmi-wb-loading" : ""}`} id="mmi-wb-dc-table">
                <thead>
                  <tr>
                    {COLS.map((c) => {
                      const on = filter.orderby === c.key;
                      return (
                        <SortTh key={c.key} col={c.key} label={c.label} active={on} desc={filter.order === "desc"} onSort={sortBy}
                          title={on ? "Sorted by this; click to flip, a third click goes back to errors first" : "Sort by this"} />
                      );
                    })}
                  </tr>
                </thead>
                <tbody id="mmi-wb-dc-rows">
                  {!page && <tr><td colSpan={4} className="mmi-wb-empty-cell">Loading what waits…</td></tr>}
                  {page && !rows.length && <tr><td colSpan={4} className="mmi-wb-empty-cell">{filter.kind || filter.q ? "Nothing here." : "Nothing waits on you."}</td></tr>}
                  {listed.map((r) => (
                    <tr key={r.id} data-id={r.id} className={`mmi-wb-dc-row${open === r.id ? " is-open" : ""}`} tabIndex={0} aria-selected={open === r.id}
                      onClick={(e) => { if (!(e.target as HTMLElement).closest("a")) setOpen(r.id); }} onKeyDown={(e) => onRowKey(e, r.id)}>
                      <td className="mmi-wb-dc-id">{r.id}</td>
                      <td className="mmi-wb-dc-product">
                        <div className="mmi-wb-dc-prod">
                          <ProductThumb thumb={r.thumb} />
                          <div className="mmi-wb-dc-prod-text">
                            <strong className="mmi-wb-dc-title">{r.title}</strong>
                            <div className="description">{[r.cats.map((c) => c.name).join(", "), r.sku && `SKU ${r.sku}`, r.status !== "publish" && r.status].filter(Boolean).join(" · ")}</div>
                          </div>
                        </div>
                      </td>
                      <td className="mmi-wb-dc-brand">{r.brands.map((b) => b.name).join(", ") || <span className="description">None</span>}</td>
                      <td><div className="mmi-wb-dc-waiting">{r.waiting.map((w) => <span key={w.key} className={`mmi-badge ${w.tone}`} data-wait={w.key}>{w.label}</span>)}</div></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {page && page.pages > 1 && (
              <div className="mmi-wb-pr-pager" id="mmi-wb-dc-pager">
                <button type="button" className="button button-small" disabled={filter.page <= 1 || loading} onClick={() => setFilter((f) => ({ ...f, page: f.page - 1 }))}>‹ Previous</button>
                <span>Page {filter.page} of {page.pages} · {plural(page.total, "product")}</span>
                <button type="button" className="button button-small" disabled={filter.page >= page.pages || loading} onClick={() => setFilter((f) => ({ ...f, page: f.page + 1 }))}>Next ›</button>
              </div>
            )}
            {open !== null && <p className="description mmi-wb-dc-keys"><Dashicon name="info-outline" /> J and K step through the list; Escape closes the drawer.</p>}
          </div>
          {open !== null && !queue && (
            <ProductDrawer cfg={cfg} id={open} prev={step(listed, open, -1)} next={step(listed, open, 1)}
              onStep={setOpen} onClose={() => setOpen(null)} onChanged={changed} />
          )}
        </div>
      </div>
    </Section>
  );
}
