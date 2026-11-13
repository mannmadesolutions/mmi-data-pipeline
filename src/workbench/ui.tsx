/**
 * Small building blocks rendered with the suite's shared classes
 * (mmi-suite-common.css): page sections, spinner buttons, multi-select
 * filters, the shared pager, and Island, which hosts server-rendered jQuery
 * markup inside the React tree.
 */
import { useContext, useEffect, useLayoutEffect, useRef, useState, type ButtonHTMLAttributes, type KeyboardEvent as ReactKeyboardEvent, type ReactNode, type RefObject } from "react";
import { page } from "./globals";
import { SectionEmbedContext } from "./nav";
import type { Thumb } from "./waitingApi";

export const Dashicon = ({ name }: { name: string }) => <span className={`dashicons dashicons-${name}`} aria-hidden="true" />;

/**
 * A button whose idle and busy states have the same structure (icon + label),
 * so toggling never changes its height (AGENTS.md → Button Loading).
 */
export function BusyButton({ busy, icon, label, busyLabel, className = "button", ...rest }:
  { busy: boolean; icon: string; label: string; busyLabel: string } & ButtonHTMLAttributes<HTMLButtonElement>) {
  return (
    <button type="button" {...rest} className={`${className}${busy ? " mmi-is-loading" : ""}`} disabled={rest.disabled || busy}>
      {busy ? <span className="mmi-loading" aria-hidden="true" /> : <Dashicon name={icon} />} {busy ? busyLabel : label}
    </button>
  );
}

/** A page section's header band (the suite's Page Section standard). */
export function Section({ id, icon, title, desc, actions, children }:
  { id: string; icon: string; title: string; desc: string; actions?: ReactNode; children: ReactNode }) {
  // Shown inside another section (a review queue under Needs a decision's pill): no card, no title, the pill names it.
  if (useContext(SectionEmbedContext)) {
    return (
      <div className="mmi-wb-embedded" id={id} aria-label={title}>
        <div className="mmi-wb-embedded-head">
          <p className="description">{desc}</p>
          {actions ? <div className="mmi-wb-section-actions">{actions}</div> : null}
        </div>
        {children}
      </div>
    );
  }
  return (
    <div className="mmi-process-section" id={id}>
      <div className="mmi-section-header">
        <div>
          <h3 className="mmi-process-section-header"><Dashicon name={icon} /> {title}</h3>
          <p className="mmi-process-section-description">{desc}</p>
        </div>
        {actions ? <div className="mmi-wb-section-actions">{actions}</div> : null}
      </div>
      <div className="mmi-section-content">{children}</div>
    </div>
  );
}

/**
 * The shared .mmi-multiselect checkbox dropdown. The trigger reads the filter
 * name, the one ticked option, or "Name" with a count badge. Closes on an
 * outside click or Escape.
 */
export function MultiSelect({ id, label, options, checked, onChange, before }: {
  id: string;
  label: string;
  options: Record<string, string>;
  checked: string[];
  onChange: (next: string[]) => void;
  before?: ReactNode;
}) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!open) return;
    const onDoc = (e: MouseEvent) => { if (!ref.current?.contains(e.target as Node)) setOpen(false); };
    const onKey = (e: KeyboardEvent) => { if (e.key === "Escape") setOpen(false); };
    document.addEventListener("click", onDoc);
    document.addEventListener("keydown", onKey);
    return () => { document.removeEventListener("click", onDoc); document.removeEventListener("keydown", onKey); };
  }, [open]);

  const one = checked.length === 1 ? options[checked[0]!] : undefined;
  return (
    <div ref={ref} className={`mmi-multiselect mmi-wb-filter${open ? " is-open" : ""}${checked.length ? " has-value" : ""}`} id={id}>
      <button type="button" className="mmi-ms-trigger" aria-expanded={open} aria-haspopup="true" onClick={() => setOpen((o) => !o)}>
        <span className="mmi-ms-label">{one ? `${label}: ${one}` : label}</span>
        <span className="mmi-ms-badge" hidden={checked.length < 2}>{checked.length}</span>
        <span className="mmi-ms-caret" aria-hidden="true">▼</span>
      </button>
      <div className="mmi-ms-menu" hidden={!open}>
        {before}
        {Object.entries(options).map(([value, text]) => (
          <label key={value} className="mmi-ms-option mmi-ms-option--check">
            <input type="checkbox" value={value} checked={checked.includes(value)}
              onChange={(e) => onChange(e.target.checked ? [...checked, value] : checked.filter((v) => v !== value))} />
            {" "}<span className="mmi-ms-opt-label">{text}</span>
          </label>
        ))}
      </div>
    </div>
  );
}

