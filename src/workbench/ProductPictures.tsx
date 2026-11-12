/**
 * Product pictures: what the picture runner did, with Put back per product
 * (GET /pictures, /pictures/rows; POST /pictures/{id}/revert, /pictures/stop).
 * Products are queued from Maker pages (Pictures on ticked rows), which
 * announces it with the window event QUEUED_EVENT so this section follows.
 * Polls every 15 s while the runner works or products wait; one request at a time.
 */
import { useCallback, useEffect, useRef, useState } from "react";
import { ApiError } from "./api";
import {
  folderLines, groupTable, inPlaceCount, OUTCOME, outcomeOf, releaseLine, rowsQuery, runnerLine, saved, SHOWS, size,
  type PictureRow, type PictureRows, type PictureSort, type PicturesSummary,
} from "./picturesApi";
import { rest, type TitleRulesConfig } from "./titleRulesApi";
import { useSectionVisible } from "./nav";
import { Dashicon, Section, SortTh, useColumnResize } from "./ui";

export const QUEUED_EVENT = "mmi-wb:pictures-queued";
const POLL_MS = 15000;
const msg = (e: unknown) => (e instanceof ApiError ? e.message : String(e));

/** The summary's columns; "" keeps the outcome order the summary comes in. */
type GroupSort = "" | "outcome" | "reason" | "n" | "pics" | "bb" | "ba" | "saved";
const GROUP_COLS: [Exclude<GroupSort, "">, string, boolean][] = [
  ["outcome", "Outcome", false], ["reason", "Why", false], ["n", "Products", true], ["pics", "Pictures before → after", true],
  ["bb", "Size before", true], ["ba", "Size after", true], ["saved", "Saved", true],
];
type GroupRow = ReturnType<typeof groupTable>["rows"][number];
/** A summary row's value for a column: numbers for the figures (rows that changed nothing sort below every figure). */
function groupValue(g: GroupRow, by: Exclude<GroupSort, "">): number | string {
  if (by === "outcome") return g.outcome;
  if (by === "reason") return g.reason ? g.reason_label : "";
  if (by === "n") return g.n;
  if (!g.changed) return -Infinity;
  if (by === "pics") return g.pics_after - g.pics_before;
  if (by === "bb") return g.bytes_before;
  if (by === "ba") return g.bytes_after;
  return g.bytes_before - g.bytes_after;
}

const COLS: [PictureSort, string, boolean][] = [
  ["id", "ID", false], ["title", "Product", false], ["outcome", "Outcome", false], ["reason", "Why", false],
  ["pics", "Pictures", true], ["before", "Before", true], ["after", "After", true], ["run_at", "When", false],
];

function Badge({ outcome }: { outcome: string }) {
  const [text, tone] = OUTCOME[outcome] ?? [outcome, ""];
  const busy = outcome === "working" || outcome === "revert";
  return <span className={`mmi-badge ${tone} inline`.replace("  ", " ")}>{busy && <span className="mmi-loading" aria-hidden="true" />} {text}</span>;
}

