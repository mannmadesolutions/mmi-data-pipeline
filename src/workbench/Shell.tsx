/**
 * The Workbench's frame: a side menu of every section, one section on screen.
 *
 * - A section mounts the first time it is opened, then stays mounted (hidden),
 *   so its filters, page, open editor and running work are where the person
 *   left them. A hidden section is told so (useSectionVisible) and pauses its
 *   pollers.
 * - The open section is wb_sec in the address: a menu click adds a history
 *   entry (Back returns), a section following its own pill replaces it.
 * - Menu items are links to their address, so Cmd/Ctrl-click opens the
 *   section in a new tab.
 * - Badges come from one counts call after the menu is drawn, again when a
 *   section opens or the page says something changed (debounced), never polled.
 * - Under 960 px the menu becomes a <select> above the section.
 */
import { useCallback, useEffect, useMemo, useRef, useState, type MouseEvent, type ReactNode } from "react";
import { api } from "./api";
import { landing, readLanding } from "./landing";
import { COUNTS_EVENT, OPEN_EVENT, SectionVisibleContext, type OpenRequest } from "./nav";
import type { WorkbenchCounts } from "./types";

export type GroupId = "work" | "review" | "maker" | "automation" | "store" | "history";

export const GROUPS: { id: GroupId; label: string }[] = [
  { id: "work", label: "Work" },
  { id: "review", label: "Review" },
  { id: "maker", label: "Maker data" },
  { id: "automation", label: "Automation" },
  { id: "store", label: "This store" },
  { id: "history", label: "History" },
];

export interface Badge { text: string; kind?: "" | "error" | "warning" | "info"; title?: string }

export interface MenuEntry {
  key: string;
  label: string;
  group: GroupId;
  /** Entries showing one section in different ways share its pane (Descriptions and Pictures to pick). */
  pane?: string;
  /** Mounted with the page, not on first open (Find and change hosts the shared jQuery components). */
  eager?: boolean;
  /** The entry's badge from the counts; "loading" while its count is being worked out. */
  badge?: (c: WorkbenchCounts) => Badge | "loading" | null;
  /** The section, given the entry chosen for its pane. */
  render: (entry: string) => ReactNode;
}

const COUNTS_DEBOUNCE_MS = 2000;
const LANDING_PARAMS = ["wb_sec", "wb_q", "wb_brand", "wb_site", "wb_bundle"];

/** This page's address with wb_sec set to the key and the other landing parameters dropped. */
export function hrefFor(key: string): string {
  const u = new URL(window.location.href);
  LANDING_PARAMS.forEach((p) => u.searchParams.delete(p));
  u.searchParams.set("wb_sec", key);
  u.hash = "";
  return u.toString();
}

const paneOf = (e: MenuEntry) => e.pane ?? e.key;

