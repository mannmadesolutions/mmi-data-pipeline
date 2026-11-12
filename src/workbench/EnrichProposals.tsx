/**
 * Description and picture proposals (Product Data's Enrichment) in the
 * Workbench: descriptions read from a maker's page or a supplier, and pictures
 * for products that have few or none. Accept queues a proposal for the
 * throttled writer; Write now writes one at once; Put back restores the old
 * value. Pictures are picked in the row: click to use or leave out, the star
 * makes one the featured picture; the pick is saved as you click, and Accept
 * or Write now imports exactly that.
 */
import { useCallback, useEffect, useRef, useState } from "react";
import { ApiError } from "./api";
import {
  effectivePick, EMPTY_ENRICH, ENRICH_STATUS, ENRICH_VIEWS, enrichActions, enrichCount, enrichQuery, enrichSources, makeFeatured, pickable, sourceShort, togglePick, writeResult,
  type EnrichAction, type EnrichFilter, type EnrichKind, type EnrichPage, type EnrichRow, type EnrichSummary,
} from "./enrichApi";
import { rest, type TitleRulesConfig } from "./titleRulesApi";
import { Dashicon, Section, SortTh, useColumnResize } from "./ui";
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

  const msg = (e: unknown) => (e instanceof ApiError ? e.message : String(e));
  const loadSummary = useCallback(() => { rest<EnrichSummary>(cfg, "GET", "enrich/summary").then(setSummary).catch(() => {}); }, [cfg]);
  const load = useCallback(async (f: EnrichFilter) => {
    const mine = ++seq.current;
    setLoading(true);
    try {
      const d = await rest<EnrichPage>(cfg, "GET", `enrich?${enrichQuery(f)}`);
      if (mine === seq.current) setPage(d);
    } catch (e) {
      if (mine === seq.current) { setPage({ rows: [], total: 0, pages: 1 }); setNotice({ text: msg(e), kind: "error" }); }
    } finally { if (mine === seq.current) setLoading(false); }
  }, [cfg]);
  useEffect(() => { loadSummary(); }, [loadSummary]);
  useEffect(() => { void load(filter); setSelected([]); setRowNotice(null); setPicks({}); }, [filter, load]);
  const refresh = () => { void load(filter); loadSummary(); };

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

  const pictures = (row: EnrichRow) => {
    const can = pickable(row);
    const chosen = shown(row);
    const tiles = [...row.own.map((o) => ({ token: o.token, url: o.url, own: true })), ...row.images.map((u) => ({ token: u, url: u, own: false }))];
    return (
      <div className="mmi-wb-en-pics">
        {tiles.map((p) => {
          const i = chosen.indexOf(p.token);
          const low = row.low_res.includes(p.token);
          return (
            <div key={p.token} className={`mmi-wb-en-pic${i === 0 ? " is-featured" : i > 0 ? " is-gallery" : " is-out"}${low ? " is-low-res" : ""}`} data-token={p.token}>
              <button type="button" className="mmi-wb-en-pic-img" disabled={!can || busy !== null}
                aria-pressed={i >= 0} aria-label={`${i >= 0 ? "Leave out" : "Use"} ${p.own ? "the product's picture" : "this picture"}`} onClick={() => pick(row, togglePick(chosen, p.token))}>
                <img src={p.url} alt="" loading="lazy" />
              </button>
              {i >= 0 && <span className="mmi-wb-en-pic-badge">{i === 0 ? "Featured" : i}</span>}
              {p.own && <span className="mmi-wb-en-pic-own">now</span>}
              {low && <span className="mmi-wb-en-pic-low" title="Smaller than the picture standard">small</span>}
              {can && i !== 0 && (
                <button type="button" className="button-link mmi-wb-en-star" aria-label="Make this the featured picture" disabled={busy !== null} onClick={() => pick(row, makeFeatured(chosen, p.token))}><Dashicon name="star-empty" /></button>
              )}
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
        <table ref={tableRef} className={`wp-list-table widefat striped mmi-wb-pr-table${loading ? " mmi-wb-loading" : ""}`} id="mmi-wb-en-table">
          <thead>
            <tr>
              <td className="check-column"><input type="checkbox" aria-label="Tick every row on this page" checked={allTicked} onChange={(e) => setSelected(e.target.checked ? rows.map((r) => r.id) : [])} /></td>
              {COLS.map((c) => (
                <SortTh key={c.key} col={c.key} label={c.label} title="Sorts this page" active={sort.key === c.key} desc={sort.dir === "desc"} onSort={sortBy} />
              ))}
              <SortTh col="value" active={sort.key === "value"} desc={sort.dir === "desc"} onSort={sortBy}
                label={filter.kind === "content" ? "Description: now → proposed" : "Pictures: click to use or leave out"}
                title={filter.kind === "content" ? "Sorts this page by the proposed description's length" : "Sorts this page by how many pictures are proposed"} />
              <th data-resize-col="actions"><span className="screen-reader-text">Actions</span></th>
            </tr>
          </thead>
          <tbody id="mmi-wb-en-rows">
            {!page && <tr><td colSpan={7} className="mmi-wb-empty-cell">Loading proposals…</td></tr>}
            {page && !rows.length && <tr><td colSpan={7} className="mmi-wb-empty-cell">Nothing here.</td></tr>}
            {rows.map((row) => {
              const [st, sk] = ENRICH_STATUS[row.status] ?? [row.status, ""];
              const mine = rowNotice && rowNotice.id === row.id ? rowNotice : null;
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
                    {mine && <div className={`notice notice-${mine.kind === "error" ? "error" : "success"} inline`} role="status"><p>{mine.text}</p></div>}
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
