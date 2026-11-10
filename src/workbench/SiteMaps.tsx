/**
 * Site maps in the Workbench (Product Data's VendorMaps, SiteGaps, FillRun): each maker site's map, what its pages
 * would add to its products, and the Fill that writes it. Open a site: whether its pages can be read (with the one
 * fix for each state), every product against its page, part by part, the Fill button with a review of the products
 * it writes to (each can be left out), and what the last Fills wrote, with Undo. Parts themselves are picked on the
 * maker's live page in the Chrome extension.
 */
import { useCallback, useEffect, useRef, useState } from "react";
import { landing } from "./landing";
import {
  adds, CELL_ACTION_LABEL, cellActions, CODES, FIELDS, fillDone, fillLabel, fillRows, gainOf, gainProducts, idsWith, LEGEND, SITE_VIEWS, siteViewCount, sitesIn, STATUS,
  PART_LABEL, proposalHosts, type CellAction, type FillRunRow, type Proposal, type Gaps, type TextSkips, type MapsReply, type Reading, type SiteRow, type SiteView,
} from "./mapsApi";
import { msg, type Notice } from "./pageRows";
import { ProductPictures, SitePicturesPanel } from "./SitePictures";
import { rest, type TitleRulesConfig } from "./titleRulesApi";
import { Dashicon, Section } from "./ui";

const POLL_MS = 8000;
const enc = encodeURIComponent;

