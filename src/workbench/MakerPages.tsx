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
import { ApiError } from "./api";
import {
  bulkUseText, CONF_BANDS, CONFIRM, confBand, DONE, EMPTY_PAGES, groupCount, movedTo, NOTE_ICON, PAGE_GROUPS, pageActions, pageFills, pagesQuery, rowQuery, urlError, WAITING,
  type Evidence, type PageAction, type PageFilter, type PageListReply, type PageRow,
} from "./pagesApi";
import { rest, storedPageUrl, type TermOption, type TitleRulesConfig } from "./titleRulesApi";
import { Dashicon, Section } from "./ui";

interface Notice { text: string; kind: "ok" | "error" }
interface RowState { notice?: Notice; gone?: boolean }

const LABEL: Record<PageAction, string> = {
  use: "Use proposed URL", reject: "Not this page", useMoved: "Use its new address", keep: "Keep this page", read: "Read again",
  scrape: "Scrape again", set: "Set the page", clear: "Clear URL", none: "Maker doesn't publish it", look: "Look for its page again", reset: "Back to review",
};
const HINT: Partial<Record<PageAction, string>> = {
  use: "Make the proposed page the product's maker page and read it now",
  reject: "Keep the product as it is; this page is never proposed again",
  useMoved: "The page now redirects to a newer address on the same site: make that its maker page and read it",
  keep: "This address is the product's page after all (a one-product maker's home page)",
  read: "Read the maker page again (the page only)",
  scrape: "Read the page again, then build the description and picture proposals",
  clear: "Remove a wrong address; the finder then looks again",
  none: "The product has no page on the maker's site: its address, proposal and page copy are cleared and the finder stops looking",
  look: "Undo \"Maker doesn't publish it\": the finder looks for its page again",
  reset: "Put this proposed page back with the pages to decide",
};