/**
 * A toolbar button that opens a menu below it, in the .mmi-multiselect
 * look. Closes on an outside click, Escape, or when the content calls close().
 */
export function MenuButton({ id, label, count, title, children }: {
  id: string;
  label: string;
  /** Shown as a badge when above 0. */
  count?: number;
  title?: string;
  children: (close: () => void) => ReactNode;
}) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!open) return;
    const onDoc = (e: MouseEvent) => { if (!ref.current?.contains(e.target as Node)) setOpen(false); };
    const onKey = (e: KeyboardEvent) => { if (e.key === "Escape") setOpen(false); };
    document.addEventListener("click", onDoc);
    document.addEventListener("keydown", onKey);
    return () => { document.removeEventListener("click", onDoc); document.removeEventListener("keydown", onKey); };
  }, [open]);
  return (
    <div ref={ref} className={`mmi-multiselect mmi-wb-filter mmi-wb-menu${open ? " is-open" : ""}${count ? " has-value" : ""}`} id={id}>
      <button type="button" className="mmi-ms-trigger" aria-expanded={open} aria-haspopup="true" title={title} onClick={() => setOpen((o) => !o)}>
        <span className="mmi-ms-label">{label}</span>
        <span className="mmi-ms-badge" hidden={!count}>{count}</span>
        <span className="mmi-ms-caret" aria-hidden="true">▼</span>
      </button>
      <div className="mmi-ms-menu" hidden={!open}>{open ? children(() => setOpen(false)) : null}</div>
    </div>
  );
}

/**
 * Hosts server-rendered markup (by element id) inside the React tree. The
 * node is moved, not copied, so the jQuery component keeps its state and its
 * delegated handlers; on unmount it goes back to its hidden holder.
 */
export function Island({ sourceId, className }: { sourceId: string; className?: string }) {
  const slot = useRef<HTMLDivElement>(null);
  useLayoutEffect(() => {
    const node = document.getElementById(sourceId);
    const home = node?.parentElement;
    if (!node || !home || !slot.current) return;
    slot.current.appendChild(node);
    return () => { home.appendChild(node); };
  }, [sourceId]);
  return <div ref={slot} className={className} />;
}

/**
 * A sortable column heading, the same on every Workbench table: the label,
 * then ↕ (unsorted), ↑ or ↓. Enter and Space sort like a click. The column's
 * key names it for the shared resizer's saved widths too.
 */
export function SortTh<K extends string>({ col, label, title, active, desc, onSort, className }: {
  col: K;
  label: ReactNode;
  title?: string;
  active: boolean;
  desc: boolean;
  onSort: (col: K) => void;
  className?: string;
}) {
  return (
    <th data-col={col} data-resize-col={col} tabIndex={0} title={title}
      className={`mmi-wb-job-sortable${active ? " is-active" : ""}${active && desc ? " is-desc" : ""}${className ? ` ${className}` : ""}`}
      aria-sort={active ? (desc ? "descending" : "ascending") : "none"}
      onClick={() => onSort(col)}
      onKeyDown={(e: ReactKeyboardEvent<HTMLTableCellElement>) => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); onSort(col); } }}>
      {label} <span className="sort-icon" />
    </th>
  );
}

/**
 * Writes each visible heading's width as its share of the table, so a fixed
 * layout keeps the widths and they still scale when the box narrows (Needs a
 * decision's drawer). Hidden headings keep what they have.
 *
 * A column whose cells can't wrap (a row's buttons) keeps the width its widest
 * cell needs, and the other columns share what is left; otherwise the buttons
 * spill past the table's edge (Chromium sized Standing rules' five buttons
 * 24px short). After a drag, the dragged column (keep) keeps its new width too,
 * and the others share what is left of the box.
 */