export function SiteMaps({ cfg }: { cfg: TitleRulesConfig }) {
  const [reply, setReply] = useState<MapsReply | null>(null);
  const [view, setView] = useState<SiteView>("gain");
  const [q, setQ] = useState("");
  const [open, setOpen] = useState<string | null>(() => landing().site || null); // a link can open a site (wb_site)
  const [notice, setNotice] = useState<Notice | null>(null);
  const [proposals, setProposals] = useState<Proposal[]>([]);
  const loadProposals = useCallback(() => { rest<{ rows: Proposal[] }>(cfg, "GET", "map-proposals").then((d) => setProposals(d.rows)).catch(() => {}); }, [cfg]);
  useEffect(() => { loadProposals(); }, [loadProposals]);
  const withProposals = proposalHosts(proposals);
  const [onlyProposals, setOnlyProposals] = useState(false);
  const loadList = useCallback(() => {
    rest<MapsReply>(cfg, "GET", "maps").then(setReply).catch((e) => { setReply({ rows: [], runner: { running: false, waiting: 0, note: "", finished_at: "" } }); setNotice({ text: msg(e), kind: "error" }); });
  }, [cfg]);
  useEffect(() => { loadList(); }, [loadList]);
  const rows = reply ? sitesIn(reply.rows, onlyProposals ? "all" : view, q).filter((r) => !onlyProposals || withProposals.has(r.host)) : [];
  const site = reply?.rows.find((r) => r.host === open) ?? null;

  return (
    <Section id="mmi-wb-site-maps" icon="location-alt" title="Site maps"
      desc="Where each maker site keeps pictures and text, what its pages would add to its products, and the Fill that writes it. Parts are picked on the maker's live page in the Chrome extension">
      {notice && <div className="notice notice-error inline" role="status"><p>{notice.text}</p></div>}
      <div className="mmi-filter-pills mmi-wb-pr-views" role="group" aria-label="Sites">
        {SITE_VIEWS.map((v) => (
          <button key={v.value} type="button" className={`mmi-pill${!onlyProposals && view === v.value ? " active" : ""}`} aria-pressed={!onlyProposals && view === v.value} data-sview={v.value} title={v.hint || undefined} onClick={() => { setOnlyProposals(false); setView(v.value); }}>
            {v.label} <span className="mmi-pill-count">{reply ? siteViewCount(reply.rows, v.value) : "…"}</span>
          </button>
        ))}
        <button type="button" className={`mmi-pill${onlyProposals ? " active" : ""}`} aria-pressed={onlyProposals} data-sview="proposals" title="Parts found on the site's pages, waiting for a person's approval" onClick={() => setOnlyProposals(true)}>
          Proposed maps <span className="mmi-pill-count">{withProposals.size}</span>
        </button>
      </div>
      <div className="mmi-wb-filters mmi-wb-pr-filters">
        <input type="search" id="mmi-wb-sm-q" aria-label="Find sites" placeholder="Site" value={q} onChange={(e) => setQ(e.target.value)} />
        <span className="mmi-wb-count" id="mmi-wb-sm-count">{reply ? `${rows.length} site${rows.length === 1 ? "" : "s"}` : ""}</span>
        {reply?.runner.running && <span className="description" role="status"><span className="mmi-loading" aria-hidden="true" /> Checking maps{reply.runner.note ? `: ${reply.runner.note}` : ""}</span>}
      </div>
      <div className="mmi-table-scroll-wrapper mmi-wb-bs-list">
        <table className="wp-list-table widefat striped mmi-wb-pr-table" id="mmi-wb-sm-table">
          <thead><tr><th>Site</th><th>Map</th><th>Products</th><th>Pages read</th><th>Gaps to fill</th></tr></thead>
          <tbody id="mmi-wb-sm-rows">
            {!reply && <tr><td colSpan={5} className="mmi-wb-empty-cell">Loading sites…</td></tr>}
            {reply && !rows.length && <tr><td colSpan={5} className="mmi-wb-empty-cell">Nothing here.</td></tr>}
            {rows.map((r) => {
              const [label, badge] = STATUS[r.status] ?? [r.status, ""];
              return (
                <tr key={r.host} data-host={r.host} className={open === r.host ? "is-open" : undefined}>
                  <td><button type="button" className="button-link" aria-expanded={open === r.host} onClick={() => setOpen(open === r.host ? null : r.host)}>{r.host}</button></td>
                  <td><span className={`mmi-badge ${badge} inline`}>{label}</span></td>
                  <td>{r.stats.products}</td>
                  <td>{r.stats.read} of {r.stats.pages}{r.stats.refused ? <span className="mmi-wb-mp-warn"> · {r.stats.refused} refused</span> : null}</td>
                  <td>{gainOf(r) ? `${gainOf(r)} on ${gainProducts(r)} product${gainProducts(r) === 1 ? "" : "s"}` : <span className="description">None</span>}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      {site && <SitePanel key={site.host} cfg={cfg} site={site} proposals={proposals.filter((p) => p.host === site.host)} onChanged={() => { loadList(); loadProposals(); }} onClose={() => setOpen(null)} />}
    </Section>
  );
}

function SitePanel({ cfg, site, proposals, onChanged, onClose }: { cfg: TitleRulesConfig; site: SiteRow; proposals: Proposal[]; onChanged: () => void; onClose: () => void }) {
  const [gaps, setGaps] = useState<Gaps | null>(null);
  const [runs, setRuns] = useState<FillRunRow[] | null>(null);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [review, setReview] = useState<number[] | null>(null); // the products left in, while the Fill is reviewed
  const [reading, setReading] = useState<{ since: string; r: Reading | null } | null>(null);
  const [pics, setPics] = useState<number[]>([]); // products whose pictures are open in the review
  const [sitePics, setSitePics] = useState(false);
  const [cellPick, setCellPick] = useState<{ id: number; field: string } | null>(null);
  const [pattern, setPattern] = useState(site.pattern);
  const [render, setRender] = useState(!!site.render);
  const [text, setText] = useState<TextSkips | null>(null);
  const [addLine, setAddLine] = useState("");
  const ref = useRef<HTMLDivElement>(null);
  const host = site.host;
  useEffect(() => { ref.current?.scrollIntoView({ block: "start", behavior: "smooth" }); }, []);

  const load = useCallback(async () => {
    try {
      const [g, f] = await Promise.all([rest<Gaps>(cfg, "GET", `maps/${enc(host)}/gaps`), rest<{ runs: FillRunRow[] }>(cfg, "GET", `maps/${enc(host)}/fills`)]);
      setGaps(g);
      setRuns(f.runs);
    } catch (e) { setNotice({ text: msg(e), kind: "error" }); }
  }, [cfg, host]);
  useEffect(() => { void load(); }, [load]);
  // While pages are being checked or read, the numbers aren't final: ask again.
  useEffect(() => {
    if (!gaps || (!gaps.pending && !reading)) return;
    const t = window.setTimeout(async () => {
      if (reading) {
        try {
          const r = await rest<Reading>(cfg, "GET", `maps/${enc(host)}/reading?since=${enc(reading.since)}`);
          setReading(r.waiting || r.working ? { ...reading, r } : null);
        } catch { setReading(null); }
      }
      void load();
    }, POLL_MS);
    return () => window.clearTimeout(t);
  }, [gaps, reading, cfg, host, load]);

  const post = async (key: string, path: string, body: Record<string, unknown>, done: (d: Record<string, unknown>) => string) => {
    setBusy(key);
    setNotice(null);
    try {
      const d = await rest<Record<string, unknown>>(cfg, "POST", path, body);
      setNotice({ text: done(d), kind: "ok" });
      onChanged();
      await load();
      return d;
    } catch (e) { setNotice({ text: msg(e), kind: "error" }); return null; } finally { setBusy(null); }
  };

  const fill = async () => {
    if (!gaps || !review) return;
    const skip = fillRows(gaps).map((r) => r.id).filter((id) => !review.includes(id));
    const d = await post("fill", `maps/${enc(host)}/fill`, { fields: gaps.fill.fields, skip }, (x) => fillDone(x as { confirmed: boolean; scrape: number; pictures: number }));
    if (d) { setReview(null); setReading({ since: String(d.since ?? ""), r: null }); }
  };
  const readThem = async (ids: number[]) => {
    const d = await post("read", "pages/queue", { ids, mode: "read" }, (x) => `${x.queued ?? ids.length} page${ids.length === 1 ? "" : "s"} queued to read.`);
    if (d) setReading({ since: new Date().toISOString().slice(0, 19).replace("T", " "), r: null });
  };

  const decideCell = async (a: CellAction) => {
    if (!cellPick) return;
    const path = a === "use" || a === "keep" ? `maps/${enc(host)}/requirements` : `maps/${enc(host)}/none`;
    const body = a === "use" || a === "keep" ? { id: cellPick.id, choice: a } : { id: cellPick.id, field: cellPick.field, undo: a === "unnone" };
    const d = await post(`cell:${a}`, path, body, (x) => String(x.note || "Done."));
    if (d) setCellPick(null);
  };
  const loadText = async () => { try { setText(await rest<TextSkips>(cfg, "GET", `maps/${enc(host)}/text`)); } catch (e) { setNotice({ text: msg(e), kind: "error" }); } };
  const skip = async (add: string[], remove: string[]) => {
    setBusy("text");
    try { setText(await rest<TextSkips>(cfg, "POST", `maps/${enc(host)}/text-skip`, { add, remove })); setAddLine(""); } catch (e) { setNotice({ text: msg(e), kind: "error" }); } finally { setBusy(null); }
  };
  const scanText = async () => {
    setBusy("scan");
    try { setText(await rest<TextSkips>(cfg, "POST", `maps/${enc(host)}/text`)); } catch (e) { setNotice({ text: msg(e), kind: "error" }); } finally { setBusy(null); }
  };

  const [label, badge] = STATUS[site.status] ?? [site.status, ""];
  const plan = gaps?.fill;
  const toFill = gaps ? fillRows(gaps) : [];
  return (
    <div className="mmi-wb-bs-drawer" id="mmi-wb-sm-panel" ref={ref} data-host={host}>
      <div className="mmi-wb-bs-drawer-head">
        <h4><a href={`https://${host}`} target="_blank" rel="noreferrer">{host}</a> <span className={`mmi-badge ${badge} inline`}>{label}</span></h4>
        <span className="mmi-wb-sm-site-actions">
          <button type="button" className="button button-small" disabled={busy !== null} title="Read the site's product list again and look for pages for its products"
            onClick={() => void post("reread", `maps/${enc(host)}/reread`, {}, () => "Reading the site's products again.")}>Re-read its products</button>
          {site.status === "off" ? (
            <button type="button" className="button button-small" disabled={busy !== null} onClick={() => void post("reopen", `maps/${enc(host)}/reopen`, {}, () => "The map is back as a draft.")}>Turn the map back on</button>
          ) : site.status !== "auto" && site.status !== "refused" ? (
            <button type="button" className="button button-small" disabled={busy !== null} title="The reader then reads this site without its map" onClick={() => void post("off", `maps/${enc(host)}/off`, {}, () => "Map switched off: the site is read without it.")}>Switch the map off</button>
          ) : null}
          <button type="button" className="button button-small" onClick={onClose}><Dashicon name="no-alt" /> Close</button>
        </span>
      </div>
      {site.pattern && <p className="description">Its product pages: <code>{site.pattern}</code>{site.map_at ? ` · map changed ${site.map_at}` : ""}</p>}
      {notice && <div className={`notice notice-${notice.kind === "error" ? "error" : "success"} inline`} role="status" id="mmi-wb-sm-notice"><p>{notice.text}</p></div>}
      {!gaps && <p className="mmi-wb-empty-cell">Loading the site's products…</p>}
      {gaps && (
        <>
          {proposals.some((p) => p.status === "proposed") && (
            <div className="mmi-wb-sm-proposals" id="mmi-wb-sm-proposals">
              <h5>Proposed map parts <button type="button" className="button button-small button-primary" disabled={busy !== null}
                title="Save every proposed part as this site's map; products are written from it only by a Fill or a commit from the Chrome extension"
                onClick={() => void post("approve-all", "map-proposals/site", { host }, (d) => `${d.approved ?? 0} part${d.approved === 1 ? "" : "s"} approved.${(d.failed as string[] | undefined)?.length ? ` Not approved: ${(d.failed as string[]).join("; ")}` : ""}`)}>
                Approve all {proposals.filter((p) => p.status === "proposed").length}</button></h5>
              <p className="description">Parts found on the site's stored pages. Nothing reaches a product until a person approves a part and a Fill (or the Chrome extension) writes it. Each can be inspected on the maker's live page in the extension's mapper.</p>
              {proposals.filter((p) => p.status === "proposed").map((pr) => (
                <div key={pr.id} className="mmi-wb-sm-proposal" data-proposal={pr.id}>
                  <p><strong>{PART_LABEL[pr.field] ?? pr.field}</strong>{pr.scope !== "site" ? <span className="description"> (one page layout)</span> : null} · found on {pr.found} of {pr.of} pages · <code>{pr.sel}</code>
                    {pr.note && <span className="description"> · {pr.checked === "claude" ? "Reviewed: " : ""}{pr.note}</span>}</p>
                  <ul>{pr.samples.slice(0, 3).map((s) => (
                    <li key={s.id}><a href={s.url} target="_blank" rel="noreferrer">{s.title}</a> <span className="description">reads {s.short}</span>
                      {pr.field === "pictures" && s.urls?.length ? <span className="mmi-wb-sm-prop-pics">{s.urls.slice(0, 4).map((u) => <img key={u} src={u} alt="" loading="lazy" />)}</span> : null}</li>
                  ))}</ul>
                  <button type="button" className="button button-small button-primary" disabled={busy !== null} onClick={() => void post(`prop:${pr.id}`, `map-proposals/${pr.id}`, { decision: "approve" }, () => `${PART_LABEL[pr.field] ?? pr.field}: approved as this site's part.`)}>Approve</button>{" "}
                  <button type="button" className="button button-small" disabled={busy !== null} onClick={() => void post(`prop:${pr.id}`, `map-proposals/${pr.id}`, { decision: "reject" }, () => `${PART_LABEL[pr.field] ?? pr.field}: rejected.`)}>Reject</button>
                </div>
              ))}
            </div>
          )}
          <h5>1. Can we read their pages?</h5>
          <ul className="mmi-wb-sm-ready" id="mmi-wb-sm-ready">
            <li><strong>{gaps.ready.read}</strong> readable</li>
            {gaps.ready.unread > 0 && <li><strong>{gaps.ready.unread}</strong> not read yet <button type="button" className="button button-small" disabled={busy !== null} onClick={() => void readThem(idsWith(gaps, "unread"))}>Read them</button></li>}
            {gaps.ready.blocked > 0 && <li className="mmi-wb-mp-warn"><strong>{gaps.ready.blocked}</strong> blocked by the site: send them from your browser with the Chrome extension</li>}
            {gaps.ready.broken > 0 && <li className="mmi-wb-mp-warn"><strong>{gaps.ready.broken}</strong> gone or moved: decide their pages in Maker pages above</li>}
            {gaps.pending > 0 && <li className="description" role="status"><span className="mmi-loading" aria-hidden="true" /> {gaps.pending} page{gaps.pending === 1 ? " is" : "s are"} being checked with the map as it is now</li>}
            {reading?.r && <li className="description" role="status"><span className="mmi-loading" aria-hidden="true" /> Reading: {reading.r.done} done, {reading.r.working} now, {reading.r.waiting} waiting</li>}
          </ul>

          <h5>2. What its pages would add</h5>
          <div className="mmi-wb-filters mmi-wb-pr-filters">
            <button type="button" className="button button-primary" id="mmi-wb-sm-fill" disabled={!plan || plan.why !== "" || busy !== null || review !== null}
              title={plan?.why || undefined} onClick={() => setReview(toFill.map((r) => r.id))}>
              {plan && plan.why === "" ? fillLabel(plan) : "Fill"}
            </button>
            {plan && plan.why !== "" && <span className="description" id="mmi-wb-sm-why">{plan.why}</span>}
          </div>
          {review && plan && (
            <div className="mmi-wb-sm-review" id="mmi-wb-sm-review">
              <p>{plan.confirm ? "Filling confirms this site's draft map first. " : ""}Each product below gets what its page adds; untick one to leave it out. Writers only fill empty or thin fields, and every write can be undone.</p>
              {toFill.some((r) => adds(r).includes("Pictures")) && (
                <p><button type="button" className="button button-small" id="mmi-wb-sm-site-pics" aria-expanded={sitePics} onClick={() => setSitePics((v) => !v)}>
                  {sitePics ? "Hide the pictures across these products" : "Review the pictures across these products"}
                </button></p>
              )}
              {sitePics && <SitePicturesPanel cfg={cfg} host={host} ids={toFill.map((r) => r.id)} />}
              <ul>
                {toFill.map((r) => (
                  <li key={r.id} data-id={r.id}>
                    <label>
                      <input type="checkbox" checked={review.includes(r.id)} onChange={(e) => setReview((v) => (v ? (e.target.checked ? [...v, r.id] : v.filter((x) => x !== r.id)) : v))} />
                      {" "}{r.title} <span className="description">gets {adds(r).join(", ")}</span>
                    </label>
                    {adds(r).includes("Pictures") && (
                      <>
                        {" "}<button type="button" className="button-link" aria-expanded={pics.includes(r.id)} data-pics={r.id}
                          onClick={() => setPics((v) => (v.includes(r.id) ? v.filter((x) => x !== r.id) : [...v, r.id]))}>{pics.includes(r.id) ? "Hide pictures" : "Pictures"}</button>
                        {pics.includes(r.id) && <ProductPictures cfg={cfg} host={host} id={r.id} title={r.title} />}
                      </>
                    )}
                  </li>
                ))}
              </ul>
              <button type="button" className="button button-primary" id="mmi-wb-sm-fill-go" disabled={!review.length || busy !== null} onClick={() => void fill()}>
                {plan.confirm ? "Confirm and fill" : "Fill"} {review.length} product{review.length === 1 ? "" : "s"}
              </button>{" "}
              <button type="button" className="button" onClick={() => setReview(null)}>Cancel</button>
            </div>
          )}
          <p className="mmi-wb-sm-legend">{LEGEND.map((c) => <span key={c} title={CODES[c]![2]}><b className={`mmi-wb-sm-c is-${c === "-" ? "na" : c}`}>{CODES[c]![0]}</b> {CODES[c]![1]}</span>)}</p>
          <div className="mmi-table-scroll-wrapper mmi-wb-sm-matrix">
            <table className="widefat striped" id="mmi-wb-sm-gaps">
              <thead><tr><th>Product</th>{FIELDS.map((f) => <th key={f.key} title={Object.entries(gaps.totals[f.key] ?? {}).map(([c, n]) => `${CODES[c]?.[1] ?? c}: ${n}`).join(", ")}>{f.label}</th>)}</tr></thead>
              <tbody>
                {gaps.rows.map((r) => (
                  <tr key={r.id} data-id={r.id}>
                    <td className="mmi-wb-sm-product"><a href={r.edit} target="_blank" rel="noreferrer">{r.title}</a>{r.url && <a className="description" href={r.url} target="_blank" rel="noreferrer"> page</a>}{r.note && <p className="description">{r.note}</p>}</td>
                    {FIELDS.map((f) => {
                      const c = r.cells[f.key];
                      const code = c?.[0] ?? "-";
                      const [glyph, name, meaning] = CODES[code] ?? [code, code, ""];
                      const tip = [name, c?.[2], c?.[3] || meaning].filter(Boolean).join(": ");
                      const can = cellActions(r, f.key).length > 0;
                      const picked = cellPick?.id === r.id && cellPick.field === f.key;
                      return (
                        <td key={f.key} className={`mmi-wb-sm-cell${picked ? " is-picked" : ""}`} data-code={code} data-field={f.key} title={tip}>
                          {can ? <button type="button" className="button-link mmi-wb-sm-cellbtn" aria-label={`${f.label} of ${r.title}: ${tip}`} aria-pressed={picked} onClick={() => setCellPick(picked ? null : { id: r.id, field: f.key })}><b className={`mmi-wb-sm-c is-${code === "-" ? "na" : code}`}>{glyph}</b></button>
                            : <b className={`mmi-wb-sm-c is-${code === "-" ? "na" : code}`}>{glyph}</b>}
                        </td>
                      );
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {cellPick && (() => {
            const r = gaps.rows.find((x) => x.id === cellPick.id);
            const c = r?.cells[cellPick.field];
            if (!r) return null;
            return (
              <div className="mmi-wb-sm-cellbar" id="mmi-wb-sm-cellbar">
                <p><strong>{r.title}</strong> · {FIELDS.find((f) => f.key === cellPick.field)?.label}: {CODES[c?.[0] ?? "-"]?.[1]}{c?.[2] ? ` (now: ${c[2]})` : ""}{c?.[3] ? `. ${c[3]}` : ""}</p>
                {cellActions(r, cellPick.field).map((a) => (
                  <button key={a} type="button" className={`button button-small${a === "use" || a === "none" ? " button-primary" : ""}`} disabled={busy !== null} onClick={() => void decideCell(a)}>{CELL_ACTION_LABEL[a]}</button>
                ))}{" "}
                <button type="button" className="button button-small" onClick={() => setCellPick(null)}>Close</button>
                {cellActions(r, cellPick.field).includes("none") && <p className="description">Said of the page: it holds for every product of the site reading that page, until taken back.</p>}
              </div>
            );
          })()}

          <details className="mmi-wb-sm-more" onToggle={(e) => { if ((e.target as HTMLDetailsElement).open && !text) void loadText(); }}>
            <summary>Text left out on this site</summary>
            <p className="description">Lines the reader leaves out of descriptions read from this site (shop text, notices, menus).</p>
            {text && (
              <>
                <ul id="mmi-wb-sm-skips">{text.skips.length ? text.skips.map((s) => <li key={s}>{s} <button type="button" className="button-link" disabled={busy !== null} onClick={() => void skip([], [s])}>Remove</button></li>) : <li className="description">None yet.</li>}</ul>
                <form onSubmit={(e) => { e.preventDefault(); if (addLine.trim()) void skip([addLine.trim()], []); }}>
                  <input type="text" id="mmi-wb-sm-skip-add" aria-label="A line to leave out" placeholder="A line to leave out" value={addLine} onChange={(e) => setAddLine(e.target.value)} />{" "}
                  <button type="submit" className="button button-small" disabled={busy !== null || !addLine.trim()}>Leave it out</button>{" "}
                  <button type="button" className="button button-small" disabled={busy !== null} title="Read the site's stored pages for lines that repeat on every page" onClick={() => void scanText()}>{busy === "scan" ? "Reading…" : "Suggest lines"}</button>
                </form>
                {text.scan && !text.scan.repeated?.length && <p className="description">No line repeats across the {text.scan.checked ?? 0} pages read.</p>}
                {!!text.scan?.repeated?.length && (
                  <ul className="mmi-wb-sm-suggest">{text.scan.repeated.filter((l) => !text.skips.includes(l.text)).slice(0, 30).map((l) => (
                    <li key={l.text}>{l.text} <span className="description">on {l.pages} pages</span> <button type="button" className="button-link" disabled={busy !== null} onClick={() => void skip([l.text], [])}>Leave it out</button></li>
                  ))}</ul>
                )}
              </>
            )}
          </details>
          <details className="mmi-wb-sm-more">
            <summary>Site settings</summary>
            <form className="mmi-wb-sm-settings" onSubmit={(e) => { e.preventDefault(); void post("settings", `maps/${enc(host)}/settings`, { pattern, render }, () => "Settings saved."); }}>
              <label htmlFor="mmi-wb-sm-pattern">Its product pages' address</label>
              <input id="mmi-wb-sm-pattern" type="text" placeholder="/products/{slug}/" value={pattern} onChange={(e) => setPattern(e.target.value)} />
              <label><input type="checkbox" checked={render} onChange={(e) => setRender(e.target.checked)} /> Read its pages in the server's browser (for pages its scripts build)</label>
              <button type="submit" className="button button-small" disabled={busy !== null}>Save</button>
            </form>
          </details>

          <h5>3. Last fills</h5>
          {!runs?.length && <p className="description">No fill yet.</p>}
          {runs?.map((run) => {
            const undoable = run.items.filter((i) => i.undoable).length;
            return (
              <div key={run.run} className="mmi-wb-sm-run" data-run={run.run}>
                <p><strong>{run.at}</strong> · {run.items.length} product{run.items.length === 1 ? "" : "s"}{" "}
                  {undoable > 0 && (
                    <button type="button" className="button button-small" disabled={busy !== null} title="Put back what this Fill wrote, where it is still as the Fill left it"
                      onClick={() => { if (window.confirm(`Undo this Fill on ${undoable} product${undoable === 1 ? "" : "s"}? Only what is still as the Fill left it goes back.`)) void post(`undo:${run.run}`, `maps/${enc(host)}/fills/undo`, { run: run.run }, (d) => `Undone on ${d.undone ?? 0} product${d.undone === 1 ? "" : "s"}${d.kept ? `; ${d.kept} kept (changed since)` : ""}.`); }}>Undo</button>
                  )}
                </p>
                <ul>{run.items.slice(0, 50).map((i) => <li key={i.id}><a href={i.edit} target="_blank" rel="noreferrer">{i.title}</a> <span className="description">{i.state}{i.wrote.length ? `: ${i.wrote.join(", ")}` : ""}{i.pictures ? `; pictures ${i.pictures}` : ""}</span></li>)}</ul>
                {run.items.length > 50 && <p className="description">and {run.items.length - 50} more</p>}
              </div>
            );
          })}
        </>
      )}
    </div>
  );
}
