/**
 * Description and picture proposals (Product Data's Enrichment) in the
 * Workbench: descriptions read from a maker's page or a supplier, and pictures
 * for products that have few or none. Accept queues a proposal for the
 * throttled writer; Write now writes one at once; Put back restores the old
 * value. Pictures are picked under each product's row, across the table: the
 * tiles show the pick in order, the first one the featured picture, then the
 * pictures left out. Click a tile to use or leave it out; drag it before or
 * after a used one to put it there, or onto a left-out one to leave it out
 * (Alt+arrows move a focused one, Alt+Home makes it featured). The pick is
 * saved as you go, and Accept or Write now imports exactly that, in that
 * order. The tiles' size is the person's choice, kept in this browser.
 */
import { Fragment, useCallback, useEffect, useRef, useState, type CSSProperties, type DragEvent, type KeyboardEvent } from "react";
import { ApiError } from "./api";
import {
  effectivePick, EMPTY_ENRICH, ENRICH_STATUS, ENRICH_VIEWS, enrichActions, enrichCount, enrichQuery, enrichSources, makeFeatured, nudgePick, PIC_SIZE, pickable, placePick, readPicSize,
  savePicSize, sourceShort, tileOrder, togglePick, writeResult,
  type EnrichAction, type EnrichFilter, type EnrichKind, type EnrichPage, type EnrichRow, type EnrichSummary,
} from "./enrichApi";
import { rest, type TitleRulesConfig } from "./titleRulesApi";
import { Dashicon, Section, SortTh, useColumnResize } from "./ui";
import { usePageRows } from "./pageRows";
import { EMPTY_PAGES, pagesQuery, type PageAction, type PageRow } from "./pagesApi";
import { EnrichWriting } from "./WritingSettings";

type SortKey = "id" | "title" | "source" | "status" | "value";
const COLS: { key: SortKey; label: string }[] = [
  { key: "id", label: "ID" },
  { key: "title", label: "Product" },
  { key: "source", label: "From" },
  { key: "status", label: "Status" },
];

interface Notice { text: string; kind: "ok" | "error" }

/**
 * kind: the kind shown, given by the side menu (Descriptions, Pictures to pick); the section's own pills
 * switch it through onKind, so the menu follows. Without them the section keeps its own choice.
 */