function widthsAsShares(table: HTMLTableElement, heads: HTMLElement[], widths = heads.map((h) => h.getBoundingClientRect().width), keep?: HTMLElement): number[] | null {
  const sumOf = widths.reduce((a, b) => a + b, 0);
  const total = keep ? (table.parentElement?.clientWidth || sumOf) : sumOf;
  if (total <= 0) return null;
  const need = heads.map(() => 0);
  for (const row of Array.from(table.tBodies[0]?.rows ?? []).slice(0, 100)) {
    if (row.cells.length !== heads.length) continue; // a notice or empty row spanning the table
    Array.from(row.cells).forEach((c, i) => { if (c.scrollWidth > c.clientWidth) need[i] = Math.max(need[i]!, c.scrollWidth + 1); });
  }
  heads.forEach((h, i) => { if (h === keep) need[i] = Math.max(need[i]!, widths[i]!); });
  const fixed = widths.map((w, i) => need[i]! > w || heads[i] === keep);
  const kept = widths.reduce((sum, _w, i) => sum + (fixed[i] ? need[i]! : 0), 0);
  const rest = widths.reduce((sum, w, i) => sum + (fixed[i] ? 0 : w), 0);
  const scale = kept < total && rest > 0 ? (total - kept) / rest : 1;
  const px = widths.map((w, i) => (fixed[i] && kept < total ? need[i]! : w * scale));
  const sum = px.reduce((a, b) => a + b, 0);
  // Border-box widths: Chromium adds a content-box cell's padding on top of a percentage and WebKit
  // doesn't, so only border-box reads the same in both. It is also what the resizer measures and saves (outerWidth).
  heads.forEach((h, i) => {
    if (widths[i]! <= 0) return;
    h.style.boxSizing = "border-box";
    h.style.width = `${((px[i]! / sum) * 100).toFixed(3)}%`;
  });
  return px.map((x, i) => (widths[i]! > 0 ? x / sum : 0));
}

/**
 * Column resizing for a Workbench table, through the shared column-resizer.js
 * (widths kept per column name in this browser). Starts once the table has
 * rows, so the widths it starts from are the ones its content gave it; a saved
 * width wins over those. After a drag the widths become shares of the table
 * again, so the table always fits its box. A table in a hidden section has no
 * widths to start from, so it starts when it is first shown.
 *
 * During a drag the other columns hold their widths in pixels and the table
 * follows the drag: with the others as shares, Chromium gave the space a
 * narrowing column let go straight back to it, so no column could be narrowed.
 *
 * When the box changes width (Needs a decision's drawer opens), the columns
 * take their chosen shares of the new width again, and a column whose cells
 * can't wrap (IDs, row buttons) keeps what they need.
 */