export function Shell({ entries, strip, stripExcept }: {
  entries: MenuEntry[];
  /** Shown above every section but stripExcept's (a running job's progress, which that section shows itself). */
  strip?: ReactNode;
  stripExcept?: string;
}) {
  const byKey = useMemo(() => new Map(entries.map((e) => [e.key, e])), [entries]);
  const known = useCallback((k: string) => (byKey.has(k) ? k : entries[0]!.key), [byKey, entries]);

  const [active, setActive] = useState(() => known(landing().sec));
  const activeEntry = byKey.get(active) ?? entries[0]!;
  const activePane = paneOf(activeEntry);
  // The entry last chosen for each pane (a shared pane shows the way it was last opened).
  const [paneEntry, setPaneEntry] = useState<Record<string, string>>(() => ({ [activePane]: active }));
  const [mounted, setMounted] = useState<Set<string>>(() => new Set([...entries.filter((e) => e.eager).map(paneOf), activePane]));
  const root = useRef<HTMLDivElement>(null);

  const show = useCallback((key: string) => {
    const e = byKey.get(key);
    if (!e) return;
    setActive(key);
    setPaneEntry((m) => (m[paneOf(e)] === key ? m : { ...m, [paneOf(e)]: key }));
    setMounted((s) => (s.has(paneOf(e)) ? s : new Set(s).add(paneOf(e))));
  }, [byKey]);

  /* ── Counts ───────────────────────────────────────────────────────────── */

  const [counts, setCounts] = useState<WorkbenchCounts | null>(null);
  const [countsFailed, setCountsFailed] = useState(false);
  const lastAsk = useRef(0);
  const later = useRef(0);
  const loadCounts = useCallback(() => {
    window.clearTimeout(later.current);
    lastAsk.current = Date.now();
    api.counts().then((c) => { setCounts(c); setCountsFailed(false); }).catch(() => setCountsFailed(true));
  }, []);
  /** Ask again, at most once per COUNTS_DEBOUNCE_MS. */
  const countsSoon = useCallback(() => {
    window.clearTimeout(later.current);
    later.current = window.setTimeout(loadCounts, Math.max(0, lastAsk.current + COUNTS_DEBOUNCE_MS - Date.now()));
  }, [loadCounts]);
  useEffect(() => { loadCounts(); return () => window.clearTimeout(later.current); }, [loadCounts]);
  useEffect(() => {
    window.addEventListener(COUNTS_EVENT, countsSoon);
    return () => window.removeEventListener(COUNTS_EVENT, countsSoon);
  }, [countsSoon]);
  // A maker-pages count being worked out in the background is asked for again shortly.
  useEffect(() => {
    if (!counts?.pages?.rebuilding) return;
    const t = window.setTimeout(loadCounts, 15000);
    return () => window.clearTimeout(t);
  }, [counts, loadCounts]);

  /* ── Moving between sections ──────────────────────────────────────────── */

  const go = useCallback((key: string, replace = false) => {
    const k = known(key);
    if (k === active && !replace) return;
    show(k);
    if (replace) window.history.replaceState({ wbSec: k }, "", hrefFor(k));
    else window.history.pushState({ wbSec: k }, "", hrefFor(k));
    countsSoon();
    // The section starts at its top: scroll back up if the page is below the shell's start.
    const top = root.current ? root.current.getBoundingClientRect().top : 0;
    if (!replace && top < 0) window.scrollTo({ top: window.scrollY + top - 40 });
  }, [active, countsSoon, known, show]);

  useEffect(() => {
    const onOpen = (e: Event) => { const d = (e as CustomEvent<OpenRequest>).detail; if (d?.key) go(d.key, !!d.replace); };
    const onPop = () => show(known(readLanding(window.location.search, window.location.hash).sec));
    window.addEventListener(OPEN_EVENT, onOpen);
    window.addEventListener("popstate", onPop);
    return () => { window.removeEventListener(OPEN_EVENT, onOpen); window.removeEventListener("popstate", onPop); };
  }, [go, known, show]);

  const onItem = (e: MouseEvent<HTMLAnchorElement>, key: string) => {
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return; // a new tab or window: the browser's own
    e.preventDefault();
    go(key);
  };

  /* ── Render ───────────────────────────────────────────────────────────── */

  const badgeOf = (e: MenuEntry): Badge | "loading" | null => {
    if (!e.badge || countsFailed) return null;
    return counts ? e.badge(counts) : "loading";
  };
  const groups = GROUPS.map((g) => ({ ...g, items: entries.filter((e) => e.group === g.id) })).filter((g) => g.items.length);
  const panes = [...new Map(entries.map((e) => [paneOf(e), e])).values()];

  return (
    <div className="mmi-wb-shell" id="mmi-wb-shell" ref={root}>
      <nav className="mmi-wb-menu" id="mmi-wb-menu" aria-label="Product Workbench sections">
        {groups.map((g) => (
          <div key={g.id} className="mmi-wb-menu-group" data-group={g.id}>
            <h2 className="mmi-wb-menu-heading">{g.label}</h2>
            <ul>
              {g.items.map((e) => {
                const b = badgeOf(e);
                const on = e.key === active;
                return (
                  <li key={e.key}>
                    <a href={hrefFor(e.key)} className={`mmi-wb-menu-item${on ? " is-active" : ""}`} data-sec={e.key} aria-current={on ? "page" : undefined}
                      onClick={(ev) => onItem(ev, e.key)}>
                      <span className="mmi-wb-menu-label">{e.label}</span>
                      {b === "loading"
                        ? <span className="mmi-wb-menu-badge is-loading" aria-label="Counting"><span className="mmi-loading" aria-hidden="true" /></span>
                        : b && <span className={`mmi-badge mmi-wb-menu-badge${b.kind ? ` ${b.kind}` : ""}`} title={b.title}>{b.text}</span>}
                    </a>
                  </li>
                );
              })}
            </ul>
          </div>
        ))}
      </nav>
      <label className="mmi-wb-menu-select">
        <span className="mmi-wb-menu-select-label">Section</span>
        <select id="mmi-wb-menu-select" value={active} onChange={(e) => go(e.target.value)}>
          {groups.map((g) => (
            <optgroup key={g.id} label={g.label}>
              {g.items.map((e) => {
                const b = badgeOf(e);
                return <option key={e.key} value={e.key}>{e.label}{b && b !== "loading" ? ` (${b.text})` : ""}</option>;
              })}
            </optgroup>
          ))}
        </select>
      </label>
      <div className="mmi-wb-main">
        {strip && activePane !== stripExcept ? <div className="mmi-wb-strip" id="mmi-wb-strip">{strip}</div> : null}
        {panes.map((e) => {
          const p = paneOf(e);
          if (!mounted.has(p)) return null;
          const on = p === activePane;
          return (
            <div key={p} className="mmi-wb-pane" data-pane={p} hidden={!on}>
              <SectionVisibleContext.Provider value={on}>{e.render(paneEntry[p] ?? e.key)}</SectionVisibleContext.Provider>
            </div>
          );
        })}
      </div>
    </div>
  );
}
