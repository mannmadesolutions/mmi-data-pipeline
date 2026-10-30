/**
 * Small building blocks rendered with the suite's shared classes
 * (mmi-suite-common.css): page sections, spinner buttons, multi-select
 * filters, the shared pager, and Island, which hosts server-rendered jQuery
 * markup inside the React tree.
 */
import { useEffect, useLayoutEffect, useRef, useState, type ButtonHTMLAttributes, type ReactNode } from "react";
import { page } from "./globals";

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