export function useColumnResize(table: RefObject<HTMLTableElement>, ready: boolean): void {
  const started = useRef<HTMLTableElement | null>(null);
  useLayoutEffect(() => {
    const el = table.current;
    const w = page();
    if (!el || !ready || started.current === el || !w.jQuery || !w.MMI_ColumnResizer) return;
    const $ = w.jQuery;
    const resizer = w.MMI_ColumnResizer;
    let base: number[] | null = null; // the chosen shares: from the content, a saved width or the last drag
    let dragging = false;
    const onDown = (e: MouseEvent) => {
      const dragged = (e.target as Element | null)?.closest(".mmi-resize-handle")?.closest("th, td") as HTMLElement | null | undefined;
      if (!dragged) return;
      dragging = true;
      const shown = heads.filter((h) => h.getBoundingClientRect().width > 0);
      shown.forEach((h) => { h.style.width = `${h.getBoundingClientRect().width}px`; });
      // The resizer's own document handlers were bound first, so the dragged width is set when this runs.
      const follow = () => { el.style.width = `${shown.reduce((sum, h) => sum + h.getBoundingClientRect().width, 0)}px`; };
      follow();
      document.addEventListener("mousemove", follow);
      document.addEventListener("mouseup", () => {
        document.removeEventListener("mousemove", follow);
        window.setTimeout(() => {
          const widths = heads.map((h) => h.getBoundingClientRect().width);
          el.style.width = "";
          base = widthsAsShares(el, heads, widths, dragged) ?? base;
          dragging = false;
          // The resizer saved on release, before the other columns made room; save what the table shows now.
          const id = resizer.getTableId?.($(el));
          if (id) resizer.saveColumnWidths?.($(el), `mmi_column_widths_${id}`);
        }, 0);
      }, { once: true });
    };
    const heads = Array.from(el.tHead?.rows[0]?.cells ?? []) as HTMLElement[];
    const start = () => {
      started.current = el;
      widthsAsShares(el, heads);
      el.style.tableLayout = "fixed";
      const $t = $(el);
      const id = resizer.getTableId?.($t);
      if (id) $(document).off(`.mmi-resizer-${id}`);
      resizer.initTable($t);
      base = widthsAsShares(el, heads);
      el.addEventListener("mousedown", onDown);
      const box = el.parentElement;
      if (!box) return;
      let last = box.clientWidth;
      new ResizeObserver(() => {
        const W = box.clientWidth;
        if (dragging || !base || !W || W === last) return;
        last = W;
        const visible = heads.map((h) => h.getBoundingClientRect().width > 0);
        const known = base.filter((f, i) => visible[i] && f > 0);
        const fill = known.length ? known.reduce((a, b) => a + b, 0) / known.length : 1; // a column first seen now
        const f = base.map((x, i) => (visible[i] ? x || fill : 0));
        const sum = f.reduce((a, b) => a + b, 0);
        if (sum > 0) widthsAsShares(el, heads, f.map((x) => (x / sum) * W));
      }).observe(box);
    };
    let shown: ResizeObserver | null = null;
    if (el.getClientRects().length) start();
    else {
      shown = new ResizeObserver(() => { if (el.getClientRects().length) { shown?.disconnect(); start(); } });
      shown.observe(el);
    }
    // The drag and box listeners stay for the table's life: a reload toggles ready, and the table keeps its handles.
    return () => shown?.disconnect();
  }, [table, ready]);
}

/** The shared prev/next pager (mmi-pagination.js), kept in step with the search. */
export function Pager({ pages, current, onPage }: { pages: number; current: number; onPage: (p: number) => void }) {
  const box = useRef<HTMLDivElement>(null);
  const pager = useRef<ReturnType<NonNullable<ReturnType<typeof page>["MMIPagination"]>["init"]> | null>(null);
  const onPageRef = useRef(onPage);
  onPageRef.current = onPage;
  useEffect(() => {
    if (!box.current || !page().MMIPagination) return;
    if (!pager.current) {
      pager.current = page().MMIPagination!.init({ container: box.current, totalPages: pages, page: current, onPageChange: (p) => onPageRef.current(p) });
    } else {
      pager.current.setTotalPages(pages);
      pager.current.setPage(current);
    }
  }, [pages, current]);
  return <div ref={box} id="mmi-wb-pager" />;
}

const pics = (n: number) => `${n} picture${n === 1 ? "" : "s"}`;

/** The product's picture at the front of its row. */
export function ProductThumb({ thumb, size = 40 }: { thumb: Thumb | null; size?: number }) {
  if (!thumb) {
    return <span className="mmi-wb-thumb is-none" style={{ width: size, height: size }} title="No picture: the shop shows a placeholder"><Dashicon name="format-image" /></span>;
  }
  const title = thumb.featured
    ? pics(thumb.count)
    : `No featured picture: the shop shows a placeholder. Its gallery has ${pics(thumb.count)}.`;
  return (
    <span className={`mmi-wb-thumb${thumb.featured ? "" : " is-unfeatured"}`} style={{ width: size, height: size }} title={title}>
      <img src={thumb.url} alt="" loading="lazy" width={size} height={size} />
      {thumb.count > 1 && <span className="mmi-wb-thumb-n" aria-label={pics(thumb.count)}>{thumb.count}</span>}
    </span>
  );
}
