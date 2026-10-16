/**
 * The results table: server-sorted headings (every data column sorts),
 * page/row selection, and an in-row editor for a product's categories or brand.
 */
import { Fragment, useEffect, useLayoutEffect, useMemo, useRef, useState, type CSSProperties, type KeyboardEvent } from "react";
import { ApiError, api } from "./api";
import { page } from "./globals";
import { isSelected, orderTicked, termTree, type SelectionState, type TreeTerm } from "./logic";
import { MESSAGES, STATUS_LABELS, STOCK_LABELS, fill } from "./messages";
import type { EditableTaxonomy, ProductRow, SearchResult, SortDir } from "./types";
import { Dashicon } from "./ui";

// Taxonomies the table edits in place (Product_Workbench::EDITABLE_TAXONOMIES).
const TERM_EDIT: Record<EditableTaxonomy, { label: string; hierarchical: boolean }> = {
  product_cat: { label: "Categories", hierarchical: true },
  product_brand: { label: "Brand", hierarchical: false },
};
const ROW_NOTICE_MS = 6000;

const COLUMNS: { col: string; label: string; className: string; title?: string }[] = [
  { col: "id", label: "ID", className: "mmi-wb-col-id" },
  { col: "", label: "Image", className: "mmi-wb-col-thumb" },
  { col: "title", label: "Product", className: "mmi-wb-col-title" },
  { col: "sku", label: "SKU", className: "mmi-wb-col-sku" },
  { col: "categories", label: "Categories", className: "mmi-wb-col-cats" },
  { col: "brand", label: "Brand", className: "mmi-wb-col-brand" },
  { col: "status", label: "Status", className: "mmi-wb-col-status" },
  { col: "stock", label: "Stock", className: "mmi-wb-col-stock" },
  { col: "supplier", label: "Supplier", className: "mmi-wb-col-supplier" },
  { col: "health", label: "Health", className: "mmi-wb-col-health", title: "Data-health checks this product fails. Sorting puts the most problems first (descending) or last" },
];
const COLSPAN = COLUMNS.length + 1;

/* ── Term editor ────────────────────────────────────────────────────────── */

// Loaded once per page per taxonomy; a failed load is forgotten so it can be retried.
const termsCache = new Map<string, Promise<TreeTerm[]>>();
function loadTerms(taxonomy: EditableTaxonomy): Promise<TreeTerm[]> {
  let p = termsCache.get(taxonomy);
  if (!p) {
    p = api.terms(taxonomy).then((d) => termTree(d.terms ?? [], TERM_EDIT[taxonomy].hierarchical));
    p.catch(() => termsCache.delete(taxonomy));
    termsCache.set(taxonomy, p);
  }
  return p;
}

function TermEditor({ row, taxonomy, onSaved, onClose }: {
  row: ProductRow; taxonomy: EditableTaxonomy; onSaved: (row: ProductRow) => void; onClose: () => void;
}) {
  const { label } = TERM_EDIT[taxonomy];
  const current = taxonomy === "product_cat" ? row.categories : row.brands;
  const [ticked, setTicked] = useState(() => new Set(current.map((t) => t.id)));
  const [initial] = useState(() => new Set(current.map((t) => t.id)));
  const [terms, setTerms] = useState<TreeTerm[] | null>(null);
  const [error, setError] = useState("");
  const [q, setQ] = useState("");
  const [saving, setSaving] = useState(false);
  const search = useRef<HTMLInputElement>(null);

  useEffect(() => {
    let live = true;
    loadTerms(taxonomy)
      .then((t) => { if (live) { setTerms(orderTicked(t, initial)); search.current?.focus(); } })
      .catch((e: ApiError) => { if (live) setError(e.message); });
    return () => { live = false; };
  }, [taxonomy, initial]);

  const save = () => {
    if (taxonomy === "product_cat" && !ticked.size) { setError(MESSAGES.TERMS_NO_CAT); return; }
    setSaving(true);
    api.setTerms(row.id, taxonomy, [...ticked])
      .then((d) => onSaved(d.row))
      .catch((e: ApiError) => setError(e.message))
      .finally(() => setSaving(false));
  };
  const onKey = (e: KeyboardEvent) => {
    if (e.key === "Escape") onClose();
    if (e.key === "Enter" && e.target === search.current) { e.preventDefault(); save(); }
  };
  const needle = q.trim().toLowerCase();
  const lower = label.toLowerCase();

  return (
    <tr className="mmi-wb-term-editor-tr" onKeyDown={onKey}>
      <td colSpan={COLSPAN}>
        <div className="mmi-wb-term-editor">
          <div className="mmi-wb-term-editor-head">
            <strong>{fill(MESSAGES.TERMS_TITLE, { label, title: row.title || `#${row.id}` })}</strong>
            <input ref={search} type="search" className="mmi-wb-term-search" value={q} onChange={(e) => setQ(e.target.value)}
              placeholder={fill(MESSAGES.TERMS_SEARCH, { label: lower })} aria-label={fill(MESSAGES.TERMS_SEARCH, { label: lower })} />
            <button type="button" className="button button-primary button-small mmi-wb-term-save" disabled={saving} onClick={save}>Save</button>
            <button type="button" className="button-link mmi-wb-term-cancel" onClick={onClose}>Cancel</button>
          </div>
          <p className="mmi-wb-term-hint">{fill(MESSAGES.TERMS_HINT, { label: lower })}</p>
          <div className="mmi-wb-term-list" role="group" aria-label={label}>
            {!terms && !error && <p className="mmi-wb-none">{MESSAGES.TERMS_LOADING}</p>}
            {terms?.map((t) => (
              <label key={t.id} className={`mmi-wb-term-option${needle && !t.path.toLowerCase().includes(needle) ? " mmi-hidden" : ""}`}
                title={t.path} style={{ "--depth": initial.has(t.id) ? 0 : t.depth } as CSSProperties}>
                <input type="checkbox" checked={ticked.has(t.id)} onChange={(e) => setTicked((s) => {
                  const n = new Set(s);
                  e.target.checked ? n.add(t.id) : n.delete(t.id);
                  return n;
                })} /> <span>{t.name}</span>
                {t.depth ? <span className="mmi-wb-term-path">{t.path.split(" › ").slice(0, -1).join(" › ")}</span> : null}
              </label>
            ))}
          </div>
          <span className="mmi-wb-term-notice" role="status">{error}</span>
        </div>
      </td>
    </tr>
  );
}

