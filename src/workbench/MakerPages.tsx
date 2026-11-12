/**
 * Maker pages → Product pages in the Workbench (Product Data's PageList): the
 * products whose maker page needs a person's decision, decided in the row. A
 * proposed page is used or turned down, with the evidence for it on request;
 * a page that's gone, moved or isn't a product page is read again, kept,
 * replaced or cleared; "Maker doesn't publish it" stops the finder looking.
 * After an action the row is read again in place: it stays, dimmed with its
 * outcome, when it no longer belongs in the list.
 */
import { useCallback, useEffect, useRef, useState } from "react";
import {
  bulkUseText, CONF_BANDS, CONFIRM, EMPTY_PAGES, groupCount, PAGE_GROUPS, pagesQuery, queuedText, rowQuery, WAITING,
  type PageFilter, type PageListReply, type PageRow,
} from "./pagesApi";
import { QUEUED_EVENT } from "./ProductPictures";
import { msg, usePageRows, type Notice } from "./pageRows";
import { rest, type TermOption, type TitleRulesConfig } from "./titleRulesApi";
import { Section, useColumnResize } from "./ui";


export function MakerPages({ cfg }: { cfg: TitleRulesConfig }) {
  const [filter, setFilter] = useState<PageFilter>(EMPTY_PAGES);
  const [q, setQ] = useState("");
  const [reply, setReply] = useState<PageListReply | null>(null);
  const [rows, setRows] = useState<PageRow[]>([]);
  const [loading, setLoading] = useState(false);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [selected, setSelected] = useState<number[]>([]);
  const [brands, setBrands] = useState<TermOption[]>([]);
  const seq = useRef(0);

  const load = useCallback(async (f: PageFilter) => {
    const mine = ++seq.current;
    setLoading(true);
    try {
      const d = await rest<PageListReply>(cfg, "GET", `page-list?${pagesQuery(f)}`);
      if (mine !== seq.current) return;
      setReply(d);
      setRows(d.rows);
    } catch (e) {
      if (mine === seq.current) { setRows([]); setNotice({ text: msg(e), kind: "error" }); }
    } finally { if (mine === seq.current) setLoading(false); }
  }, [cfg]);
  useEffect(() => { void load(filter); setSelected([]); reset(); }, [filter, load]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { rest<{ brands: TermOption[] }>(cfg, "GET", "brands").then((d) => setBrands(d.brands)).catch(() => {}); }, [cfg]);
  // The search box narrows the list after a pause in typing.
  useEffect(() => {
    const t = window.setTimeout(() => setFilter((f) => (f.q === q ? f : { ...f, q, page: 1 })), 400);
    return () => window.clearTimeout(t);
  }, [q]);

  /** The counts again (the list itself stays as it is, rows decided in place included). */
  const recount = async () => {
    try {
      const d = await rest<PageListReply>(cfg, "GET", `page-list?${pagesQuery(filter, { rows: "0" })}`);
      setReply((r) => (r ? { ...r, counts: d.counts, conf_counts: d.conf_counts, total: d.total, pages: d.pages, reasons: d.reasons, results: d.results } : d));
    } catch { /* the counts catch up on the next load */ }
  };

  /** One product's row again: replaced when it still belongs in the list (false when it no longer does). */
  const refetch = async (id: number): Promise<boolean | null> => {
    try {
      const d = await rest<PageListReply>(cfg, "GET", `page-list?${rowQuery(filter, id)}`);
      const fresh = d.rows.find((r) => r.id === id);
      if (fresh) setRows((rs) => rs.map((r) => (r.id === id ? fresh : r)));
      return !!fresh;
    } catch {
      return null;
    }
  };
  const { state, busy, setBusy, armed, setArmed, reset, cells } = usePageRows(cfg, refetch, () => void recount());

  const bulk = async (a: "use" | "reject" | "none" | "read" | "scrape" | "pictures") => {
    const live = rows.filter((r) => selected.includes(r.id) && !state[r.id]?.gone);
    const ids = (a === "use" || a === "reject" ? live.filter((r) => r.status === "candidate" && r.proposed) : live).map((r) => r.id);
    if (!ids.length) return;
    if (a === "none" && !(armed && armed.id === "bulk" && armed.action === "none")) { setArmed({ id: "bulk", action: "none" }); return; }
    setArmed(null);
    setBusy("bulk");
    setNotice(null);
    try {
      if (a === "use" || a === "reject") {
        const d = await rest<{ done: number; skipped: number[] }>(cfg, "POST", "urls/decide", { action: a === "use" ? "approve" : "reject", ids });
        setNotice({ text: a === "use" ? bulkUseText(d) : `${d.done} proposed page${d.done === 1 ? "" : "s"} turned down.`, kind: "ok" });
      } else if (a === "none") {
        const d = await rest<{ done: number[]; kept: number[] }>(cfg, "POST", "urls/none-many", { ids });
        setNotice({ text: `${d.done.length} marked: the maker doesn't publish them.${d.kept.length ? ` ${d.kept.length} kept: their verified page reads fine, so take it away one row at a time.` : ""}`, kind: "ok" });
      } else {
        const d = await rest<{ queued: number; pictures?: unknown }>(cfg, "POST", "pages/queue", { ids, mode: a });
        setNotice({ text: queuedText(a, d.queued), kind: "ok" });
        if (a === "pictures") window.dispatchEvent(new CustomEvent(QUEUED_EVENT, { detail: d.pictures ?? null })); // Product pictures follows
      }
      setSelected([]);
      void load(filter);
    } catch (e) { setNotice({ text: msg(e), kind: "error" }); } finally { setBusy(null); }
  };

  const live = rows.filter((r) => !state[r.id]?.gone);
  const tableRef = useRef<HTMLTableElement>(null);
  useColumnResize(tableRef, rows.length > 0);
  const allTicked = live.length > 0 && live.every((r) => selected.includes(r.id));
  const tickedOpen = rows.filter((r) => selected.includes(r.id) && r.status === "candidate" && r.proposed && !state[r.id]?.gone).length;
  const showConf = filter.group === "" || WAITING.includes(filter.group as never);

  return (
    <Section id="mmi-wb-maker-pages" icon="admin-site-alt3" title="Maker pages: product pages"
      desc="Each product's page on its maker's site, where its description, pictures and requirements are read from. Decide proposed pages, fix pages that are gone, moved or aren't product pages, or say the maker doesn't publish one">
      {notice && <div className={`notice notice-${notice.kind === "error" ? "error" : "success"} inline`} role="status" id="mmi-wb-mp-notice"><p>{notice.text}</p></div>}
      <div className="mmi-filter-pills mmi-wb-pr-views" role="group" aria-label="Show">
        {PAGE_GROUPS.map((g) => (
          <button key={g.value || "waiting"} type="button" className={`mmi-pill${filter.group === g.value ? " active" : ""}`} aria-pressed={filter.group === g.value} data-group={g.value || "waiting"} title={g.hint}
            onClick={() => setFilter((f) => ({ ...f, group: g.value, conf: "", reason: "", result: "", page: 1 }))}>
            {g.label} <span className="mmi-pill-count">{groupCount(reply, g.value) ?? "…"}</span>
          </button>
        ))}
      </div>
      {showConf && (
        <div className="mmi-filter-pills mmi-wb-pr-views" role="group" aria-label="Confidence">
          {CONF_BANDS.map((b) => (
            <button key={b.value} type="button" className={`mmi-pill${filter.conf === b.value ? " active" : ""}`} aria-pressed={filter.conf === b.value} data-conf={b.value}
              onClick={() => setFilter((f) => ({ ...f, conf: f.conf === b.value ? "" : b.value, page: 1 }))}>
              {b.label} <span className="mmi-pill-count">{reply ? reply.conf_counts[b.value] ?? 0 : "…"}</span>
            </button>
          ))}
        </div>
      )}
      <div className="mmi-wb-filters mmi-wb-pr-filters">
        <input type="search" id="mmi-wb-mp-q" aria-label="Find products" placeholder="ID, SKU or words of the title" value={q} onChange={(e) => setQ(e.target.value)} />
        <select id="mmi-wb-mp-brand" aria-label="Brand" value={filter.brand} onChange={(e) => setFilter((f) => ({ ...f, brand: Number(e.target.value), page: 1 }))}>
          <option value={0}>Every brand</option>
          {brands.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
        </select>
        {!!reply?.reasons.length && (filter.group === "" || filter.group === "open") && (
          <select id="mmi-wb-mp-reason" aria-label="Why it was proposed" value={filter.reason} onChange={(e) => setFilter((f) => ({ ...f, reason: e.target.value, page: 1 }))}>
            <option value="">Every reason it was proposed</option>
            {reply.reasons.map((r) => <option key={r.key} value={r.key}>{r.label} ({r.n})</option>)}
          </select>
        )}
        {reply && (
          <select id="mmi-wb-mp-result" aria-label="Its page now" value={filter.result} onChange={(e) => setFilter((f) => ({ ...f, result: e.target.value, page: 1 }))}>
            <option value="">However its page reads now</option>
            {reply.results.filter((r) => r.n > 0 || r.key === filter.result).map((r) => <option key={r.key} value={r.key}>{r.label} ({r.n})</option>)}
          </select>
        )}
        <span className="mmi-wb-count" id="mmi-wb-mp-count">{reply ? `${reply.total} product${reply.total === 1 ? "" : "s"}` : ""}</span>
        {selected.length > 0 && (
          <span className="mmi-wb-pr-bulk">
            <span className="mmi-wb-selected">{selected.length} ticked</span>
            <button type="button" className="button button-small button-primary" id="mmi-wb-mp-use-ticked" disabled={busy !== null || !tickedOpen} title="Approve the proposed pages of the ticked products (a verified page is never replaced in bulk)" onClick={() => bulk("use")}>Use proposed URL ({tickedOpen})</button>
            <button type="button" className="button button-small" id="mmi-wb-mp-reject-ticked" disabled={busy !== null || !tickedOpen} onClick={() => bulk("reject")}>Not this page ({tickedOpen})</button>
            <button type="button" className="button button-small" id="mmi-wb-mp-read-ticked" disabled={busy !== null} title="Read each product's current page again, the page only (queued)" onClick={() => bulk("read")}>Read again</button>
            <button type="button" className="button button-small" id="mmi-wb-mp-scrape-ticked" disabled={busy !== null} title="Read each product's current page again and update its description and pictures (queued)" onClick={() => bulk("scrape")}>Scrape again</button>
            <button type="button" className="button button-small" id="mmi-wb-mp-pictures-ticked" disabled={busy !== null} title="Replace each product's pictures from its maker page, or re-save its own (queued; Product pictures shows how it went)" onClick={() => bulk("pictures")}>Pictures</button>
            <button type="button" className={`button button-small mmi-wb-mp-danger${armed?.id === "bulk" ? " is-armed" : ""}`} id="mmi-wb-mp-none-ticked" disabled={busy !== null} onClick={() => bulk("none")}>
              {armed?.id === "bulk" ? CONFIRM.none : "Maker doesn't publish them"}
            </button>
          </span>
        )}
      </div>

      <div className="mmi-table-scroll-wrapper">
        <table ref={tableRef} className={`wp-list-table widefat striped mmi-wb-pr-table${loading ? " mmi-wb-loading" : ""}`} id="mmi-wb-mp-table">
          <thead>
            <tr>
              <td className="check-column"><input type="checkbox" aria-label="Tick every row on this page" checked={allTicked} onChange={(e) => setSelected(e.target.checked ? live.map((r) => r.id) : [])} /></td>
              <th className="mmi-wb-col-id" data-resize-col="id">ID</th>
              <th data-resize-col="product">Product</th>
              <th data-resize-col="page">Maker page</th>
              <th data-resize-col="actions"><span className="screen-reader-text">Actions</span></th>
            </tr>
          </thead>
          <tbody id="mmi-wb-mp-rows">
            {!reply && <tr><td colSpan={5} className="mmi-wb-empty-cell">Loading product pages…</td></tr>}
            {reply && !rows.length && <tr><td colSpan={5} className="mmi-wb-empty-cell">Nothing here.</td></tr>}
            {rows.map((row) => {
              const st = state[row.id];
              const c = cells(row);
              return (
                <tr key={row.id} data-id={row.id} className={st?.gone ? "mmi-wb-mp-gone" : undefined}>
                  <th scope="row" className="check-column">
                    {!st?.gone && <input type="checkbox" aria-label={`Tick #${row.id}`} checked={selected.includes(row.id)}
                      onChange={(e) => setSelected((s) => (e.target.checked ? [...s, row.id] : s.filter((x) => x !== row.id)))} />}
                  </th>
                  <td className="mmi-wb-col-id"><a href={row.edit_url} target="_blank" rel="noreferrer">{row.id}</a></td>
                  <td className="mmi-wb-mp-product">
                    {row.image && <img src={row.image} alt="" loading="lazy" />}
                    <div>
                      <a href={row.view_url} target="_blank" rel="noreferrer">{row.title}</a>
                      <p className="description">{[row.brand?.name, row.sku && `SKU ${row.sku}`].filter(Boolean).join(" · ")}</p>
                    </div>
                  </td>
                  <td className="mmi-wb-mp-pages">{c.page}</td>
                  <td className="mmi-wb-job-actions mmi-wb-mp-actions">{c.actions}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      {reply && reply.pages > 1 && (
        <div className="mmi-wb-pr-pager" id="mmi-wb-mp-pager">
          <button type="button" className="button button-small" disabled={filter.page <= 1 || loading} onClick={() => setFilter((f) => ({ ...f, page: f.page - 1 }))}>‹ Previous</button>
          <span>Page {filter.page} of {reply.pages}</span>
          <button type="button" className="button button-small" disabled={filter.page >= reply.pages || loading} onClick={() => setFilter((f) => ({ ...f, page: f.page + 1 }))}>Next ›</button>
        </div>
      )}
    </Section>
  );
}
