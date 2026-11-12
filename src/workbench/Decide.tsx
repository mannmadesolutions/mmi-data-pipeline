/**
 * Needs a decision: one table of every product something waits on, across the Workbench's queues (Product
 * Data's Waiting), with the product drawer beside it.
 *
 * - Chips narrow it to one queue; the search box takes a title, SKU or ID.
 * - Order: errors first (a failed picture download, no brand), then the most things waiting, then newest;
 *   every heading sorts too, and a third click goes back to that order.
 * - Clicking a row opens the drawer on it; ‹ › and J / K step through the rows on screen.
 * - A decision in the drawer redraws that row only; a row nothing waits on any more leaves the table.
 * - The Workbench opens here when something waits; when nothing does (and no link chose a section), it moves
 *   on to Find and change.
 * - A link's wb_product opens the drawer on that product.
 */
import { useCallback, useEffect, useRef, useState, type KeyboardEvent } from "react";
import { ApiError } from "./api";
import { landing } from "./landing";
import { openSection, useSectionVisible } from "./nav";
import { ProductDrawer } from "./ProductDrawer";
import { rest, type TitleRulesConfig } from "./titleRulesApi";
import { Dashicon, Section, SortTh, useColumnResize } from "./ui";
import {
  EMPTY_WAIT, nextSort, step, waitingQuery, WAIT_KINDS, type DrawerProduct, type WaitFilter, type WaitingPage, type WaitSort,
} from "./waitingApi";

const COLS: { key: Exclude<WaitSort, "">; label: string }[] = [
  { key: "id", label: "ID" },
  { key: "title", label: "Product" },
  { key: "brand", label: "Brand" },
  { key: "waiting", label: "Waiting on you" },
];

const msg = (e: unknown) => (e instanceof ApiError ? e.message : String(e));

export function Decide({ cfg }: { cfg: TitleRulesConfig }) {
  const [filter, setFilter] = useState<WaitFilter>(EMPTY_WAIT);
  const [q, setQ] = useState("");
  const [page, setPage] = useState<WaitingPage | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [open, setOpen] = useState<number | null>(() => landing().product || null);
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
  // The open product is wb_product in the address, so a reload or a shared link opens the drawer on it.
  useEffect(() => {
    if (!visible) return;
    const u = new URL(window.location.href);
    if (open === null) u.searchParams.delete("wb_product");
    else u.searchParams.set("wb_product", String(open));
    if (u.toString() !== window.location.href) window.history.replaceState(window.history.state, "", u.toString());
  }, [open, visible]);
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
      rows[i] = { ...rows[i]!, title: p.title, cats: p.cats, brands: p.brands, waiting: p.waiting };
      return { ...pg, rows };
    });
  };

  const rows = page?.rows ?? [];
  const tableRef = useRef<HTMLTableElement>(null);
  useColumnResize(tableRef, rows.length > 0);
  const sortBy = (col: Exclude<WaitSort, "">) => setFilter((f) => ({ ...f, ...nextSort(f, col), page: 1 }));
  const onRowKey = (e: KeyboardEvent, id: number) => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); setOpen(id); } };

  return (
    <Section id="mmi-wb-decide" icon="flag" title="Needs a decision"
      desc="Every product something waits on, across the queues: titles, descriptions, pictures, maker pages and data issues. Errors first. Click a product to decide everything about it in the drawer beside the list">
      <div className="mmi-toolbar mmi-wb-dc-toolbar">
        <input type="search" id="mmi-wb-dc-q" placeholder="Search titles, SKU or ID" aria-label="Search the products waiting" value={q} onChange={(e) => setQ(e.target.value)} />
        <div className="mmi-filter-pills" role="group" aria-label="What waits">
          {WAIT_KINDS.map((k) => (
            <button key={k.value || "all"} type="button" className={`mmi-pill${filter.kind === k.value ? " active" : ""}`} aria-pressed={filter.kind === k.value}
              data-kind={k.value || "all"} title={k.hint} onClick={() => setFilter((f) => ({ ...f, kind: k.value, page: 1 }))}>
              {k.label} <span className="mmi-pill-count">{page ? page.counts[k.count].toLocaleString("en-US") : "…"}</span>
            </button>
          ))}
        </div>
      </div>
      {error && <div className="notice notice-error inline"><p>{error}</p></div>}

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
                {rows.map((r) => (
                  <tr key={r.id} data-id={r.id} className={`mmi-wb-dc-row${open === r.id ? " is-open" : ""}`} tabIndex={0} aria-selected={open === r.id}
                    onClick={(e) => { if (!(e.target as HTMLElement).closest("a")) setOpen(r.id); }} onKeyDown={(e) => onRowKey(e, r.id)}>
                    <td className="mmi-wb-dc-id">{r.id}</td>
                    <td>
                      <strong className="mmi-wb-dc-title">{r.title}</strong>
                      <div className="description">{[r.cats.map((c) => c.name).join(", "), r.sku && `SKU ${r.sku}`, r.status !== "publish" && r.status].filter(Boolean).join(" · ")}</div>
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
              <span>Page {filter.page} of {page.pages} · {page.total.toLocaleString("en-US")} products</span>
              <button type="button" className="button button-small" disabled={filter.page >= page.pages || loading} onClick={() => setFilter((f) => ({ ...f, page: f.page + 1 }))}>Next ›</button>
            </div>
          )}
          {open !== null && <p className="description mmi-wb-dc-keys"><Dashicon name="info-outline" /> J and K step through the list; Escape closes the drawer.</p>}
        </div>
        {open !== null && (
          <ProductDrawer cfg={cfg} id={open} prev={step(rows, open, -1)} next={step(rows, open, 1)}
            onStep={setOpen} onClose={() => setOpen(null)} onChanged={changed} />
        )}
      </div>
    </Section>
  );
}
