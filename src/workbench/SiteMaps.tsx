/**
 * An open maker site in Makers (Makers.tsx; Product Data's VendorMaps, SiteGaps, FillRun): the site's map, what its
 * pages would add to its products, and the Fill that writes it. Parts themselves are picked on the maker's live page
 * in the Chrome extension. It opens listed by site, or as an open brand's Map tab.
 *
 * Layout (owner 2026-11-14, the "split view with part rows" mockup):
 * - A header with the site's actions and its Fill, a line saying what the Fill waits on, the Fill's review when it
 *   is open, then tabs (Parts, Pages, Last fills, Text left out, Settings).
 * - Parts opens on the proposed parts as batches by the reviewer's verdict (owner 2026-11-14, Makers step 3): Careful
 *   first and never approved in bulk, then Changed, Added and Checked, each a line per proposal with Approve, Reject
 *   and More (its samples and note). A site with several page layouts can show them as a grid instead, a part per
 *   row and a layout per column. Under them, one row per part (Pictures, Description, Requirements…): what the map
 *   reads it with and a bar of what it does across the site's products. A part's name opens its products, with the
 *   decisions a cell takes ("its page has none of this", the page's requirements).
 */
import { useCallback, useEffect, useRef, useState } from "react";
import {
  adds, CELL_ACTION_LABEL, cellActions, CODES, drillRows, fillDone, fillLabel, fillRows, findProducts, idsWith, layoutName, noteBody, noteParts,
  PART_LABEL, partRows, partsHint, proposalGrid, sliceAt, SLICES, sortParts, STATUS, tallyText, verdictBatches, verdictOf, VERDICTS,
  type CellAction, type DrillSort, type FillRunRow, type GapRow, type Gaps, type PartRow, type PartSort, type Proposal,
  type Reading, type SiteRow, type Tally, type TextSkips,
} from "./mapsApi";
import { msg, type Notice } from "./pageRows";
import { ProductPictures, SitePicturesPanel } from "./SitePictures";
import { rest, type TitleRulesConfig } from "./titleRulesApi";
import { SortTh, SplitTabs, useColumnResize } from "./ui";

const POLL_MS = 8000;
const enc = encodeURIComponent;
const plural = (n: number, one: string, many = `${one}s`) => `${n.toLocaleString("en-US")} ${n === 1 ? one : many}`;

export type SiteTab = "parts" | "pages" | "fills" | "text" | "settings";
export type Sort<K> = { col: K; desc: boolean } | null;
/** A heading click: a new column starts ascending (counts descending), the same one flips, a third goes back to the default order. */
export function nextSort<K extends string>(s: Sort<K>, col: K, countCol: K): Sort<K> {
  const first = col === countCol;
  if (!s || s.col !== col) return { col, desc: first };
  return s.desc === first ? { col, desc: !first } : null;
}

const TABS: { key: SiteTab; label: string }[] = [
  { key: "parts", label: "Parts" }, { key: "pages", label: "Pages" }, { key: "fills", label: "Last fills" }, { key: "text", label: "Text left out" }, { key: "settings", label: "Settings" },
];

/**
 * focus: an open brand's step (Makers, owner 2026-11-14 step 4). "map" is the map without its Fill (Parts, Pages, Text left
 * out, Settings); "fill" is the Fill with its review and the last fills. Listed by site, the panel has both.
 */
