/**
 * One product's maker page as a row: the page now and the page proposed, the actions its state offers, the address
 * editor and the evidence for a proposal. Shared by Maker pages (product pages) and the Brands & sites drawer, so a
 * row decides the same way wherever it is. The list around it owns its rows and filters; after an action it reads
 * the row again through `refetch` (true: still listed, false: no longer belongs, null: unknown).
 */
import { useState, type ReactNode } from "react";
import { ApiError } from "./api";
import { CONF_BANDS, CONFIRM, confBand, DONE, movedTo, NOTE_ICON, pageActions, pageFills, urlError, type Evidence, type PageAction, type PageRow } from "./pagesApi";
import type { Match, SitePages } from "./brandsApi";
import { rest, storedPageUrl, type TitleRulesConfig } from "./titleRulesApi";
import { Dashicon } from "./ui";

export interface Notice { text: string; kind: "ok" | "error" }
export interface RowState { notice?: Notice; gone?: boolean }

export const msg = (e: unknown): string => (e instanceof ApiError ? e.message : String(e));

export const LABEL: Record<PageAction, string> = {
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

/** onChanged: after an action, with the product and the action (Pictures to pick waits for a page read's pictures). */
export function usePageRows(cfg: TitleRulesConfig, refetch: (id: number) => Promise<boolean | null>, onChanged?: (id: number, action: PageAction) => void) {
  const [state, setState] = useState<Record<number, RowState>>({});
  const [busy, setBusy] = useState<number | "bulk" | null>(null);
  const [armed, setArmed] = useState<{ id: number | "bulk"; action: PageAction } | null>(null);
  const [editing, setEditing] = useState<{ id: number; text: string; error: string } | null>(null);
  const [evidence, setEvidence] = useState<Record<number, Evidence | "loading" | string>>({});
  /** The maker site's own product pages for the address editor (GET /brand-sites/{brand}/pages), per product. */
  const [sitePages, setSitePages] = useState<Record<number, { state: "loading" | "pending" | "ready" | "none" | "error"; host?: string; page?: number; items?: { url: string; name: string }[]; best?: Match | null; text?: string }>>({});
  const reset = () => { setState({}); setEditing(null); setArmed(null); setEvidence({}); setSitePages({}); };

  /** A large store's list is read in steps: ask again every 3 s, up to a minute, while the editor is open. */
  const loadPages = async (row: PageRow, tries = 0) => {
    if (!row.brand?.id) { setSitePages((m) => ({ ...m, [row.id]: { state: "none" } })); return; }
    if (!tries) setSitePages((m) => ({ ...m, [row.id]: { state: "loading" } }));
    try {
      const d = await rest<SitePages & { items?: { url: string; name: string }[] | null }>(cfg, "GET", `brand-sites/${row.brand.id}/pages?ids[]=${row.id}`);
      if (d.pending) {
        setSitePages((m) => ({ ...m, [row.id]: { state: "pending", host: d.host, page: d.reading?.page ?? 1 } }));
        if (tries < 20) window.setTimeout(() => void loadPages(row, tries + 1), 3000);
        return;
      }
      setSitePages((m) => ({ ...m, [row.id]: d.host ? { state: "ready", host: d.host, items: d.items ?? [], best: d.matches?.[String(row.id)] ?? null } : { state: "none" } }));
    } catch (e) { setSitePages((m) => ({ ...m, [row.id]: { state: "error", text: msg(e) } })); }
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
      const still = await refetch(row.id);
      setState((s) => ({ ...s, [row.id]: { notice: { text, kind: "ok" }, gone: still === false } }));
      onChanged?.(row.id, a);
    } catch (e) {
      if (a === "set" && editing?.id === row.id) setEditing({ ...editing, error: msg(e) });
      else setState((s) => ({ ...s, [row.id]: { notice: { text: msg(e), kind: "error" } } }));
    } finally { setBusy(null); }
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
          onClick={() => {
            if (editing?.id === row.id) { setEditing(null); return; }
            setEditing({ id: row.id, text: row.current || row.proposed || "", error: "" });
            if (!sitePages[row.id] || sitePages[row.id]!.state === "error") void loadPages(row);
          }}>
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

  /** Under the editor: the site's pages as you type, and the page that names the product best. */
  const pickLine = (row: PageRow) => {
    const sp = sitePages[row.id];
    if (!sp || sp.state === "none") return null;
    if (sp.state === "loading") return <p className="description mmi-wb-mp-pick" role="status"><span className="mmi-loading" aria-hidden="true" /> Loading the maker site's pages…</p>;
    if (sp.state === "pending") return <p className="description mmi-wb-mp-pick" role="status"><span className="mmi-loading" aria-hidden="true" /> Reading {sp.host}'s product list (page {sp.page})…</p>;
    if (sp.state === "error") return <p className="description mmi-wb-mp-pick">{sp.text}</p>;
    const best = sp.best && sp.best.url.replace(/\/+$/, "") !== row.current.replace(/\/+$/, "") ? sp.best : null;
    return (
      <p className="description mmi-wb-mp-pick">
        {best && (
          <span className="mmi-wb-mp-best">Names it best: <a href={best.url} target="_blank" rel="noreferrer">{best.name || best.url}</a> ({best.score}%{best.score < 70 && best.why ? `, ${best.why}` : ""}){" "}
            <button type="button" className="button button-small" onClick={() => setEditing({ ...editing!, text: best.url, error: "" })}>Use this page</button>{" "}
          </span>
        )}
        {sp.items?.length ? `Type to pick from the ${sp.items.length} pages on ${sp.host}, or paste an address.` : `${sp.host} publishes no product list the finder can read: paste the address.`}
      </p>
    );
  };

  const editor = (row: PageRow) => (
    <form className="mmi-wb-mp-editor" noValidate onSubmit={(e) => {
      e.preventDefault();
      const err = urlError(editing!.text, row.current);
      if (err) setEditing({ ...editing!, error: err }); else void act(row, "set", editing!.text.trim());
    }}>
      <label className="screen-reader-text" htmlFor={`mmi-wb-mp-url-${row.id}`}>The maker page's address for {row.title}</label>
      <input id={`mmi-wb-mp-url-${row.id}`} type="url" className="regular-text" placeholder="https://" value={editing!.text} autoFocus list={`mmi-wb-mp-pages-${row.id}`}
        onChange={(e) => setEditing({ ...editing!, text: e.target.value, error: "" })} onKeyDown={(e) => { if (e.key === "Escape") setEditing(null); }} />
      <datalist id={`mmi-wb-mp-pages-${row.id}`}>{(sitePages[row.id]?.items ?? []).map((i) => <option key={i.url} value={i.url} label={i.name} />)}</datalist>
      <button type="submit" className="button button-small button-primary" disabled={busy !== null}>Save</button>
      <button type="button" className="button button-small" onClick={() => setEditing(null)}>Cancel</button>
      {editing!.error ? <p className="mmi-wb-mp-error" role="alert">{editing!.error}</p> : <p className="description">Saving makes this the product's maker page and reads it now.</p>}
      {pickLine(row)}
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

  /** A row's two cells: its pages (with the editor and its outcome) and its actions. */
  const cells = (row: PageRow): { page: ReactNode; actions: ReactNode } => {
    const st = state[row.id];
    return {
      page: (
        <>
          {pageCell(row)}
          {editing?.id === row.id && !st?.gone && editor(row)}
          {st?.notice && <div className={`notice notice-${st.notice.kind === "error" ? "error" : "success"} inline`} role="status"><p>{st.notice.text}{st.gone ? " It leaves this list." : ""}</p></div>}
        </>
      ),
      actions: !st?.gone ? pageActions(row).map((a) => button(row, a)) : null,
    };
  };

  return { state, setState, busy, setBusy, armed, setArmed, reset, cells, act };
}
