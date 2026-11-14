/**
 * Map parts, a pill of Needs a decision (owner 2026-11-14): every maker site's proposed parts in one table, one
 * decision a row. A part was found on the site's stored pages (MapProposals); approving saves it as the site's (or
 * the layout's) part, and nothing reaches a product until a Fill or the Chrome extension writes it. A site's link
 * opens it in Site maps in a new browser tab, so this list keeps its place.
 */
import { useCallback, useEffect, useRef, useState } from "react";
import { layoutName, noteParts, PART_LABEL, waitingParts, waitingSites, type Proposal, type QueueSort } from "./mapsApi";
import { refreshCounts } from "./nav";
import { msg, type Notice } from "./pageRows";
import { rest, type TitleRulesConfig } from "./titleRulesApi";
import { Section, SortTh, useColumnResize } from "./ui";

const plural = (n: number, one: string, many = `${one}s`) => `${n.toLocaleString("en-US")} ${n === 1 ? one : many}`;

/** The site's Site maps view, in this Workbench (a new browser tab opens it). */
function siteHref(host: string): string {
  const here = new URL(window.location.href);
  const u = new URL(here.pathname, here.origin);
  for (const k of ["page", "pipeline_tab"]) { const v = here.searchParams.get(k); if (v) u.searchParams.set(k, v); }
  u.searchParams.set("wb_sec", "maps");
  u.searchParams.set("wb_site", host);
  return u.toString();
}