export function ProductPictures({ cfg }: { cfg: TitleRulesConfig }) {
  const [sum, setSum] = useState<PicturesSummary | null>(null);
  const [list, setList] = useState<PictureRows | null>(null);
  const [show, setShow] = useState("");
  const [page, setPage] = useState(1);
  const [sort, setSort] = useState<{ by: PictureSort; order: "asc" | "desc" }>({ by: "run_at", order: "desc" });
  const [said, setSaid] = useState<{ text: string; ok: boolean } | null>(null);
  const [rowSaid, setRowSaid] = useState<{ id: number; text: string; ok: boolean } | null>(null);
  const [busy, setBusy] = useState<number | "stop" | null>(null);
  const timer = useRef(0);
  const visible = useSectionVisible();
  const visibleRef = useRef(visible);
  const view = useRef({ show, page, sort });
  view.current = { show, page, sort };

  const loadRows = useCallback(async () => {
    const v = view.current;
    try { setList(await rest<PictureRows>(cfg, "GET", `pictures/rows?${rowsQuery(v.show, v.page, v.sort.by, v.sort.order)}`)); } catch (e) { setSaid({ text: msg(e), ok: false }); }
  }, [cfg]);

  const refresh = useCallback(async (given?: PicturesSummary) => {
    window.clearTimeout(timer.current);
    let d = given;
    try { d = d ?? await rest<PicturesSummary>(cfg, "GET", "pictures"); } catch (e) { setSaid({ text: msg(e), ok: false }); return; }
    setSum(d);
    await loadRows(); // one request at a time
    // While another section is on screen the runner isn't watched; it is looked at again when this one is back.
    if ((d.running || d.waiting) && visibleRef.current) timer.current = window.setTimeout(() => void refresh(), POLL_MS);
  }, [cfg, loadRows]);

  useEffect(() => {
    visibleRef.current = visible;
    if (visible) void refresh(); // the first look, and again on coming back
    return () => window.clearTimeout(timer.current);
  }, [refresh, visible]);
  useEffect(() => { void loadRows(); }, [show, page, sort, loadRows]);
  useEffect(() => {
    const on = (e: Event) => void refresh((e as CustomEvent<PicturesSummary | null>).detail ?? undefined);
    window.addEventListener(QUEUED_EVENT, on);
    return () => window.removeEventListener(QUEUED_EVENT, on);
  }, [refresh]);

  const stop = async () => {
    setBusy("stop");
    try {
      const d = await rest<{ removed: number; summary: PicturesSummary }>(cfg, "POST", "pictures/stop");
      setSaid({ text: `${d.removed.toLocaleString("en-US")} waiting products removed.`, ok: true });
      void refresh(d.summary);
    } catch (e) { setSaid({ text: msg(e), ok: false }); } finally { setBusy(null); }
  };

  const putBack = async (r: PictureRow) => {
    const id = Number(r.product_id);
    setBusy(id);
    try {
      const d = await rest<{ message?: string }>(cfg, "POST", `pictures/${id}/revert`);
      setRowSaid({ id, text: d.message || "Old pictures restored.", ok: true });
      void refresh();
    } catch (e) { setRowSaid({ id, text: msg(e), ok: false }); } finally { setBusy(null); }
  };

  const sortBy = (by: PictureSort) => { setSort((s) => ({ by, order: s.by === by && s.order === "desc" ? "asc" : "desc" })); setPage(1); };
  const table = sum ? groupTable(sum) : null;
  const [gSort, setGSort] = useState<{ by: GroupSort; order: "asc" | "desc" }>({ by: "", order: "asc" });
  const gSortBy = (by: Exclude<GroupSort, "">) => setGSort((s) => ({ by, order: s.by === by && s.order === "desc" ? "asc" : "desc" }));
  const groupRows = !table ? [] : gSort.by === "" ? table.rows : [...table.rows].sort((a, b) => {
    const x = groupValue(a, gSort.by as Exclude<GroupSort, "">);
    const y = groupValue(b, gSort.by as Exclude<GroupSort, "">);
    return (typeof x === "number" && typeof y === "number" ? (x === y ? 0 : x < y ? -1 : 1) : String(x).localeCompare(String(y))) * (gSort.order === "desc" ? -1 : 1);
  });
  const groupsRef = useRef<HTMLTableElement>(null);
  const listRef = useRef<HTMLTableElement>(null);
  useColumnResize(groupsRef, groupRows.length > 0);
  useColumnResize(listRef, (list?.rows.length ?? 0) > 0);
  const own = sum ? inPlaceCount(sum) : 0;
  const paused = !!(sum?.activity?.manual && sum.running);
  const pages = list ? Math.max(1, Math.ceil(list.total / (list.per_page || 50))) : 1;

  return (
    <Section id="mmi-wb-pictures" icon="format-gallery" title="Product pictures"
      desc="What the picture runner did. A product whose maker page has two or more of its own pictures gets those (800×800 JPEG on white); otherwise it keeps its own, re-saved to the same standard. Pictures a person picked are never replaced. At most 300 products change a day. Replaced pictures wait 2 days, then 14 days in quarantine before they can be deleted. Queue products with Pictures in Maker pages"
      actions={<button type="button" className="button" id="mmi-wb-px-stop" disabled={!sum?.waiting || busy !== null} onClick={() => void stop()}><Dashicon name="controls-pause" /> Stop</button>}>
      <p className="mmi-wb-px-status" id="mmi-wb-px-status" role="status" aria-live="polite">
        {sum ? <>{sum.running && !paused && <span className="mmi-loading" aria-hidden="true" />} {runnerLine(sum)}</> : "Loading…"}
      </p>
      {said && <div className={`notice notice-${said.ok ? "success" : "error"} inline`} role="status" id="mmi-wb-px-notice"><p>{said.text}</p></div>}
      {own > 0 && (
        <div className="notice notice-warning inline" id="mmi-wb-px-own"><p>
          {own.toLocaleString("en-US")} products did not get vendor pictures: their maker page had no usable pictures, so they kept their own, re-saved to the 800×800 standard (same pictures, smaller files).{" "}
          <button type="button" className="button-link" onClick={() => { setShow("in_place"); setPage(1); }}>Show them</button>
        </p></div>
      )}
      <div className="mmi-table-scroll-wrapper mmi-wb-px-groupwrap">
        <table ref={groupsRef} className="wp-list-table widefat striped mmi-wb-px-groups" id="mmi-wb-px-groups">
          <thead><tr>
            {GROUP_COLS.map(([k, label, n]) => (
              <SortTh key={k} col={k} label={label} className={n ? "mmi-wb-num" : undefined} active={gSort.by === k} desc={gSort.order === "desc"} onSort={gSortBy} />
            ))}
          </tr></thead>
          <tbody>
            {table && !table.rows.length && <tr><td colSpan={7} className="mmi-wb-empty-cell">Nothing yet. Queue products with Pictures in Maker pages.</td></tr>}
            {groupRows.map((g, i) => (
              <tr key={i} data-outcome={g.outcome}>
                <td><Badge outcome={g.outcome} /></td>
                <td>{g.reason ? g.reason_label : "—"}</td>
                <td className="mmi-wb-num">{g.n.toLocaleString("en-US")}</td>
                <td className="mmi-wb-num">{g.changed ? `${g.pics_before.toLocaleString("en-US")} → ${g.pics_after.toLocaleString("en-US")}` : "—"}</td>
                <td className="mmi-wb-num">{g.changed ? size(g.bytes_before) : "—"}</td>
                <td className="mmi-wb-num">{g.changed ? size(g.bytes_after) : "—"}</td>
                <td className="mmi-wb-num">{g.changed ? saved(g.bytes_before, g.bytes_after) : "—"}</td>
              </tr>
            ))}
          </tbody>
          {table && table.rows.length > 0 && (
            <tfoot>
              <tr className="mmi-wb-px-total"><th>Total</th><td /><td className="mmi-wb-num">{table.total.n.toLocaleString("en-US")}</td>
                <td className="mmi-wb-num">{`${table.total.pb.toLocaleString("en-US")} → ${table.total.pa.toLocaleString("en-US")}`}</td>
                <td className="mmi-wb-num">{size(table.total.bb)}</td><td className="mmi-wb-num">{size(table.total.ba)}</td><td className="mmi-wb-num">{saved(table.total.bb, table.total.ba)}</td></tr>
            </tfoot>
          )}
        </table>
      </div>
      {sum && <p className="description" id="mmi-wb-px-release">{releaseLine(sum)}</p>}
      {sum && folderLines(sum) && (
        <details className="mmi-wb-px-folders" id="mmi-wb-px-folders">
          <summary>{folderLines(sum)!.head}</summary>
          <ul>{folderLines(sum)!.items.map((t) => <li key={t}>{t}</li>)}</ul>
        </details>
      )}

      <div className="mmi-wb-filters mmi-wb-la-add">
        <select id="mmi-wb-px-show" aria-label="Show" value={show} onChange={(e) => { setShow(e.target.value); setPage(1); }}>
          {SHOWS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
        <span className="mmi-wb-count" id="mmi-wb-px-count">{list ? `${list.total.toLocaleString("en-US")} products` : ""}</span>
      </div>
      <div className="mmi-table-scroll-wrapper">
        <table ref={listRef} className="wp-list-table widefat striped" id="mmi-wb-px-table">
          <thead><tr>
            {COLS.map(([k, label, n]) => (
              <SortTh key={k} col={k} label={label} className={n ? "mmi-wb-num" : undefined} active={sort.by === k} desc={sort.order === "desc"} onSort={sortBy} />
            ))}
            <th data-resize-col="actions"><span className="screen-reader-text">Actions</span></th>
          </tr></thead>
          <tbody id="mmi-wb-px-rows">
            {!list && <tr><td colSpan={9} className="mmi-wb-empty-cell">Loading…</td></tr>}
            {list && !list.rows.length && <tr><td colSpan={9} className="mmi-wb-empty-cell">Nothing here.</td></tr>}
            {list?.rows.map((r) => {
              const id = Number(r.product_id);
              const o = outcomeOf(r.status, r.mode);
              const changed = r.status === "applied";
              const mine = rowSaid && rowSaid.id === id ? rowSaid : null;
              return (
                <tr key={id} data-id={id}>
                  <td className="mmi-wb-col-id"><a href={`post.php?post=${id}&action=edit`} target="_blank" rel="noreferrer">{id}</a></td>
                  <td className="mmi-wb-px-product">
                    {r.thumb && <img src={r.thumb} alt="" width={32} height={32} className="mmi-wb-px-thumb" />} {r.title}
                    {mine && <div className={`notice notice-${mine.ok ? "success" : "error"} inline`} role="status"><p>{mine.text}</p></div>}
                  </td>
                  <td><Badge outcome={o} /></td>
                  <td title={r.note || undefined}>{r.reason ? r.reason_label : "—"}</td>
                  <td className="mmi-wb-num">{changed || o === "reverted" ? `${r.pics_before} → ${r.pics_after}` : String(r.pics_before || "—")}</td>
                  <td className="mmi-wb-num">{Number(r.bytes_before) ? size(r.bytes_before) : "—"}</td>
                  <td className="mmi-wb-num">{Number(r.bytes_after) ? size(r.bytes_after) : "—"}</td>
                  <td className="mmi-wb-px-when">{r.run_at || "—"}</td>
                  <td className="mmi-wb-job-actions">
                    {changed && <button type="button" className="button button-small" title="Restore this product’s old pictures" disabled={busy !== null} onClick={() => void putBack(r)}><Dashicon name="backup" /> Put back</button>}
                    {r.source_url && <a href={r.source_url} target="_blank" rel="noopener noreferrer" title={r.source_url} className="mmi-wb-px-page">↗</a>}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      {pages > 1 && (
        <div className="mmi-wb-pr-pager" id="mmi-wb-px-pager">
          <button type="button" className="button button-small" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>‹ Previous</button>
          <span>Page {page} of {pages}</span>
          <button type="button" className="button button-small" disabled={page >= pages} onClick={() => setPage((p) => p + 1)}>Next ›</button>
        </div>
      )}
    </Section>
  );
}