/* ── Table ──────────────────────────────────────────────────────────────── */

function TermCell({ row, taxonomy, onEdit }: { row: ProductRow; taxonomy: EditableTaxonomy; onEdit: () => void }) {
  const terms = taxonomy === "product_cat" ? row.categories : row.brands;
  const lower = TERM_EDIT[taxonomy].label.toLowerCase();
  return (
    <>
      <span className="mmi-wb-term-names">
        {!terms.length ? <span className="mmi-wb-none">—</span>
          : taxonomy === "product_cat" ? terms.map((t) => <span key={t.id} className="mmi-wb-term">{t.name}</span>)
          : terms.map((t) => t.name).join(", ")}
      </span>
      {row.locked.includes(taxonomy) && (
        <span className="dashicons dashicons-lock mmi-wb-term-lock" title={MESSAGES.TERMS_LOCKED} aria-label={MESSAGES.TERMS_LOCKED} />
      )}
      <button type="button" className="mmi-wb-edit-terms" onClick={onEdit}
        title={fill(MESSAGES.CHANGE_TERMS, { label: lower })} aria-label={fill(MESSAGES.CHANGE_TERMS, { label: lower })}>
        <Dashicon name="edit" />
      </button>
    </>
  );
}

export function ResultsTable({ result, sort, dir, loading, selection, onSort, onToggle, onHealthChip, onRowSaved }: {
  result: SearchResult | null;
  sort: string;
  dir: SortDir;
  loading: boolean;
  selection: SelectionState;
  onSort: (col: string) => void;
  onToggle: (ids: number[], on: boolean) => void;
  onHealthChip: (checkId: string) => void;
  onRowSaved: (row: ProductRow, taxonomy: EditableTaxonomy) => void;
}) {
  const table = useRef<HTMLTableElement>(null);
  const [editing, setEditing] = useState<{ id: number; taxonomy: EditableTaxonomy } | null>(null);
  const [notice, setNotice] = useState<{ id: number; text: string } | null>(null);
  const rows = result?.rows ?? [];
  const pageIds = useMemo(() => rows.map((r) => r.id), [rows]);
  const pageAll = pageIds.length > 0 && pageIds.every((id) => isSelected(selection, id));

  // The shared resizer (column widths saved per column name) attaches once the table exists.
  useLayoutEffect(() => {
    const w = page();
    if (table.current && w.jQuery && w.MMI_ColumnResizer) w.MMI_ColumnResizer.initTable(w.jQuery(table.current));
  }, []);

  useEffect(() => {
    if (!notice) return;
    const t = window.setTimeout(() => setNotice(null), ROW_NOTICE_MS);
    return () => window.clearTimeout(t);
  }, [notice]);

  // A new page of results closes an open editor.
  useEffect(() => { setEditing(null); }, [result]);

  const sortKey = (e: KeyboardEvent, col: string) => {
    if (e.key === "Enter" || e.key === " ") { e.preventDefault(); onSort(col); }
  };
  const edit = (id: number, taxonomy: EditableTaxonomy) =>
    setEditing((cur) => (cur?.id === id && cur.taxonomy === taxonomy ? null : { id, taxonomy }));

  return (
    <div className="mmi-table-scroll-wrapper mmi-wb-table-wrap">
      <table ref={table} className="wp-list-table widefat striped mmi-data-table mmi-wb-table" id="mmi-wb-table">
        <thead>
          <tr>
            <td className="check-column">
              <input type="checkbox" aria-label="Select this page" checked={pageAll} onChange={(e) => onToggle(pageIds, e.target.checked)} />
            </td>
            {COLUMNS.map((c) => c.col ? (
              <th key={c.col} data-col={c.col} data-resize-col={c.col} title={c.title} tabIndex={0}
                className={`${c.className} mmi-wb-sortable${sort === c.col ? " is-active" : ""}${sort === c.col && dir === "desc" ? " is-desc" : ""}`}
                aria-sort={sort === c.col ? (dir === "desc" ? "descending" : "ascending") : "none"}
                onClick={() => onSort(c.col)} onKeyDown={(e) => sortKey(e, c.col)}>
                {c.label} <span className="sort-icon" />
              </th>
            ) : (
              <th key="thumb" className={c.className} data-resize-col="thumb"><span className="screen-reader-text">{c.label}</span></th>
            ))}
          </tr>
        </thead>
        <tbody id="mmi-wb-rows" className={loading ? "mmi-wb-loading" : undefined}>
          {result && !rows.length && <tr><td colSpan={COLSPAN} className="mmi-wb-empty-cell">{MESSAGES.NO_MATCH}</td></tr>}
          {rows.map((r) => (
            <Fragment key={r.id}>
              <tr data-id={r.id}>
                <th scope="row" className="check-column">
                  <input type="checkbox" className="mmi-wb-row-cb" checked={isSelected(selection, r.id)}
                    aria-label={fill(MESSAGES.SELECT_ROW, { title: r.title })} onChange={(e) => onToggle([r.id], e.target.checked)} />
                </th>
                <td className="mmi-wb-col-id"><a href={r.view_url} target="_blank" rel="noopener noreferrer">{r.id}</a></td>
                <td className="mmi-wb-col-thumb">
                  {r.thumb ? <img src={r.thumb} alt="" loading="lazy" /> : <span className="mmi-wb-no-thumb" title="No featured image" />}
                </td>
                <td className="mmi-wb-col-title"><a href={r.edit_url} target="_blank" rel="noopener noreferrer">{r.title}</a></td>
                <td className="mmi-wb-col-sku">{r.sku}</td>
                <td className="mmi-wb-col-cats"><TermCell row={r} taxonomy="product_cat" onEdit={() => edit(r.id, "product_cat")} /></td>
                <td className="mmi-wb-col-brand"><TermCell row={r} taxonomy="product_brand" onEdit={() => edit(r.id, "product_brand")} /></td>
                <td className="mmi-wb-col-status">
                  <span className={`mmi-badge ${r.status === "publish" ? "success" : "warning"} inline`}>{STATUS_LABELS[r.status] ?? r.status}</span>
                </td>
                <td className="mmi-wb-col-stock">{STOCK_LABELS[r.stock] ?? r.stock}</td>
                <td className="mmi-wb-col-supplier">{r.supplier === "all" ? <span className="mmi-wb-none">—</span> : r.supplier}</td>
                <td className="mmi-wb-col-health">
                  {r.health.length ? r.health.map((id) => {
                    const c = result?.checks[id] ?? { chip: id, label: id };
                    return (
                      <button key={id} type="button" className="mmi-health-chip" title={fill(MESSAGES.HEALTH_CHIP, { label: c.label })}
                        onClick={() => onHealthChip(id)}>{c.chip}</button>
                    );
                  }) : <span className="mmi-wb-health-ok">OK</span>}
                </td>
              </tr>
              {editing?.id === r.id && (
                <TermEditor row={r} taxonomy={editing.taxonomy} onClose={() => setEditing(null)}
                  onSaved={(saved) => {
                    setEditing(null);
                    setNotice({ id: saved.id, text: fill(MESSAGES.TERMS_SAVED, { label: TERM_EDIT[editing.taxonomy].label }) });
                    onRowSaved(saved, editing.taxonomy);
                  }} />
              )}
              {notice?.id === r.id && (
                <tr className="mmi-wb-row-notice"><td colSpan={COLSPAN} role="status">{notice.text}</td></tr>
              )}
            </Fragment>
          ))}
        </tbody>
      </table>
    </div>
  );
}
