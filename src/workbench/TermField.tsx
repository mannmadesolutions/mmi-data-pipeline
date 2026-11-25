/**
 * A term field with its picker (Category & brand mapping): type to find a term in one taxonomy, pick it, or create
 * the typed name. A hierarchical taxonomy (product categories) arrives whole once per page and is filtered here as
 * you type, its tree indented, the current term marked; a flat one (brands, thousands of terms) is searched on the
 * server, 250 ms after the last key. ↑ ↓ move, Enter picks, Escape closes.
 *
 * The list is drawn on <body> at the field's place (position: fixed), so a table's scroll box can't clip it; it
 * closes when the page scrolls under it.
 */
import { useEffect, useId, useRef, useState, type CSSProperties, type KeyboardEvent } from "react";
import { createPortal } from "react-dom";
import { taxmapApi } from "./taxmapApi";
import { filterTermTree, offerCreate, type FoundTerms, type TreeTerm } from "./taxmapLogic";

const SEARCH_DEBOUNCE_MS = 250;
const MIN_WIDTH = 220;
const TREE_MIN_WIDTH = 320;

/** Hierarchical taxonomies' trees, fetched once per page; forgotten when a term is created in one. */
const trees = new Map<string, TreeTerm[]>();
export const forgetTermTree = (taxonomy: string): void => { trees.delete(taxonomy); };

/** A taxonomy's terms for a query: the cached tree when it has one, else the server's matches. */
export async function findTerms(taxonomy: string, q: string): Promise<FoundTerms> {
  const tree = trees.get(taxonomy);
  if (tree) return { tree };
  const f = await taxmapApi.terms(taxonomy, q);
  if ("tree" in f) trees.set(taxonomy, f.tree);
  return f;
}

interface Option { id: number; name: string; count: number; depth: number; kind: string; path: string }