export function MakerPages({ cfg }: { cfg: TitleRulesConfig }) {
  const [filter, setFilter] = useState<PageFilter>(EMPTY_PAGES);
  const [q, setQ] = useState("");
  const [reply, setReply] = useState<PageListReply | null>(null);
  const [rows, setRows] = useState<PageRow[]>([]);
  const [loading, setLoading] = useState(false);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [state, setState] = useState<Record<number, RowState>>({});
  const [busy, setBusy] = useState<number | "bulk" | null>(null);
  const [selected, setSelected] = useState<number[]>([]);
  const [armed, setArmed] = useState<{ id: number | "bulk"; action: PageAction } | null>(null);
  const [editing, setEditing] = useState<{ id: number; text: string; error: string } | null>(null);
  const [evidence, setEvidence] = useState<Record<number, Evidence | "loading" | string>>({});
  const [brands, setBrands] = useState<TermOption[]>([]);
  const seq = useRef(0);

  const msg = (e: unknown) => (e instanceof ApiError ? e.message : String(e));
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
  useEffect(() => { void load(filter); setSelected([]); setState({}); setEditing(null); setArmed(null); }, [filter, load]);
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

  /** One product's row again: replaced when it still belongs in the list, else kept dimmed with its outcome. */
  const reread = async (id: number, done: Notice) => {
    try {
      const d = await rest<PageListReply>(cfg, "GET", `page-list?${rowQuery(filter, id)}`);
      const fresh = d.rows.find((r) => r.id === id);
      if (fresh) setRows((rs) => rs.map((r) => (r.id === id ? fresh : r)));
      setState((s) => ({ ...s, [id]: { notice: done, gone: !fresh } }));
    } catch {
      setState((s) => ({ ...s, [id]: { notice: done } }));
    }
  };

  const call = async (row: PageRow, a: PageAction, url = ""): Promise<string> => {
    switch (a) {
      case "use": case "reject": case "reset": {
        const d = await rest<{ done: number; skipped: number[] }>(cfg, "POST", "urls/decide", { action: a === "use" ? "approve" : a, ids: [row.id] });
        if (a === "use" && d.skipped.length) throw new ApiError("Not approved: the product has a different verified page. Set its page instead.", "server");
        return DONE[a];
      }
      case "useMoved": await rest(cfg, "POST", `urls/${row.id}/set`, { url: movedTo(row) }); return DONE[a];
      case "set": await rest(cfg, "POST", `urls/${row.id}/set`, { url }); return DONE[a];
      case "keep": await rest(cfg, "POST", `page-list/${row.id}/keep`); return DONE[a];
      case "read": case "scrape": await rest(cfg, "POST", "pages/queue", { ids: [row.id], mode: a }); return DONE[a];
      case "clear": case "none": case "look": await rest(cfg, "POST", `urls/${row.id}/${a}`); return DONE[a];
    }
  };

  const act = async (row: PageRow, a: PageAction, url = "") => {
    if (CONFIRM[a] && !(armed && armed.id === row.id && armed.action === a)) { setArmed({ id: row.id, action: a }); return; }
    setArmed(null);
    setBusy(row.id);
    try {
      const text = await call(row, a, url);
      if (a === "set") setEditing(null);
      setEvidence((m) => { const c = { ...m }; delete c[row.id]; return c; });
      await reread(row.id, { text, kind: "ok" });
      void recount();
    } catch (e) {
      if (a === "set" && editing?.id === row.id) setEditing({ ...editing, error: msg(e) });
      else setState((s) => ({ ...s, [row.id]: { notice: { text: msg(e), kind: "error" } } }));
    } finally { setBusy(null); }
  };

  const bulk = async (a: "use" | "reject" | "none" | "read") => {
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
        const d = await rest<{ queued: number }>(cfg, "POST", "pages/queue", { ids, mode: "read" });
        setNotice({ text: `${d.queued} page${d.queued === 1 ? "" : "s"} queued to read again.`, kind: "ok" });
      }
      setSelected([]);
      void load(filter);
    } catch (e) { setNotice({ text: msg(e), kind: "error" }); } finally { setBusy(null); }
  };

  const why = async (row: PageRow, again = false) => {
    if (evidence[row.id] && !again) { setEvidence((m) => { const c = { ...m }; delete c[row.id]; return c; }); return; }
    setEvidence((m) => ({ ...m, [row.id]: "loading" }));
    try {
      const d = await rest<{ rows: Record<string, Evidence | null> }>(cfg, "POST", "urls/evidence", { ids: [row.id] });
      setEvidence((m) => ({ ...m, [row.id]: d.rows[String(row.id)] ?? "Nothing is proposed for this product now." }));
    } catch (e) { setEvidence((m) => ({ ...m, [row.id]: msg(e) })); }
  };

  const look = async (row: PageRow, which: "proposed" | "current") => {
    const w = window.open("about:blank", "_blank");
    try {
      const u = await storedPageUrl(cfg, row.id, which);
      if (w) w.location.href = u; else window.open(u, "_blank");
    } catch (e) { w?.close(); setState((s) => ({ ...s, [row.id]: { notice: { text: msg(e), kind: "error" } } })); }
  };

  const button = (row: PageRow, a: PageAction) => {
    if (a === "set") {
      return (
        <button key={a} type="button" className="button button-small" disabled={busy !== null} data-action={a}
          onClick={() => setEditing(editing?.id === row.id ? null : { id: row.id, text: row.current || row.proposed || "", error: "" })}>
          <Dashicon name="edit" /> {row.current ? "Change the page" : LABEL.set}
        </button>
      );
    }
    const primary = a === "use" || a === "useMoved" || a === "keep";
    const armedHere = armed && armed.id === row.id && armed.action === a;
    return (
      <button key={a} type="button" data-action={a} title={HINT[a]} disabled={busy !== null}
        className={`button button-small${primary ? " button-primary" : ""}${CONFIRM[a] ? " mmi-wb-mp-danger" : ""}${armedHere ? " is-armed" : ""}`}
        onClick={() => act(row, a)}>
        {armedHere ? CONFIRM[a] : LABEL[a]}
      </button>
    );
  };

  const evidenceBox = (row: PageRow) => {
    const ev = evidence[row.id];
    if (!ev) return null;
    if (ev === "loading") return <div className="mmi-wb-mp-evidence" role="status"><span className="mmi-loading" aria-hidden="true" /> Reading the evidence…</div>;
    if (typeof ev === "string") {
      return (
        <div className="mmi-wb-mp-evidence" role="status">
          <p className="description">{ev}</p>
          {!ev.startsWith("Nothing is proposed") && <button type="button" className="button button-small" onClick={() => void why(row, true)}>Try again</button>}
        </div>
      );
    }
    const p = ev.proposed;
    return (
      <div className="mmi-wb-mp-evidence" data-evidence={row.id}>
        {ev.confidence && <p><span className={`mmi-badge ${CONF_BANDS.find((b) => b.value === confBand(ev.confidence!.n))?.badge ?? ""} inline`}>Confidence {ev.confidence.n}</span> {ev.reason_label}</p>}
        <ul className="mmi-wb-mp-notes">
          {ev.notes.map(([tone, text, pts], i) => (
            <li key={i} className={`is-${tone}`}><Dashicon name={NOTE_ICON[tone] ?? "marker"} /> {text}{pts ? ` (${pts > 0 ? "+" : ""}${pts})` : ""}</li>
          ))}
        </ul>
        {p && (
          <div className="mmi-wb-mp-page">
            {p.images[0] && <img src={p.images[0]} alt="" loading="lazy" />}
            <div>
              <strong>{p.title || p.host}</strong>
              <p className="description">{p.text}</p>
              <p className="description">{p.chars.toLocaleString()} characters, {p.images.length} picture{p.images.length === 1 ? "" : "s"}; ours: {ev.ours.chars.toLocaleString()} characters, {ev.ours.pictures} picture{ev.ours.pictures === 1 ? "" : "s"}.{p.stored_at ? ` Stored ${p.stored_at}.` : ""}</p>
            </div>
          </div>
        )}
      </div>
    );
  };

  const editor = (row: PageRow) => (
    <form className="mmi-wb-mp-editor" noValidate onSubmit={(e) => {
      e.preventDefault();
      const err = urlError(editing!.text, row.current);
      if (err) setEditing({ ...editing!, error: err }); else void act(row, "set", editing!.text.trim());
    }}>
      <label className="screen-reader-text" htmlFor={`mmi-wb-mp-url-${row.id}`}>The maker page's address for {row.title}</label>
      <input id={`mmi-wb-mp-url-${row.id}`} type="url" className="regular-text" placeholder="https://" value={editing!.text} autoFocus
        onChange={(e) => setEditing({ ...editing!, text: e.target.value, error: "" })} onKeyDown={(e) => { if (e.key === "Escape") setEditing(null); }} />
      <button type="submit" className="button button-small button-primary" disabled={busy !== null}>Save</button>
      <button type="button" className="button button-small" onClick={() => setEditing(null)}>Cancel</button>
      {editing!.error ? <p className="mmi-wb-mp-error" role="alert">{editing!.error}</p> : <p className="description">Saving makes this the product's maker page and reads it now.</p>}
    </form>
  );

  const pageCell = (row: PageRow) => {
    const fills = pageFills(row);
    const moved = movedTo(row);
    return (
      <>
        <div className="mmi-wb-mp-now">
          <span className="mmi-wb-mp-label">Now</span>
          {row.current ? (
            <>
              <a href={row.current} target="_blank" rel="noreferrer" className="mmi-wb-mp-url">{row.current}</a>
              {row.verified && <span className="mmi-badge success inline">verified</span>}
              <span className={`mmi-badge ${row.result_badge} inline`}>{row.result_label}</span>
              {row.page_title && row.op_group !== "open" && <span className="description"> {row.page_title}</span>}
              {(row.result === "read" || row.result === "no_copy") && <button type="button" className="button-link" onClick={() => look(row, "current")}>Stored copy</button>}
              {row.op_wrong_note && <p className="mmi-wb-mp-warn"><Dashicon name="warning" /> {row.op_wrong_note}</p>}
              {moved && <p className="description">Now redirects to <a href={moved} target="_blank" rel="noreferrer">{moved}</a></p>}
              {!moved && row.read_note && row.op_group === "broken" && <p className="description">{row.read_note}</p>}
            </>
          ) : <span className="description">{row.fetch_status === "no_page" ? "The maker doesn't publish a page for it." : "No maker page yet."}</span>}
        </div>
        {row.status === "candidate" && row.proposed && (
          <div className="mmi-wb-mp-proposed">
            <span className="mmi-wb-mp-label">Proposed</span>
            <a href={row.proposed} target="_blank" rel="noreferrer" className="mmi-wb-mp-url">{row.proposed}</a>
            {row.op_conf !== null && <span className={`mmi-badge ${CONF_BANDS.find((b) => b.value === confBand(row.op_conf))?.badge ?? ""} inline`} title="How sure this page is the product's">Confidence {row.op_conf}</span>}
            {row.page_title && <span className="description"> {row.page_title}</span>}
            <p className="description">{row.reason_label}{fills.length ? `. Would add: ${fills.join(", ")}.` : "."}</p>
            <button type="button" className="button-link" aria-expanded={!!evidence[row.id]} onClick={() => why(row)}>{evidence[row.id] ? "Hide why" : "Why?"}</button>
            {" · "}<button type="button" className="button-link" onClick={() => look(row, "proposed")}>Stored copy</button>
            {evidenceBox(row)}
          </div>
        )}
      </>
    );
  };

  const live = rows.filter((r) => !state[r.id]?.gone);
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
            <button type="button" className="button button-small" id="mmi-wb-mp-read-ticked" disabled={busy !== null} onClick={() => bulk("read")}>Read again</button>
            <button type="button" className={`button button-small mmi-wb-mp-danger${armed?.id === "bulk" ? " is-armed" : ""}`} id="mmi-wb-mp-none-ticked" disabled={busy !== null} onClick={() => bulk("none")}>
              {armed?.id === "bulk" ? CONFIRM.none : "Maker doesn't publish them"}
            </button>
          </span>
        )}
      </div>

      <div className="mmi-table-scroll-wrapper">
        <table className={`wp-list-table widefat striped mmi-wb-pr-table${loading ? " mmi-wb-loading" : ""}`} id="mmi-wb-mp-table">
          <thead>
            <tr>
              <td className="check-column"><input type="checkbox" aria-label="Tick every row on this page" checked={allTicked} onChange={(e) => setSelected(e.target.checked ? live.map((r) => r.id) : [])} /></td>
              <th className="mmi-wb-col-id">ID</th>
              <th>Product</th>
              <th>Maker page</th>
              <th><span className="screen-reader-text">Actions</span></th>
            </tr>
          </thead>
          <tbody id="mmi-wb-mp-rows">
            {!reply && <tr><td colSpan={5} className="mmi-wb-empty-cell">Loading product pages…</td></tr>}
            {reply && !rows.length && <tr><td colSpan={5} className="mmi-wb-empty-cell">Nothing here.</td></tr>}
            {rows.map((row) => {
              const st = state[row.id];
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
                  <td className="mmi-wb-mp-pages">
                    {pageCell(row)}
                    {editing?.id === row.id && !st?.gone && editor(row)}
                    {st?.notice && <div className={`notice notice-${st.notice.kind === "error" ? "error" : "success"} inline`} role="status"><p>{st.notice.text}{st.gone ? " It leaves this list." : ""}</p></div>}
                  </td>
                  <td className="mmi-wb-job-actions mmi-wb-mp-actions">{!st?.gone && pageActions(row).map((a) => button(row, a))}</td>
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