export function MapParts({ cfg }: { cfg: TitleRulesConfig }) {
  const [rows, setRows] = useState<Proposal[] | null>(null);
  const [host, setHost] = useState("");
  const [part, setPart] = useState("");
  const [sort, setSort] = useState<{ col: QueueSort; desc: boolean } | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [notice, setNotice] = useState<Notice | null>(null);
  const load = useCallback(async () => {
    try { setRows((await rest<{ rows: Proposal[] }>(cfg, "GET", "map-proposals")).rows); } catch (e) { setRows([]); setNotice({ text: msg(e), kind: "error" }); }
  }, [cfg]);
  useEffect(() => { void load(); }, [load]);
  const list = rows ? waitingParts(rows, host, part, sort) : [];
  const sites = rows ? waitingSites(rows) : [];
  const parts = rows ? [...new Set(rows.filter((p) => p.status === "proposed").map((p) => p.field))] : [];
  const tableRef = useRef<HTMLTableElement>(null);
  useColumnResize(tableRef, list.length > 0);
  const sortBy = (col: QueueSort) => setSort((s) => (!s || s.col !== col ? { col, desc: col === "found" } : s.desc === (col === "found") ? { col, desc: !s.desc } : null));

  const decide = async (key: string, path: string, body: Record<string, unknown>, done: (d: Record<string, unknown>) => string, gone: (p: Proposal) => boolean) => {
    setBusy(key);
    setNotice(null);
    try {
      const d = await rest<Record<string, unknown>>(cfg, "POST", path, body);
      setNotice({ text: done(d), kind: "ok" });
      setRows((r) => (r ? r.map((p) => (gone(p) ? { ...p, status: "decided" } : p)) : r));
      refreshCounts();
    } catch (e) { setNotice({ text: msg(e), kind: "error" }); } finally { setBusy(null); }
  };
  const one = (p: Proposal, approve: boolean) => {
    const name = `${p.host} · ${PART_LABEL[p.field] ?? p.field}`;
    void decide(`p:${p.id}`, `map-proposals/${p.id}`, { decision: approve ? "approve" : "reject" }, () => (approve ? `${name}: approved as the site's part.` : `${name}: rejected.`), (x) => x.id === p.id);
  };
  const allOnSite = () => {
    const n = rows?.filter((p) => p.host === host && p.status === "proposed").length ?? 0;
    void decide("site", "map-proposals/site", { host }, (d) => `${host}: ${plural(Number(d.approved ?? n), "part")} approved.${(d.failed as string[] | undefined)?.length ? ` Not approved: ${(d.failed as string[]).join("; ")}` : ""}`, (x) => x.host === host);
  };
  const siteWaiting = host ? sites.find(([h]) => h === host)?.[1] ?? 0 : 0;

  return (
    <Section id="mmi-wb-map-parts" icon="location-alt" title="Map parts"
      desc="Parts found on each maker site's stored pages: where it keeps pictures, manuals, requirements and the rest. Approving one saves it as the site's part; nothing reaches a product until a Fill or the Chrome extension writes it. A site's name opens it in Site maps in a new tab"
      actions={host && siteWaiting > 0 ? <button type="button" className="button button-primary" id="mmi-wb-mq-site-all" disabled={busy !== null} onClick={allOnSite}>Approve all {siteWaiting} on {host}</button> : undefined}>
      <div className="mmi-wb-filters mmi-wb-mq-filters">
        <select id="mmi-wb-mq-site" aria-label="Site" value={host} onChange={(e) => setHost(e.target.value)}>
          <option value="">Every site ({sites.length})</option>
          {sites.map(([h, n]) => <option key={h} value={h}>{h} ({n})</option>)}
        </select>
        <select id="mmi-wb-mq-part" aria-label="Part" value={part} onChange={(e) => setPart(e.target.value)}>
          <option value="">Every part</option>
          {parts.map((f) => <option key={f} value={f}>{PART_LABEL[f] ?? f}</option>)}
        </select>
        <span className="mmi-wb-count" id="mmi-wb-mq-count">{rows ? plural(list.length, "part") : ""}</span>
      </div>
      {notice && <div className={`notice notice-${notice.kind === "error" ? "error" : "success"} inline`} role="status" id="mmi-wb-mq-notice"><p>{notice.text}</p></div>}
      <div className="mmi-table-scroll-wrapper">
        <table ref={tableRef} className="wp-list-table widefat striped" id="mmi-wb-mq-table">
          <thead>
            <tr>
              <SortTh col="id" label="ID" active={sort?.col === "id"} desc={!!sort?.desc} onSort={sortBy} className="mmi-wb-col-id" />
              <SortTh col="site" label="Site" active={sort?.col === "site"} desc={!!sort?.desc} onSort={sortBy} />
              <SortTh col="part" label="Part" active={sort?.col === "part"} desc={!!sort?.desc} onSort={sortBy} />
              <SortTh col="found" label="Found on" title="Share of the pages looked at that have it" active={sort?.col === "found"} desc={!!sort?.desc} onSort={sortBy} />
              <SortTh col="reads" label="What it reads" active={sort?.col === "reads"} desc={!!sort?.desc} onSort={sortBy} />
              <th className="mmi-wb-job-actions"><span className="screen-reader-text">Decide</span></th>
            </tr>
          </thead>
          <tbody>
            {!rows && <tr><td colSpan={6} className="mmi-wb-empty-cell">Loading the parts waiting…</td></tr>}
            {rows && !list.length && <tr><td colSpan={6} className="mmi-wb-empty-cell">{host || part ? "Nothing here." : "No map part waits on you."}</td></tr>}
            {list.map((p) => {
              const [first] = noteParts(p.note);
              const s = p.samples[0];
              return (
                <tr key={p.id} data-proposal={p.id}>
                  <td className="mmi-wb-col-id">{p.id}</td>
                  <td><a href={siteHref(p.host)} target="_blank" rel="noreferrer">{p.host} ↗</a><div className="description">{layoutName(p)}</div></td>
                  <td><strong>{PART_LABEL[p.field] ?? p.field}</strong>{p.note && <div className="description" title={p.note}>{p.checked === "claude" ? "Reviewed: " : ""}{first}</div>}</td>
                  <td>{p.found} of {p.of} pages</td>
                  <td>
                    {s ? <><a href={s.url} target="_blank" rel="noreferrer">{s.title}</a> <span className="description">reads {s.short}</span></> : <span className="description">No sample kept</span>}
                    {p.field === "pictures" && s?.urls?.length ? <div className="mmi-wb-sm-prop-pics">{s.urls.slice(0, 4).map((u) => <img key={u} src={u} alt="" loading="lazy" />)}</div> : null}
                    <div><code className="mmi-wb-sm-sel" title={p.sel}>{p.sel}</code></div>
                  </td>
                  <td className="mmi-wb-job-actions">
                    <button type="button" className="button button-small button-primary" disabled={busy !== null} onClick={() => one(p, true)}>Approve</button>{" "}
                    <button type="button" className="button button-small" disabled={busy !== null} onClick={() => one(p, false)}>Reject</button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </Section>
  );
}