export function SitePanel({ cfg, site, proposals, tab, onTab, onChanged, focus }: {
  cfg: TitleRulesConfig; site: SiteRow; proposals: Proposal[]; tab: SiteTab; onTab: (t: SiteTab) => void; onChanged: () => void; focus?: "map" | "fill";
}) {
  const showMap = focus !== "fill";
  const showFill = focus !== "map";
  const shown: SiteTab = focus === "fill" ? "fills" : focus === "map" && tab === "fills" ? "parts" : tab;
  const [gaps, setGaps] = useState<Gaps | null>(null);
  const [runs, setRuns] = useState<FillRunRow[] | null>(null);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [review, setReview] = useState<number[] | null>(null); // the products left in, while the Fill is reviewed
  const [reading, setReading] = useState<{ since: string; r: Reading | null } | null>(null);
  const [pics, setPics] = useState<number[]>([]); // products whose pictures are open in the review
  const [sitePics, setSitePics] = useState(false);
  const [pattern, setPattern] = useState(site.pattern);
  const [render, setRender] = useState(!!site.render);
  const [text, setText] = useState<TextSkips | null>(null);
  const [addLine, setAddLine] = useState("");
  const [undoAsk, setUndoAsk] = useState<string | null>(null); // the Fill whose Undo waits for a yes, asked in place
  const ref = useRef<HTMLDivElement>(null);
  const host = site.host;
  // The site opens beside the list; when the page was scrolled past its top, bring its top into view.
  useEffect(() => { if (ref.current && ref.current.getBoundingClientRect().top < 0) ref.current.scrollIntoView({ block: "start" }); }, []);

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
  const decideCell = (id: number, field: string, a: CellAction) => {
    const path = a === "use" || a === "keep" ? `maps/${enc(host)}/requirements` : `maps/${enc(host)}/none`;
    const body = a === "use" || a === "keep" ? { id, choice: a } : { id, field, undo: a === "unnone" };
    void post(`cell:${id}:${field}:${a}`, path, body, (x) => String(x.note || "Done."));
  };
  const decideProposal = (pr: Proposal, approve: boolean) => {
    const name = PART_LABEL[pr.field] ?? pr.field;
    void post(`prop:${pr.id}`, `map-proposals/${pr.id}`, { decision: approve ? "approve" : "reject" }, () => (approve ? `${name}: approved as this site's part.` : `${name}: rejected.`));
  };
  const loadText = useCallback(async () => { try { setText(await rest<TextSkips>(cfg, "GET", `maps/${enc(host)}/text`)); } catch (e) { setNotice({ text: msg(e), kind: "error" }); } }, [cfg, host]);
  useEffect(() => { if (shown === "text" && !text) void loadText(); }, [shown, text, loadText]);
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
  const waiting = proposals.filter((p) => p.status === "proposed");
  const pagesAttention = gaps ? gaps.ready.unread + gaps.ready.blocked + gaps.ready.broken : 0;
  const tabCount: Record<SiteTab, string> = {
    parts: waiting.length ? `${waiting.length} to approve` : gaps ? plural(plan?.fields ?? 0, "gap") : "",
    pages: gaps ? `${gaps.ready.read} of ${gaps.ready.read + pagesAttention}` : "",
    fills: runs ? String(runs.length) : "",
    text: text ? String(text.skips.length) : "",
    settings: "",
  };
  const tabDot: Record<SiteTab, boolean> = { parts: waiting.length > 0, pages: pagesAttention > 0, fills: false, text: false, settings: false };
  const pagesHref = (() => { const u = new URL(window.location.href); u.search = ""; ["page", "pipeline_tab"].forEach((k) => { const v = new URL(window.location.href).searchParams.get(k); if (v) u.searchParams.set(k, v); }); u.searchParams.set("wb_kind", "pages"); return u.toString(); })();

  return (
    <div className="mmi-wb-split-item" id="mmi-wb-sm-panel" ref={ref} data-host={host}>
      <div className="mmi-wb-split-head">
        <div className="mmi-wb-split-title">
          <h4><a href={`https://${host}`} target="_blank" rel="noreferrer">{host}</a> <span className={`mmi-badge ${badge} inline`}>{label}</span></h4>
          <p className="description">
            {site.pattern ? <>Its product pages: <code>{site.pattern}</code> · </> : null}
            {plural(site.stats.products, "product")} · {site.stats.read} of {plural(site.stats.pages, "page")} read{site.map_at ? ` · map changed ${site.map_at}` : ""}
          </p>
        </div>
        <div className="mmi-wb-split-actions">
          {showMap && <button type="button" className="button" disabled={busy !== null} title="Read the site's product list again and look for pages for its products"
            onClick={() => void post("reread", `maps/${enc(host)}/reread`, {}, () => "Reading the site's products again.")}>Re-read its products</button>}
          {!showMap ? null : site.status === "off" ? (
            <button type="button" className="button" disabled={busy !== null} onClick={() => void post("reopen", `maps/${enc(host)}/reopen`, {}, () => "The map is back as a draft.")}>Turn the map back on</button>
          ) : site.status !== "auto" && site.status !== "refused" ? (
            <button type="button" className="button" disabled={busy !== null} title="The reader then reads this site without its map" onClick={() => void post("off", `maps/${enc(host)}/off`, {}, () => "Map switched off: the site is read without it.")}>Switch the map off</button>
          ) : null}
          {showFill && (
            <button type="button" className="button button-primary" id="mmi-wb-sm-fill" disabled={!plan || plan.why !== "" || busy !== null || review !== null}
              title={plan?.why || "Review the products it writes to first; you can leave any out"} onClick={() => setReview(toFill.map((r) => r.id))}>
              {plan && plan.why === "" ? fillLabel(plan) : "Fill"}
            </button>
          )}
        </div>
      </div>

      {/* What the Fill waits on, said where the Fill is (not as grey text beside a grey button). */}
      <div className="mmi-wb-split-lines">
        {!gaps && <p className="mmi-wb-empty-cell">Loading the site's products…</p>}
        {showFill && plan && plan.why !== "" && <p className="mmi-wb-split-why is-blocked" id="mmi-wb-sm-why">{plan.why}</p>}
        {showMap && waiting.length > 0 && <p className="mmi-wb-split-why is-info" id="mmi-wb-sm-parts-hint">{partsHint(waiting.length)}</p>}
        {focus === "fill" && waiting.length > 0 && <p className="mmi-wb-split-why is-info" id="mmi-wb-sm-fill-waits">{plural(waiting.length, "proposed part")} {waiting.length === 1 ? "waits" : "wait"} under Map: a Fill reads with the map as it is now, so approve them first.</p>}
        {gaps && gaps.pending > 0 && <p className="description" role="status"><span className="mmi-loading" aria-hidden="true" /> {gaps.pending} page{gaps.pending === 1 ? " is" : "s are"} being checked with the map as it is now</p>}
        {reading?.r && <p className="description" role="status"><span className="mmi-loading" aria-hidden="true" /> Reading: {reading.r.done} done, {reading.r.working} now, {reading.r.waiting} waiting</p>}
      </div>
      {notice && <div className={`notice notice-${notice.kind === "error" ? "error" : "success"} inline`} role="status" id="mmi-wb-sm-notice"><p>{notice.text}</p></div>}

      {showFill && review && plan && (
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
            {plan.confirm ? "Confirm and fill" : "Fill"} {plural(review.length, "product")}
          </button>{" "}
          <button type="button" className="button" onClick={() => setReview(null)}>Cancel</button>
        </div>
      )}

      {focus !== "fill" && (
        <SplitTabs id="mmi-wb-sm" label={`${host}: what to look at`} active={shown} onTab={onTab}
          tabs={TABS.filter((t) => focus !== "map" || t.key !== "fills").map((t) => ({ ...t, count: tabCount[t.key], dot: tabDot[t.key] }))} />
      )}
      {focus === "fill" && <h5 className="mmi-wb-sm-fills-head">Last fills</h5>}

      <div className="mmi-wb-split-panel" id="mmi-wb-sm-tabpanel" role={focus === "fill" ? undefined : "tabpanel"} aria-labelledby={focus === "fill" ? undefined : `mmi-wb-sm-tab-${shown}`}>
        {shown === "parts" && gaps && (
          <PartsTab gaps={gaps} proposals={proposals} busy={busy}
            onApproveMany={(ids) => void post("approve-many", "map-proposals/site", { host, ids }, (d) => `${plural(Number(d.approved ?? 0), "part")} approved.${(d.failed as string[] | undefined)?.length ? ` Not approved: ${(d.failed as string[]).join("; ")}` : ""}`)}
            onProposal={decideProposal} onCell={decideCell} />
        )}

        {shown === "pages" && gaps && (
          <>
            <p className="description">Whether the site's product pages can be read, with the one fix for each state.</p>
            <ul className="mmi-wb-sm-ready" id="mmi-wb-sm-ready">
              <li><strong>{gaps.ready.read}</strong> readable</li>
              {gaps.ready.unread > 0 && <li><strong>{gaps.ready.unread}</strong> not read yet <button type="button" className="button button-small" disabled={busy !== null} onClick={() => void readThem(idsWith(gaps, "unread"))}>Read them</button></li>}
              {gaps.ready.blocked > 0 && <li className="mmi-wb-mp-warn"><strong>{gaps.ready.blocked}</strong> blocked by the site: send them from your browser with the Chrome extension</li>}
              {gaps.ready.broken > 0 && <li className="mmi-wb-mp-warn"><strong>{gaps.ready.broken}</strong> gone or moved: decide their pages in <a href={pagesHref} target="_blank" rel="noreferrer">Maker pages ↗</a></li>}
            </ul>
          </>
        )}

        {shown === "fills" && (
          <>
            {!runs?.length && <p className="description">No fill yet. What a Fill writes shows here, with Undo.</p>}
            {runs?.map((run) => {
              const undoable = run.items.filter((i) => i.undoable).length;
              return (
                <div key={run.run} className="mmi-wb-sm-run" data-run={run.run}>
                  <p><strong>{run.at}</strong> · {plural(run.items.length, "product")}{" "}
                    {undoable > 0 && undoAsk !== run.run && (
                      <button type="button" className="button button-small" disabled={busy !== null} title="Put back what this Fill wrote, where it is still as the Fill left it" onClick={() => setUndoAsk(run.run)}>Undo</button>
                    )}
                    {undoable > 0 && undoAsk === run.run && (
                      <span className="mmi-wb-sm-ask" role="group" aria-label="Undo this Fill?">
                        Only what is still as the Fill left it goes back.{" "}
                        <button type="button" className="button button-small button-primary" disabled={busy !== null}
                          onClick={() => { setUndoAsk(null); void post(`undo:${run.run}`, `maps/${enc(host)}/fills/undo`, { run: run.run }, (d) => `Undone on ${plural(Number(d.undone ?? 0), "product")}${d.kept ? `; ${d.kept} kept (changed since)` : ""}.`); }}>
                          Undo on {plural(undoable, "product")}
                        </button>{" "}
                        <button type="button" className="button button-small" onClick={() => setUndoAsk(null)}>Keep it</button>
                      </span>
                    )}
                  </p>
                  <ul>{run.items.slice(0, 50).map((i) => <li key={i.id}><a href={i.edit} target="_blank" rel="noreferrer">{i.title}</a> <span className="description">{i.state}{i.wrote.length ? `: ${i.wrote.join(", ")}` : ""}{i.pictures ? `; pictures ${i.pictures}` : ""}</span></li>)}</ul>
                  {run.items.length > 50 && <p className="description">and {run.items.length - 50} more</p>}
                </div>
              );
            })}
          </>
        )}

        {shown === "text" && (
          <>
            <p className="description">Lines the reader leaves out of descriptions read from this site (shop text, notices, menus).</p>
            {!text && <p className="mmi-wb-empty-cell">Loading…</p>}
            {text && (
              <>
                <ul id="mmi-wb-sm-skips">{text.skips.length ? text.skips.map((s) => <li key={s}>{s} <button type="button" className="button-link" disabled={busy !== null} onClick={() => void skip([], [s])}>Remove</button></li>) : <li className="description">None yet.</li>}</ul>
                <form className="mmi-wb-sm-skip-form" onSubmit={(e) => { e.preventDefault(); if (addLine.trim()) void skip([addLine.trim()], []); }}>
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
          </>
        )}

        {shown === "settings" && (
          <form className="mmi-wb-sm-settings" onSubmit={(e) => { e.preventDefault(); void post("settings", `maps/${enc(host)}/settings`, { pattern, render }, () => "Settings saved."); }}>
            <label htmlFor="mmi-wb-sm-pattern">Its product pages' address</label>
            <input id="mmi-wb-sm-pattern" type="text" placeholder="/products/{slug}/" value={pattern} onChange={(e) => setPattern(e.target.value)} />
            <label><input type="checkbox" checked={render} onChange={(e) => setRender(e.target.checked)} /> Read its pages in the server's browser (for pages its scripts build)</label>
            <button type="submit" className="button button-small" disabled={busy !== null}>Save</button>
          </form>
        )}
      </div>
    </div>
  );
}

/** A part's bar: its products by slice, in the legend's colors (a striped slice is still being checked). */
function Bar({ t }: { t: Tally }) {
  if (!t.applies) return <span className="description">Not measured per product</span>;
  return (
    <span className="mmi-wb-sm-bar" role="img" aria-label={tallyText(t)} title={tallyText(t)}>
      {t.slices.filter((s) => s.n > 0).map((s) => <span key={s.key} className={`mmi-wb-sm-sw is-${s.key}`} style={{ width: `${(s.n / t.applies) * 100}%` }} />)}
    </span>
  );
}

function PartsTab({ gaps, proposals, busy, onApproveMany, onProposal, onCell }: {
  gaps: Gaps; proposals: Proposal[]; busy: string | null;
  onApproveMany: (ids: number[]) => void; onProposal: (p: Proposal, approve: boolean) => void; onCell: (id: number, field: string, a: CellAction) => void;
}) {
  const [pq, setPq] = useState("");
  const [openPart, setOpenPart] = useState<string | null>(null);
  const [sort, setSort] = useState<Sort<PartSort>>(null);
  const products = findProducts(gaps.rows, pq);
  const parts = sortParts(partRows(gaps, products, proposals), sort);
  const waiting = proposals.filter((p) => p.status === "proposed").length;
  const tableRef = useRef<HTMLTableElement>(null);
  useColumnResize(tableRef, parts.length > 0);
  const sortBy = (c: PartSort) => setSort((s) => nextSort(s, c, "adds"));

  return (
    <>
      {waiting > 0 && <ProposedParts proposals={proposals} busy={busy} onApproveMany={onApproveMany} onProposal={onProposal} />}
      <div className="mmi-wb-sm-parts-bar">
        <input type="search" id="mmi-wb-sm-pq" aria-label="Find a product" placeholder="Find a product" value={pq} onChange={(e) => setPq(e.target.value)} />
        {pq && <span className="mmi-wb-count">{plural(products.length, "product")}</span>}
      </div>
      <p className="mmi-wb-sm-key" aria-label="What the bars show">{SLICES.map((s) => <span key={s.key}><i className={`mmi-wb-sm-sw is-${s.key}`} aria-hidden="true" />{s.label}</span>)}</p>
      <p className="description">One row per part: what the map reads it with, and what it does across the site's {plural(products.length, "product")}. Click a part for its products. A proposed part (above) was found on the site's stored pages; nothing reaches a product until a person approves it and a Fill (or the Chrome extension) writes it.</p>
      <div className="mmi-table-scroll-wrapper">
        <table ref={tableRef} className="wp-list-table widefat mmi-wb-sm-parts" id="mmi-wb-sm-parts">
          <thead>
            <tr>
              <SortTh col="part" label="Part" active={sort?.col === "part"} desc={!!sort?.desc} onSort={sortBy} />
              <SortTh col="map" label="Map" title="What the map reads it with: proposed first, then saved, then none" active={sort?.col === "map"} desc={!!sort?.desc} onSort={sortBy} />
              <SortTh col="adds" label={`Across ${plural(products.length, "product")}`} title="Sort by how many products its page adds to" active={sort?.col === "adds"} desc={!!sort?.desc} onSort={sortBy} />
            </tr>
          </thead>
          <tbody>
            {!parts.length && <tr><td colSpan={3} className="mmi-wb-empty-cell">No part applies to these products.</td></tr>}
            {parts.map((p) => <PartRows key={p.field} p={p} rows={products} open={openPart === p.field} busy={busy}
              onToggle={() => setOpenPart(openPart === p.field ? null : p.field)} onCell={onCell} />)}
          </tbody>
        </table>
      </div>
    </>
  );
}

/** A part's row and (open) its products. Its proposals are decided above the table, by verdict. */
function PartRows({ p, rows, open, busy, onToggle, onCell }: {
  p: PartRow; rows: GapRow[]; open: boolean; busy: string | null; onToggle: () => void;
  onCell: (id: number, field: string, a: CellAction) => void;
}) {
  const measured = p.tally.applies > 0;
  return (
    <>
      <tr data-part={p.field} className={`mmi-wb-sm-part${open ? " is-open" : ""}`}>
        <td>
          {measured
            ? <button type="button" className="button-link mmi-wb-sm-part-btn" aria-expanded={open} aria-controls={`mmi-wb-sm-drill-${p.field}`} onClick={onToggle}><strong>{p.label}</strong> <span aria-hidden="true">{open ? "▾" : "▸"}</span></button>
            : <strong>{p.label}</strong>}
        </td>
        <td>
          <div className="mmi-wb-sm-mapcell">
            {p.sel ? <code className="mmi-wb-sm-sel" title={p.sel}>{p.sel}</code> : <span className="description">{p.proposals.length ? "No part saved yet" : "Read without a part"}</span>}
            {p.broken && <span className="mmi-badge error inline">Stopped matching</span>}
            {p.proposals.length > 0 && <span className="mmi-badge warning inline">{p.proposals.length} proposed</span>}
          </div>
        </td>
        <td>
          <div className="mmi-wb-sm-across"><Bar t={p.tally} />{measured && <span className="description">{tallyText(p.tally)}</span>}</div>
        </td>
      </tr>
      {open && (
        <tr className="mmi-wb-sm-drill-row" id={`mmi-wb-sm-drill-${p.field}`}><td colSpan={3}><PartProducts p={p} rows={rows} busy={busy} onCell={onCell} /></td></tr>
      )}
    </>
  );
}

/**
 * The proposed parts, by the reviewer's verdict: a batch per verdict (Careful first, never approved in bulk), a line per
 * proposal. With several page layouts, Grid shows a part per row and a layout per column; a cell opens its proposal.
 */
function ProposedParts({ proposals, busy, onApproveMany, onProposal }: {
  proposals: Proposal[]; busy: string | null; onApproveMany: (ids: number[]) => void; onProposal: (p: Proposal, approve: boolean) => void;
}) {
  const batches = verdictBatches(proposals);
  const grid = proposalGrid(proposals);
  const [view, setView] = useState<"batches" | "grid">("batches");
  // Checked starts shut while something needs a closer look (Careful, Changed, Added): those come first; Checked is approved together.
  const [shut, setShut] = useState<Set<string>>(() => new Set(batches.some((b) => b.v.key && b.v.key !== "checked") ? ["checked"] : []));
  const [opened, setOpened] = useState<number | null>(null);
  const waiting = batches.reduce((n, b) => n + b.rows.length, 0);
  const flipShut = (k: string) => setShut((set) => { const n = new Set(set); if (n.has(k)) n.delete(k); else n.add(k); return n; });
  const picked = opened !== null ? proposals.find((p) => p.id === opened && p.status === "proposed") ?? null : null;
  return (
    <div className="mmi-wb-vp" id="mmi-wb-sm-proposed">
      <div className="mmi-wb-vp-bar">
        <strong>{plural(waiting, "proposed part")}</strong>
        <span className="mmi-wb-vp-counts">{batches.map((b) => <span key={b.v.key || "none"} className={`mmi-badge ${b.v.badge} inline`}>{b.v.key ? b.v.key[0]!.toUpperCase() + b.v.key.slice(1) : "Not reviewed"} {b.rows.length}</span>)}</span>
        {grid.layouts.length > 1 && (
          <span className="mmi-wb-mk-mode mmi-wb-vp-view" role="group" aria-label="Show the proposed parts">
            <button type="button" id="mmi-wb-vp-batches" className={`button button-small${view === "batches" ? " is-active" : ""}`} aria-pressed={view === "batches"} onClick={() => setView("batches")}>By verdict</button>
            <button type="button" id="mmi-wb-vp-grid" className={`button button-small${view === "grid" ? " is-active" : ""}`} aria-pressed={view === "grid"} onClick={() => setView("grid")}>Parts × layouts</button>
          </span>
        )}
      </div>

      {view === "batches" && (
        <div className="mmi-wb-bt-list">
          {batches.map(({ v, rows }) => {
            const key = v.key || "none";
            const closed = shut.has(key);
            return (
              <section key={key} className={`mmi-wb-bt is-v-${key}`} data-vbatch={key} aria-label={v.label}>
                <div className="mmi-wb-bt-head">
                  <span aria-hidden="true" />
                  <span className="mmi-wb-bt-count">{rows.length}</span>
                  <div className="mmi-wb-bt-why"><strong>{v.label}</strong><span className="description">{v.hint}</span></div>
                  <span className="mmi-wb-bt-acts">
                    {v.bulk && rows.length > 1 && (
                      <button type="button" className="button button-small button-primary" data-vbatch-action="approve" disabled={busy !== null}
                        title="Save these as the site's map; products are written from it only by a Fill or a commit from the Chrome extension" onClick={() => onApproveMany(rows.map((p) => p.id))}>
                        Approve the {rows.length}
                      </button>
                    )}
                    <button type="button" className="button button-small" aria-expanded={!closed} data-vbatch-action="toggle" onClick={() => flipShut(key)}>{closed ? `Show ${rows.length}` : "Hide"}</button>
                  </span>
                </div>
                {!closed && (
                  <ul className="mmi-wb-bt-rows">
                    {rows.map((pr) => (
                      <li key={pr.id} data-proposal={pr.id} className={`mmi-wb-bt-row${opened === pr.id ? " is-open" : ""}`}>
                        <div className="mmi-wb-vp-line">
                          <span className="mmi-badge inline mmi-wb-vp-layout">{layoutName(pr)}{pr.of ? ` · ${pr.of}` : ""}</span>
                          <strong className="mmi-wb-vp-part">{PART_LABEL[pr.field] ?? pr.field}</strong>
                          <code className="mmi-wb-vp-sel" title={pr.sel}>{pr.sel}</code>
                          <span className="mmi-wb-vp-found" title={`Found on ${pr.found} of ${pr.of} pages`}>{pr.found} / {pr.of}</span>
                          <span className="mmi-wb-vp-note" title={noteBody(pr.note)}>{noteBody(pr.note) || <span className="description">No note</span>}</span>
                          <span className="mmi-wb-bt-acts">
                            <button type="button" className="button button-small button-primary" data-action="approve" disabled={busy !== null} onClick={() => onProposal(pr, true)}>Approve</button>
                            <button type="button" className="button button-small" data-action="reject" disabled={busy !== null} onClick={() => onProposal(pr, false)}>Reject</button>
                            <button type="button" className="button button-small mmi-wb-bt-more" aria-expanded={opened === pr.id} onClick={() => setOpened(opened === pr.id ? null : pr.id)}>{opened === pr.id ? "Less" : "More"}</button>
                          </span>
                        </div>
                        {opened === pr.id && <div className="mmi-wb-bt-detail"><ProposalView pr={pr} busy={busy} onDecide={(a) => onProposal(pr, a)} /></div>}
                      </li>
                    ))}
                  </ul>
                )}
              </section>
            );
          })}
        </div>
      )}

      {view === "grid" && (
        <>
          <div className="mmi-wb-vgrid" role="table" aria-label="Proposed parts: a part per row, a page layout per column" id="mmi-wb-vp-table"
            style={{ gridTemplateColumns: `minmax(110px, 0.8fr) repeat(${grid.layouts.length}, minmax(0, 1fr))` }}>
            <div role="row" className="mmi-wb-vgrid-row">
              <span role="columnheader">Part</span>
              {grid.layouts.map((l) => <span key={l.layout} role="columnheader">{l.label}{l.pages ? <span className="description"> · {l.pages} pages</span> : null}</span>)}
            </div>
            {grid.parts.map((f) => (
              <div key={f} role="row" className="mmi-wb-vgrid-row" data-part={f}>
                <span role="rowheader"><strong>{PART_LABEL[f] ?? f}</strong></span>
                {grid.layouts.map((l) => {
                  const pr = grid.at(f, l.layout);
                  if (!pr) return <span key={l.layout} role="cell" className="mmi-wb-vgrid-none">–</span>;
                  const v = VERDICTS.find((d) => d.key === verdictOf(pr))!;
                  return (
                    <span key={l.layout} role="cell">
                      <button type="button" className={`mmi-wb-vgrid-cell${opened === pr.id ? " is-on" : ""}`} data-cell={`${f}:${l.layout}`} aria-pressed={opened === pr.id} onClick={() => setOpened(opened === pr.id ? null : pr.id)}>
                        <span className={`mmi-badge ${v.badge} inline`}>{v.key ? v.key[0]!.toUpperCase() + v.key.slice(1) : "Not reviewed"}</span>
                        <span className="description">{pr.found} / {pr.of}</span>
                      </button>
                    </span>
                  );
                })}
              </div>
            ))}
          </div>
          {picked && (
            <div className="mmi-wb-vgrid-open" data-open={picked.id}>
              <p className="mmi-wb-vgrid-open-title"><strong>{layoutName(picked)} · {PART_LABEL[picked.field] ?? picked.field}</strong></p>
              <ProposalView pr={picked} busy={busy} onDecide={(a) => onProposal(picked, a)} />
            </div>
          )}
        </>
      )}
    </div>
  );
}

function ProposalView({ pr, busy, onDecide }: { pr: Proposal; busy: string | null; onDecide: (approve: boolean) => void }) {
  const [first, rest] = noteParts(pr.note);
  const lead = pr.checked === "claude" ? "Reviewed: " : "";
  return (
    <div className="mmi-wb-sm-proposal" data-proposal={pr.id}>
      <div className="mmi-wb-sm-prop-head">
        <span className="mmi-badge warning inline">Proposed</span>
        <span>{layoutName(pr)} · found on {pr.found} of {pr.of} pages</span>
        <code className="mmi-wb-sm-sel" title={pr.sel}>{pr.sel}</code>
        <span className="mmi-wb-sm-prop-acts">
          <button type="button" className="button button-small button-primary" disabled={busy !== null} onClick={() => onDecide(true)}>Approve</button>
          <button type="button" className="button button-small" disabled={busy !== null} onClick={() => onDecide(false)}>Reject</button>
        </span>
      </div>
      {pr.samples.length > 0 && (
        <ul className="mmi-wb-sm-samples">{pr.samples.slice(0, 3).map((s) => (
          <li key={s.id}><a href={s.url} target="_blank" rel="noreferrer">{s.title}</a> <span className="description">reads {s.short}</span>
            {pr.field === "pictures" && s.urls?.length ? <span className="mmi-wb-sm-prop-pics">{s.urls.slice(0, 4).map((u) => <img key={u} src={u} alt="" loading="lazy" />)}</span> : null}</li>
        ))}</ul>
      )}
      {pr.note && (rest
        ? <details className="mmi-wb-sm-prop-note"><summary>{lead}{first}</summary><p>{rest}</p></details>
        : <p className="mmi-wb-sm-prop-note description">{lead}{first}</p>)}
    </div>
  );
}

/** A part's products, a slice at a time, with the decisions their cell takes. */
function PartProducts({ p, rows, busy, onCell }: { p: PartRow; rows: GapRow[]; busy: string | null; onCell: (id: number, field: string, a: CellAction) => void }) {
  const [slice, setSlice] = useState("");
  const [sort, setSort] = useState<Sort<DrillSort>>(null);
  const list = drillRows(rows, p.field, slice, sort);
  const tableRef = useRef<HTMLTableElement>(null);
  useColumnResize(tableRef, list.length > 0);
  const sortBy = (c: DrillSort) => setSort((s) => nextSort(s, c, "id"));
  const chip = (key: string, text: string, n: number) => (
    <button key={key || "all"} type="button" className={`mmi-wb-pr-chip${slice === key ? " is-active" : ""}`} aria-pressed={slice === key} data-slice={key || "all"} onClick={() => setSlice(key)}>
      {key && <i className={`mmi-wb-sm-sw is-${key}`} aria-hidden="true" />}{text} <span className="mmi-pill-count">{n}</span>
    </button>
  );
  return (
    <div className="mmi-wb-sm-drill">
      <div className="mmi-wb-pr-chips" role="group" aria-label={`${p.label}: which products`}>
        {chip("", "All", p.tally.applies)}
        {p.tally.slices.filter((s) => s.n > 0).map((s) => chip(s.key, s.label, s.n))}
      </div>
      <div className="mmi-table-scroll-wrapper">
        <table ref={tableRef} className="wp-list-table widefat striped" id={`mmi-wb-sm-part-${p.field}`}>
          <thead>
            <tr>
              <SortTh col="id" label="ID" active={sort?.col === "id"} desc={!!sort?.desc} onSort={sortBy} className="mmi-wb-col-id" />
              <SortTh col="title" label="Product" active={sort?.col === "title"} desc={!!sort?.desc} onSort={sortBy} />
              <SortTh col="state" label={p.label} title="In the bar's order" active={sort?.col === "state"} desc={!!sort?.desc} onSort={sortBy} />
              <th className="mmi-wb-job-actions"><span className="screen-reader-text">Decide</span></th>
            </tr>
          </thead>
          <tbody>
            {!list.length && <tr><td colSpan={4} className="mmi-wb-empty-cell">Nothing here.</td></tr>}
            {list.map((r) => {
              const c = r.cells[p.field];
              const code = c?.[0] ?? "-";
              const closed = sliceAt(r, p.field) === "none";
              const [glyph, name, meaning] = closed ? ["○", "Page has none", "A person said its page has none of this"] : CODES[code] ?? [code, code, ""];
              const detail = [c?.[2] ? `Now: ${c[2]}` : "", c?.[3] || meaning].filter(Boolean).join(". ");
              const acts = cellActions(r, p.field);
              return (
                <tr key={r.id} data-id={r.id}>
                  <td className="mmi-wb-col-id">{r.id}</td>
                  <td className="mmi-wb-sm-product">
                    <a href={r.edit} target="_blank" rel="noreferrer">{r.title}</a>{r.url && <> · <a className="description" href={r.url} target="_blank" rel="noreferrer">its page ↗</a></>}
                    {r.note && <p className="description">{r.note}</p>}
                  </td>
                  <td className="mmi-wb-sm-cell" data-code={code} data-field={p.field} data-slice={sliceAt(r, p.field)} title={[name, detail].filter(Boolean).join(": ")}>
                    <b className={`mmi-wb-sm-c is-${code === "-" ? "na" : code}`}>{glyph}</b> {name}
                    {detail && <div className="description">{detail}</div>}
                  </td>
                  <td className="mmi-wb-job-actions">
                    {acts.map((a) => (
                      <button key={a} type="button" className={`button button-small${a === "use" || a === "none" ? " button-primary" : ""}`} disabled={busy !== null} onClick={() => onCell(r.id, p.field, a)}>{CELL_ACTION_LABEL[a]}</button>
                    ))}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      {list.some((r) => cellActions(r, p.field).includes("none")) && <p className="description">"Its page has none of this" holds for every product of the site reading that page, until taken back.</p>}
    </div>
  );
}