export function EnrichProposals({ cfg, kind, onKind }: { cfg: TitleRulesConfig; kind?: EnrichKind; onKind?: (k: EnrichKind) => void }) {
  const [filter, setFilter] = useState<EnrichFilter>(() => (kind ? { ...EMPTY_ENRICH, kind } : EMPTY_ENRICH));
  useEffect(() => { if (kind) setFilter((f) => (f.kind === kind ? f : { ...EMPTY_ENRICH, kind })); }, [kind]);
  const [page, setPage] = useState<EnrichPage | null>(null);
  const [loading, setLoading] = useState(false);
  const [summary, setSummary] = useState<EnrichSummary | null>(null);
  const [selected, setSelected] = useState<number[]>([]);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [rowNotice, setRowNotice] = useState<{ id: number } & Notice | null>(null);
  const [busy, setBusy] = useState<number | "bulk" | null>(null);
  const [sort, setSort] = useState<{ key: SortKey; dir: "asc" | "desc" }>({ key: "id", dir: "asc" });
  const seq = useRef(0);
  // Picks a person made on this page, saved as they clicked: shown as they are, even when empty.
  const [picks, setPicks] = useState<Record<number, string[]>>({});
  const shown = (r: EnrichRow): string[] => picks[r.id] ?? effectivePick(r);
  const [picSize, setPicSize] = useState(readPicSize);
  // The tile being dragged, and where it would land.
  const drag = useRef<{ row: number; token: string } | null>(null);
  const [drop, setDrop] = useState<{ row: number; token: string; side: "before" | "after" | "out" } | null>(null);

  const msg = (e: unknown) => (e instanceof ApiError ? e.message : String(e));
  const loadSummary = useCallback(() => { rest<EnrichSummary>(cfg, "GET", "enrich/summary").then(setSummary).catch(() => {}); }, [cfg]);
  const load = useCallback(async (f: EnrichFilter): Promise<EnrichPage | null> => {
    const mine = ++seq.current;
    setLoading(true);
    try {
      const d = await rest<EnrichPage>(cfg, "GET", `enrich?${enrichQuery(f)}`);
      if (mine === seq.current) setPage(d);
      return d;
    } catch (e) {
      if (mine === seq.current) { setPage({ rows: [], total: 0, pages: 1 }); setNotice({ text: msg(e), kind: "error" }); }
      return null;
    } finally { if (mine === seq.current) setLoading(false); }
  }, [cfg]);
  useEffect(() => { loadSummary(); }, [loadSummary]);
  useEffect(() => { void load(filter); setSelected([]); setRowNotice(null); setPicks({}); setMakerOpen(null); }, [filter, load]);
  const refresh = () => { void load(filter); loadSummary(); };

  /* ── The maker page, decided in place (2026-11-12) ────────────────────
   * A picture row's "Maker's page" opens the row Maker pages shows (pageRows.tsx): the page now and any proposed,
   * the address editor, the evidence and every decision. A page set, approved or read again is read in the
   * background, and PageEvents::after_read() rebuilds this product's pictures from it: the row is asked for again
   * until its pictures change, or it leaves (no new pictures on the new page). A cleared page takes its picture
   * proposal with it. Pictures from other products on the right page are the site's map, not the address. */
  const [makerOpen, setMakerOpen] = useState<number | null>(null);
  const [makerRows, setMakerRows] = useState<Record<number, PageRow | "loading" | string>>({});
  const [watching, setWatching] = useState<Record<number, string>>({});
  const timers = useRef<Record<number, number>>({});
  useEffect(() => () => { Object.values(timers.current).forEach((t) => window.clearTimeout(t)); }, []);
  const filterRef = useRef(filter);
  filterRef.current = filter;
  const rowsRef = useRef(page);
  rowsRef.current = page;

  const fetchPageRow = useCallback(async (id: number): Promise<boolean | null> => {
    try {
      const d = await rest<{ rows: PageRow[] }>(cfg, "GET", `page-list?${pagesQuery({ ...EMPTY_PAGES, group: "every" })}&ids[]=${id}`);
      const r = d.rows.find((x) => x.id === id);
      setMakerRows((m) => ({ ...m, [id]: r ?? "This product isn't in the maker-page list (left alone, or not sold)." }));
      return !!r;
    } catch (e) {
      setMakerRows((m) => ({ ...m, [id]: msg(e) }));
      return null;
    }
  }, [cfg]);

  const toggleMaker = (id: number) => {
    if (makerOpen === id) { setMakerOpen(null); return; }
    setMakerOpen(id);
    if (!makerRows[id] || typeof makerRows[id] === "string") {
      setMakerRows((m) => ({ ...m, [id]: "loading" }));
      void fetchPageRow(id);
    }
  };

  /** What a read can change in a picture row. */
  const sig = (r: EnrichRow) => JSON.stringify([r.images, r.own.map((o) => o.token), r.status, r.source_url]);
  const READS: PageAction[] = ["use", "useMoved", "set", "keep", "read", "scrape"];
  const pageChanged = (id: number, a: PageAction) => {
    const forget = () => setPicks((m) => { const c = { ...m }; delete c[id]; return c; });
    if (a === "clear" || a === "none") {
      void load(filterRef.current).then((d) => {
        loadSummary();
        if (d && !d.rows.some((r) => r.id === id)) {
          setMakerOpen((o) => (o === id ? null : o));
          setNotice({ text: `#${id}: its maker page was cleared, and the pictures proposed from it went with it.`, kind: "ok" });
        }
      });
      return;
    }
    if (!READS.includes(a)) return;
    const was = rowsRef.current?.rows.find((r) => r.id === id);
    const before = was ? sig(was) : "";
    window.clearTimeout(timers.current[id]);
    setWatching((w) => ({ ...w, [id]: "Reading the page. This product's pictures update here when the read is done." }));
    let tries = 0;
    const tick = async () => {
      tries++;
      try {
        const d = await rest<EnrichPage>(cfg, "GET", `enrich?${enrichQuery({ ...filterRef.current, page: 1 })}&product=${id}`);
        const fresh = d.rows.find((r) => r.id === id);
        if ((fresh ? sig(fresh) : "") !== before) {
          setWatching((w) => { const c = { ...w }; delete c[id]; return c; });
          forget();
          const all = await load(filterRef.current);
          loadSummary();
          if (fresh) setRowNotice({ id, text: "Pictures read again from the maker page.", kind: "ok" });
          else if (all && !all.rows.some((r) => r.id === id)) setNotice({ text: `#${id}: its maker page was read; it has no new pictures for the product, so it left this list.`, kind: "ok" });
          return;
        }
      } catch { /* asked again below */ }
      if (tries < 15) timers.current[id] = window.setTimeout(() => void tick(), 4000);
      else setWatching((w) => ({ ...w, [id]: "The read hasn't changed this product's pictures yet (the background reader may be busy, or the page has the same pictures). Reload the list later to see." }));
    };
    timers.current[id] = window.setTimeout(() => void tick(), 3000);
  };
  const { cells } = usePageRows(cfg, fetchPageRow, pageChanged);

  /** Site maps on this maker site, in a new tab (wb_site opens it). */
  const siteMapsHref = (host: string) => {
    const u = new URL(window.location.href);
    ["wb_q", "wb_brand", "wb_bundle", "wb_product"].forEach((p) => u.searchParams.delete(p));
    u.searchParams.set("wb_sec", "maps");
    u.searchParams.set("wb_site", host);
    u.hash = "";
    return u.toString();
  };

  const makerPanel = (row: EnrichRow) => {
    const pr = makerRows[row.id];
    const site = row.site;
    let body;
    if (pr === undefined || pr === "loading") body = <p className="description"><span className="mmi-loading" aria-hidden="true" /> Loading its maker page…</p>;
    else if (typeof pr === "string") body = <p className="description">{pr}</p>;
    else {
      const c = cells(pr);
      body = <div className="mmi-wb-en-maker-row"><div className="mmi-wb-en-maker-page">{c.page}</div><div className="mmi-wb-job-actions mmi-wb-en-maker-actions">{c.actions}</div></div>;
    }
    return (
      <div className="mmi-wb-en-maker" id={`mmi-wb-en-maker-${row.id}`} data-maker-for={row.id}>
        {body}
        {watching[row.id] && <p className="description mmi-wb-en-watch" role="status"><span className="mmi-loading" aria-hidden="true" /> {watching[row.id]}</p>}
        {site && (site.pictures ? (
          <p className="description mmi-wb-en-site">{site.host}'s map says where its product pictures are, so only those are read.</p>
        ) : (
          <p className="description mmi-wb-en-site">
            <Dashicon name="info-outline" /> {site.host} {site.map === "confirmed" ? "has a confirmed map without a Pictures part" : site.map === "none" ? "has no site map" : "has no confirmed site map"}, so
            the reader takes every picture on the page, a "related products" strip's too. Pictures of other products on the right page
            need the site mapped, not a new page:{" "}
            <a href={siteMapsHref(site.host)} target="_blank" rel="noreferrer">map {site.host}'s pictures in Site maps</a>, once for all its products (new tab).
          </p>
        ))}
      </div>
    );
  };

  /** Image rows about to be written keep exactly the pick shown: save it when none is saved yet. */
  const savePicks = async (ids: number[]) => {
    for (const r of (page?.rows ?? []).filter((x) => ids.includes(x.id) && x.kind === "image" && !x.chosen.length && !picks[x.id])) {
      const next = effectivePick(r);
      if (next.length) await rest(cfg, "POST", `enrich/${r.id}/image/choose`, { images: next });
    }
  };

  const decide = async (action: "accept" | "reject" | "reset", ids: number[]) => {
    setBusy(ids.length === 1 ? ids[0]! : "bulk");
    setNotice(null);
    try {
      if (action === "accept") await savePicks(ids);
      const d = await rest<{ updated: number }>(cfg, "POST", "enrich/decide", { action, kind: filter.kind, ids });
      const what = filter.kind === "content" ? "description" : "picture proposal";
      const word = action === "accept" ? "accepted: written in the next batch" : action === "reject" ? "rejected" : "back to review";
      setNotice({ text: `${d.updated} ${what}${d.updated === 1 ? "" : "s"} ${word}.`, kind: "ok" });
      setSelected([]);
      refresh();
    } catch (e) { setNotice({ text: msg(e), kind: "error" }); } finally { setBusy(null); }
  };

  const write = async (row: EnrichRow, path: "apply" | "revert") => {
    if (!window.confirm(path === "apply" ? `Write this ${row.kind === "content" ? "description" : "pick of pictures"} to the product now? The old value is kept for Put back.` : "Put the previous value back?")) return;
    setBusy(row.id);
    try {
      if (path === "apply") await savePicks([row.id]);
      const r = await rest<{ result: string; message: string }>(cfg, "POST", `enrich/${row.id}/${row.kind}/${path}`);
      const w = writeResult(r, row.kind);
      setRowNotice({ id: row.id, text: w.text, kind: w.ok ? "ok" : "error" });
      if (w.ok) refresh();
    } catch (e) { setRowNotice({ id: row.id, text: msg(e), kind: "error" }); } finally { setBusy(null); }
  };

  const pick = async (row: EnrichRow, next: string[]) => {
    const before = picks[row.id];
    setPicks((m) => ({ ...m, [row.id]: next }));
    try {
      const d = await rest<{ chosen: string[] }>(cfg, "POST", `enrich/${row.id}/image/choose`, { images: next });
      setPicks((m) => ({ ...m, [row.id]: d.chosen }));
    } catch (e) {
      setPicks((m) => { const c = { ...m }; if (before) c[row.id] = before; else delete c[row.id]; return c; });
      setRowNotice({ id: row.id, text: msg(e), kind: "error" });
    }
  };

  const sortBy = (key: SortKey) => setSort((s) => ({ key, dir: s.key === key && s.dir === "asc" ? "desc" : "asc" }));
  // The proposal column sorts by its size: a description's length, or how many pictures are proposed.
  const sortValue = (r: EnrichRow) => (sort.key === "value" ? (r.kind === "content" ? r.chars : r.images.length) : r[sort.key]);
  const rows = [...(page?.rows ?? [])].sort((a, b) => {
    const x = sortValue(a);
    const y = sortValue(b);
    return (typeof x === "number" && typeof y === "number" ? x - y : String(x).localeCompare(String(y))) * (sort.dir === "desc" ? -1 : 1);
  });
  const tableRef = useRef<HTMLTableElement>(null);
  useColumnResize(tableRef, rows.length > 0);
  const sources = summary ? enrichSources(summary, filter.kind) : [];
  const sourceLabel = (src: string) => sources.find((s) => s.value === src)?.label ?? src;
  const ticked = selected.filter((id) => enrichActions(rows.find((r) => r.id === id)?.status ?? "applied").includes("accept"));
  const allTicked = rows.length > 0 && rows.every((r) => selected.includes(r.id));
  // Pictures: each product's tiles take the table's whole width, under its row.
  const pics = filter.kind === "image";
  const cols = pics ? 6 : 7;
  const setKind = (k: EnrichKind) => { setFilter({ ...EMPTY_ENRICH, kind: k }); onKind?.(k); };

  const button = (row: EnrichRow, a: EnrichAction) => {
    const none = row.kind === "image" && shown(row).length === 0 && (a === "accept" || a === "apply");
    const dis = busy !== null || none;
    const why = none ? "Pick the pictures first" : undefined;
    switch (a) {
      case "accept": return <button key={a} type="button" className="button button-small button-primary" disabled={dis} title={why} onClick={() => decide("accept", [row.id])}><Dashicon name="yes" /> Accept</button>;
      case "reject": return <button key={a} type="button" className="button button-small mmi-wb-icon-btn mmi-wb-rule-delete" disabled={dis} aria-label={`Reject #${row.id}`} title="Reject: keep what the product has" onClick={() => decide("reject", [row.id])}><Dashicon name="no-alt" /></button>;
      case "reset": return <button key={a} type="button" className="button button-small" disabled={dis} onClick={() => decide("reset", [row.id])}><Dashicon name="undo" /> Undo</button>;
      case "apply": return <button key={a} type="button" className="button button-small" disabled={dis} title={why} onClick={() => write(row, "apply")}><Dashicon name="upload" /> Write now</button>;
      case "revert": return <button key={a} type="button" className="button button-small" disabled={dis} onClick={() => write(row, "revert")}><Dashicon name="backup" /> Put back</button>;
    }
  };

  /** Where a dragged tile would land on this one: before or after it when it is used, "out" when it is left out. */
  const landing = (e: DragEvent<HTMLDivElement>, used: boolean): "before" | "after" | "out" => {
    if (!used) return "out";
    const r = e.currentTarget.getBoundingClientRect();
    return e.clientX < r.left + r.width / 2 ? "before" : "after";
  };

  const pictures = (row: EnrichRow) => {
    const can = pickable(row) && busy === null;
    const chosen = shown(row);
    const tiles = tileOrder([...row.own.map((o) => ({ token: o.token, url: o.url, own: true })), ...row.images.map((u) => ({ token: u, url: u, own: false }))], chosen);
    const onKey = (e: KeyboardEvent<HTMLButtonElement>, token: string) => {
      if (!e.altKey || !chosen.includes(token)) return;
      const next = e.key === "ArrowLeft" ? nudgePick(chosen, token, -1) : e.key === "ArrowRight" ? nudgePick(chosen, token, 1) : e.key === "Home" ? makeFeatured(chosen, token) : null;
      if (!next) return;
      e.preventDefault();
      void pick(row, next);
      // The tile keeps the focus in its new place.
      window.setTimeout(() => (document.querySelector(`#mmi-wb-en-rows .mmi-wb-en-pic[data-token="${CSS.escape(token)}"] .mmi-wb-en-pic-img`) as HTMLElement | null)?.focus(), 0);
    };
    return (
      <div className="mmi-wb-en-pics" role="list" aria-label={`Pictures for #${row.id}, in the order they are used`}>
        {tiles.map((p) => {
          const i = chosen.indexOf(p.token);
          const low = row.low_res.includes(p.token);
          const here = drop && drop.row === row.id && drop.token === p.token ? ` is-drop-${drop.side}` : "";
          return (
            <div key={p.token} role="listitem" data-token={p.token}
              className={`mmi-wb-en-pic${i === 0 ? " is-featured" : i > 0 ? " is-gallery" : " is-out"}${low ? " is-low-res" : ""}${here}`}
              draggable={can}
              onDragStart={(e) => { drag.current = { row: row.id, token: p.token }; e.dataTransfer.effectAllowed = "move"; e.dataTransfer.setData("text/plain", p.token); }}
              onDragOver={(e) => {
                const d = drag.current;
                if (!d || d.row !== row.id || d.token === p.token) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = "move";
                const side = landing(e, i >= 0);
                setDrop((cur) => (cur && cur.row === row.id && cur.token === p.token && cur.side === side ? cur : { row: row.id, token: p.token, side }));
              }}
              onDragLeave={() => setDrop((cur) => (cur && cur.token === p.token ? null : cur))}
              onDrop={(e) => {
                const d = drag.current;
                setDrop(null);
                drag.current = null;
                if (!d || d.row !== row.id) return;
                e.preventDefault();
                const side = landing(e, i >= 0);
                const next = placePick(chosen, d.token, p.token, side === "out" ? "after" : side);
                if (next.join("\n") !== chosen.join("\n")) void pick(row, next);
              }}
              onDragEnd={() => { drag.current = null; setDrop(null); }}>
              <button type="button" className="mmi-wb-en-pic-img" disabled={!can}
                aria-pressed={i >= 0}
                aria-label={`${i >= 0 ? "Leave out" : "Use"} ${p.own ? "the product's picture" : "this picture"}${i === 0 ? " (featured)" : i > 0 ? ` (picture ${i + 1})` : ""}`}
                title={can ? (i >= 0 ? "Click to leave out. Drag to change the order: the first is the featured picture" : "Click to use. Drag before or after a used picture to put it there") : undefined}
                onClick={() => pick(row, togglePick(chosen, p.token))} onKeyDown={(e) => onKey(e, p.token)}>
                <img src={p.url} alt="" loading="lazy" draggable={false} />
              </button>
              {i >= 0 && <span className="mmi-wb-en-pic-badge">{i === 0 ? "Featured" : i}</span>}
              {p.own && <span className="mmi-wb-en-pic-own">now</span>}
              {low && <span className="mmi-wb-en-pic-low" title="Smaller than the picture standard">small</span>}
            </div>
          );
        })}
        {row.waiting && <p className="description">AVIF pictures: converted by the next background run, then written.</p>}
      </div>
    );
  };

  return (
    <Section id="mmi-wb-enrich" icon="format-image" title="Description and picture proposals"
      desc="Descriptions read from a maker's page or a supplier, and pictures for products that have few or none. Accept to have them written, or pick the pictures first; written values can be put back">
      <EnrichWriting cfg={cfg} onChange={refresh} />
      {notice && <div className={`notice notice-${notice.kind === "error" ? "error" : "success"} inline`} role="status" id="mmi-wb-en-notice"><p>{notice.text}</p></div>}
      <div className="mmi-filter-pills mmi-wb-pr-views" role="group" aria-label="What">
        {(["content", "image"] as const).map((k) => (
          <button key={k} type="button" className={`mmi-pill${filter.kind === k ? " active" : ""}`} aria-pressed={filter.kind === k} data-kind={k} onClick={() => setKind(k)}>
            {k === "content" ? "Descriptions" : "Pictures"} <span className="mmi-pill-count">{summary ? enrichCount(summary, k, "proposed") : "…"}</span>
          </button>
        ))}
      </div>
      <div className="mmi-filter-pills mmi-wb-pr-views" role="group" aria-label="Show">
        {ENRICH_VIEWS.map((v) => (
          <button key={v.value} type="button" className={`mmi-pill${filter.status === v.value ? " active" : ""}`} aria-pressed={filter.status === v.value} data-view={v.value}
            onClick={() => setFilter((f) => ({ ...f, status: v.value, page: 1 }))}>
            {v.label} <span className="mmi-pill-count">{summary ? enrichCount(summary, filter.kind, v.value, filter.source) : "…"}</span>
          </button>
        ))}
      </div>
      <div className="mmi-wb-filters mmi-wb-pr-filters">
        <select id="mmi-wb-en-source" aria-label="From" value={filter.source} onChange={(e) => setFilter((f) => ({ ...f, source: e.target.value, page: 1 }))}>
          <option value="">Every source</option>
          {sources.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
        </select>
        {filter.kind === "image" && (
          <select id="mmi-wb-en-has" aria-label="Pictures now" value={filter.has_images} onChange={(e) => setFilter((f) => ({ ...f, has_images: e.target.value as EnrichFilter["has_images"], page: 1 }))}>
            <option value="">With or without pictures now</option>
            <option value="no">No pictures now</option>
            <option value="yes">Some pictures now</option>
          </select>
        )}
        {filter.kind === "image" && (
          <label className="mmi-wb-en-size" title="How big the pictures show; kept in this browser">
            <span>Picture size</span>
            <input type="range" id="mmi-wb-en-size" min={PIC_SIZE.min} max={PIC_SIZE.max} step={PIC_SIZE.step} value={picSize}
              onChange={(e) => { const n = Number(e.target.value); setPicSize(n); savePicSize(n); }} />
            <output htmlFor="mmi-wb-en-size">{picSize} px</output>
          </label>
        )}
        <span className="mmi-wb-count" id="mmi-wb-en-count">{page ? `${page.total} proposal${page.total === 1 ? "" : "s"}` : ""}</span>
        {selected.length > 0 && (
          <span className="mmi-wb-pr-bulk">
            <span className="mmi-wb-selected">{selected.length} ticked</span>
            <button type="button" className="button button-small button-primary" id="mmi-wb-en-accept-ticked" disabled={busy !== null || !ticked.length} onClick={() => decide("accept", ticked)}>Accept {ticked.length}</button>
            <button type="button" className="button button-small" id="mmi-wb-en-reject-ticked" disabled={busy !== null || !ticked.length} onClick={() => decide("reject", ticked)}>Reject {ticked.length}</button>
          </span>
        )}
      </div>

      <div className="mmi-table-scroll-wrapper">
        <table ref={tableRef} className={`wp-list-table widefat mmi-wb-pr-table${pics ? " mmi-wb-en-has-pics" : " striped"}${loading ? " mmi-wb-loading" : ""}`} id="mmi-wb-en-table"
          style={pics ? ({ "--mmi-wb-en-pic": `${picSize}px` } as CSSProperties) : undefined}>
          <thead>
            <tr>
              <td className="check-column"><input type="checkbox" aria-label="Tick every row on this page" checked={allTicked} onChange={(e) => setSelected(e.target.checked ? rows.map((r) => r.id) : [])} /></td>
              {COLS.map((c) => (
                <SortTh key={c.key} col={c.key} label={c.label} title="Sorts this page" active={sort.key === c.key} desc={sort.dir === "desc"} onSort={sortBy} />
              ))}
              <SortTh col="value" active={sort.key === "value"} desc={sort.dir === "desc"} onSort={sortBy}
                label={pics ? "Pictures" : "Description: now → proposed"}
                title={pics ? "Sorts this page by how many pictures are proposed" : "Sorts this page by the proposed description's length"} />
              {!pics && <th data-resize-col="actions"><span className="screen-reader-text">Actions</span></th>}
            </tr>
          </thead>
          <tbody id="mmi-wb-en-rows">
            {!page && <tr><td colSpan={cols} className="mmi-wb-empty-cell">Loading proposals…</td></tr>}
            {page && !rows.length && <tr><td colSpan={cols} className="mmi-wb-empty-cell">Nothing here.</td></tr>}
            {rows.map((row, n) => {
              const [st, sk] = ENRICH_STATUS[row.status] ?? [row.status, ""];
              const mine = rowNotice && rowNotice.id === row.id ? rowNotice : null;
              const note = mine && <div className={`notice notice-${mine.kind === "error" ? "error" : "success"} inline`} role="status"><p>{mine.text}</p></div>;
              if (pics) {
                // The product's facts on one line, its pictures across the whole table under it.
                const used = shown(row).length;
                const all = row.own.length + row.images.length;
                const alt = n % 2 ? " is-alt" : "";
                return (
                  <Fragment key={row.id}>
                    <tr data-id={row.id} className={`mmi-wb-en-head${alt}`}>
                      <th scope="row" className="check-column"><input type="checkbox" aria-label={`Tick #${row.id}`} checked={selected.includes(row.id)}
                        onChange={(e) => setSelected((s) => (e.target.checked ? [...s, row.id] : s.filter((x) => x !== row.id)))} /></th>
                      <td className="mmi-wb-col-id"><a href={row.edit_url} target="_blank" rel="noreferrer">{row.id}</a></td>
                      <td className="mmi-wb-en-product">{row.title}</td>
                      <td>{row.source === "vendor_page" ? (
                        <button type="button" className="button-link mmi-wb-en-maker-toggle" aria-expanded={makerOpen === row.id} aria-controls={`mmi-wb-en-maker-${row.id}`}
                          title={`${sourceLabel(row.source)}. Check or change the page these pictures come from`} onClick={() => toggleMaker(row.id)}>
                          {sourceShort(row.source)} <Dashicon name={makerOpen === row.id ? "arrow-up-alt2" : "arrow-down-alt2"} />
                        </button>
                      ) : row.source_url ? <a href={row.source_url} target="_blank" rel="noreferrer" title={sourceLabel(row.source)}>{sourceShort(row.source)}</a> : <span title={sourceLabel(row.source)}>{sourceShort(row.source)}</span>}</td>
                      <td><span className={`mmi-badge ${sk} inline`}>{st}</span></td>
                      <td className="mmi-wb-en-used">{used} of {all} used{row.low_res.length ? <span className="mmi-wb-en-used-low"> · {row.low_res.length} small</span> : null}</td>
                    </tr>
                    <tr data-pics-for={row.id} className={`mmi-wb-en-sub${alt}`}>
                      <td colSpan={cols}>
                        <div className="mmi-wb-en-bar">
                          <span className="description">{pickable(row) ? "Click to use or leave out. Drag to order: the first is the featured picture, the rest the gallery." : "The pick this proposal was written with."}</span>
                          <span className="mmi-wb-job-actions">{enrichActions(row.status).map((a) => button(row, a))}</span>
                        </div>
                        {makerOpen === row.id && makerPanel(row)}
                        {note}
                        {pictures(row)}
                      </td>
                    </tr>
                  </Fragment>
                );
              }
              return (
                <tr key={row.id} data-id={row.id}>
                  <th scope="row" className="check-column"><input type="checkbox" aria-label={`Tick #${row.id}`} checked={selected.includes(row.id)}
                    onChange={(e) => setSelected((s) => (e.target.checked ? [...s, row.id] : s.filter((x) => x !== row.id)))} /></th>
                  <td className="mmi-wb-col-id"><a href={row.edit_url} target="_blank" rel="noreferrer">{row.id}</a></td>
                  <td className="mmi-wb-en-product">{row.title}</td>
                  <td>{row.source_url ? <a href={row.source_url} target="_blank" rel="noreferrer" title={sourceLabel(row.source)}>{sourceShort(row.source)}</a> : <span title={sourceLabel(row.source)}>{sourceShort(row.source)}</span>}</td>
                  <td><span className={`mmi-badge ${sk} inline`}>{st}</span></td>
                  <td className="mmi-wb-en-value">
                    {row.kind === "content" ? (
                      <>
                        <div className="mmi-wb-en-now">{row.current || <span className="description">No description now.</span>}</div>
                        <div className="mmi-wb-en-new">{row.proposed}{row.chars > row.proposed.length ? "…" : ""}</div>
                      </>
                    ) : pictures(row)}
                    {note}
                  </td>
                  <td className="mmi-wb-job-actions">{enrichActions(row.status).map((a) => button(row, a))}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      {page && page.pages > 1 && (
        <div className="mmi-wb-pr-pager" id="mmi-wb-en-pager">
          <button type="button" className="button button-small" disabled={filter.page <= 1 || loading} onClick={() => setFilter((f) => ({ ...f, page: f.page - 1 }))}>‹ Previous</button>
          <span>Page {filter.page} of {page.pages}</span>
          <button type="button" className="button button-small" disabled={filter.page >= page.pages || loading} onClick={() => setFilter((f) => ({ ...f, page: f.page + 1 }))}>Next ›</button>
        </div>
      )}
    </Section>
  );
}