export function TermField({ taxonomy, taxonomyLabel, value, currentId, placeholder, disabled, missing, onPick, onCreate, id, label }: {
  taxonomy: string;
  taxonomyLabel: string;
  /** The current term's name (shown while closed). */
  value: string;
  currentId: number;
  placeholder: string;
  disabled?: boolean;
  /** The saved term was deleted: the field says so. */
  missing?: boolean;
  onPick: (id: number, name: string) => void;
  /** Create the typed name (find-or-create server side); without it the picker only picks. */
  onCreate?: (name: string) => void;
  id?: string;
  label: string;
}) {
  const [text, setText] = useState(value);
  const [open, setOpen] = useState(false);
  const [found, setFound] = useState<FoundTerms | null>(null);
  const [query, setQuery] = useState("");
  const [active, setActive] = useState(-1);
  const [box, setBox] = useState<{ top: number; left: number; width: number } | null>(null);
  const input = useRef<HTMLInputElement>(null);
  const list = useRef<HTMLUListElement>(null);
  const seq = useRef(0);
  const timer = useRef(0);
  const listId = useId();

  useEffect(() => { if (!open) setText(value); }, [value, open]);

  const place = () => {
    const r = input.current?.getBoundingClientRect();
    if (r) setBox({ top: r.bottom + 4, left: r.left, width: Math.max(r.width, found && "tree" in found ? TREE_MIN_WIDTH : MIN_WIDTH) });
  };
  const load = (q: string, fromFocus: boolean) => {
    const mine = ++seq.current;
    void findTerms(taxonomy, q).then((f) => {
      if (mine !== seq.current) return;
      setFound(f);
      // Opening a tree shows all of it (the field holds the current term's name); typing filters.
      setQuery("tree" in f && fromFocus ? "" : q);
      setActive(-1);
      setOpen(true);
    }).catch(() => { if (mine === seq.current) setOpen(false); });
  };
  const close = () => { seq.current++; window.clearTimeout(timer.current); setOpen(false); setActive(-1); };

  useEffect(() => { if (open) place(); }, [open, found]); // eslint-disable-line react-hooks/exhaustive-deps
  // A click outside closes it; so does the page scrolling under it.
  useEffect(() => {
    if (!open) return;
    const away = (e: MouseEvent) => { const t = e.target as Node; if (!input.current?.contains(t) && !list.current?.contains(t)) close(); };
    const scrolled = (e: Event) => { if (!list.current?.contains(e.target as Node)) close(); };
    document.addEventListener("mousedown", away);
    window.addEventListener("scroll", scrolled, true);
    return () => { document.removeEventListener("mousedown", away); window.removeEventListener("scroll", scrolled, true); };
  }, [open]);
  // Opening the whole tree scrolls to the current term.
  useEffect(() => {
    if (!open || query.trim()) return;
    list.current?.querySelector<HTMLElement>(".is-current")?.scrollIntoView({ block: "center" });
  }, [open, query, found]);

  const options: Option[] = !found ? []
    : "tree" in found ? filterTermTree(found.tree, query).map(({ term, kind }) => ({ id: term.id, name: term.name, count: term.count, depth: term.depth, kind, path: term.path }))
    : found.terms.map((t) => ({ id: t.id, name: t.name, count: t.count, depth: 0, kind: "all", path: t.name }));
  const create = onCreate && found ? offerCreate(found, query) : "";
  const total = options.length + (create ? 1 : 0);

  const pick = (o: Option) => { close(); setText(o.name); onPick(o.id, o.name); };
  const doCreate = () => { close(); setText(create); onCreate?.(create); };
  const onKey = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === "Escape") { if (open) { e.preventDefault(); close(); setText(value); } return; }
    if (!open || !total) return;
    if (e.key === "ArrowDown" || e.key === "ArrowUp") {
      e.preventDefault();
      setActive((a) => (e.key === "ArrowDown" ? (a + 1) % total : (a <= 0 ? total - 1 : a - 1)));
    } else if (e.key === "Enter" && active >= 0) {
      e.preventDefault();
      if (active < options.length) pick(options[active]!);
      else doCreate();
    }
  };
  useEffect(() => { list.current?.querySelector<HTMLElement>(".is-active")?.scrollIntoView({ block: "nearest" }); }, [active]);

  const isTree = !!found && "tree" in found;
  const mark = (name: string) => {
    const q = query.trim().toLowerCase();
    const at = q ? name.toLowerCase().indexOf(q) : -1;
    return at < 0 ? name : <>{name.slice(0, at)}<mark>{name.slice(at, at + q.length)}</mark>{name.slice(at + q.length)}</>;
  };

  return (
    <>
      <input ref={input} type="text" id={id} className={`mmi-wb-tm-term${missing ? " is-missing" : ""}`} value={text} placeholder={placeholder} disabled={disabled}
        aria-label={label} role="combobox" aria-expanded={open} aria-controls={listId} aria-autocomplete="list" autoComplete="off"
        onFocus={() => load(text, true)}
        onChange={(e) => { const q = e.target.value; setText(q); window.clearTimeout(timer.current); timer.current = window.setTimeout(() => load(q, false), trees.has(taxonomy) ? 0 : SEARCH_DEBOUNCE_MS); }}
        onKeyDown={onKey} />
      {open && box && (options.length > 0 || create || query.trim()) && createPortal(
        <ul ref={list} id={listId} role="listbox" className={`mmi-wb-tm-picker${isTree ? " is-tree" : ""}`}
          style={{ "--mmi-wb-tm-top": `${box.top}px`, "--mmi-wb-tm-left": `${box.left}px`, "--mmi-wb-tm-width": `${box.width}px` } as CSSProperties} aria-label={`${isTree ? "Pick from" : "Searching"}: ${taxonomyLabel}`}>
          <li className="mmi-wb-tm-picker-head" aria-hidden="true">{isTree ? "Pick from" : "Searching"}: {taxonomyLabel}</li>
          {options.map((o, i) => (
            <li key={o.id} role="option" aria-selected={i === active} title={o.path}
              className={`mmi-wb-tm-option is-${o.kind}${o.id === currentId ? " is-current" : ""}${i === active ? " is-active" : ""}`}
              style={{ "--mmi-wb-tm-depth": o.depth } as CSSProperties} onMouseDown={(e) => e.preventDefault()} onClick={() => pick(o)}>
              <span className="mmi-wb-tm-option-name">{o.kind === "match" ? mark(o.name) : o.name}</span>
              <span className="mmi-wb-tm-option-count">{o.count}</span>
            </li>
          ))}
          {!options.length && query.trim() && <li className="mmi-wb-tm-option-empty">No term matches “{query.trim()}”.</li>}
          {create && (
            <li role="option" aria-selected={active === options.length} className={`mmi-wb-tm-option is-create${active === options.length ? " is-active" : ""}`}
              onMouseDown={(e) => e.preventDefault()} onClick={doCreate}>
              <span className="dashicons dashicons-plus-alt2" aria-hidden="true" /> Create term: “{create}”
            </li>
          )}
        </ul>,
        document.body,
      )}
    </>
  );
}
