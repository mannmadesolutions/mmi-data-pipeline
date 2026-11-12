/**
 * Title proposals: Product Data's review queue in the Workbench. Each row is a
 * product whose composed title differs from its own: what changes (word diff),
 * the rules that made the change and why, how sure they are, and the decision.
 * Accept queues the title for the writer; Edit writes your own title and can
 * save a rule from your change; Apply now writes one at once; Revert puts a
 * written title back. "Write accepted titles" runs the throttled writer.
 */
import { useCallback, useEffect, useRef, useState, type KeyboardEvent, type ReactNode } from "react";
import { ApiError } from "./api";
import {
  actionsFor, applyLine, confKind, EMPTY_FILTER, filterQuery, filterTerms, STATUS_LABEL, STATUS_VIEWS, target, viewCount, wordDiff,
  type ApplyStatus, type DiffPart, type ProposalPage, type ProposalRow, type QueueFilter, type QueueSort, type QueueSummary, type RowAction, type Suggestion, type TermKind,
} from "./proposalsApi";
import { IssueChips, IssuePanel } from "./IssueFixes";
import type { FixResult, Issue } from "./issuesApi";
import { rest, type TermOption, type TitleRulesConfig } from "./titleRulesApi";
import { BusyButton, Dashicon, Section } from "./ui";
import { TitleWriting } from "./WritingSettings";
import { landing } from "./landing";
import { useSectionVisible } from "./nav";
import { PRODUCT_CHANGED } from "./waitingApi";

const COLS: { key: QueueSort; label: string }[] = [
  { key: "id", label: "ID" },
  { key: "proposed", label: "Title: now → proposed" },
  { key: "brand", label: "Brand" },
  { key: "category", label: "Category" },
  { key: "rules", label: "Rules" },
  { key: "confidence", label: "Sure" },
  { key: "stock", label: "Stock" },
  { key: "status", label: "Status" },
];

interface Notice { text: string; kind: "ok" | "error" }
interface Editing { id: number; text: string; suggestions: Suggestion[] | null; picks: Record<string, "brand" | "all"> }

const Diff = ({ parts }: { parts: DiffPart[] }) => (
  <>{parts.map((p, i) => (p.kind === "same" ? <span key={i}>{p.text}</span> : <span key={i} className={p.kind === "del" ? "mmi-wb-before" : "mmi-wb-after"}>{p.text}</span>))}</>
);

interface TermEdit { target: number | "bulk"; kind: TermKind; mode: "replace" | "add" }

/** The brand panel opened from the Brand cell's picker, for a product whose brand is set but wrong or not made yet. */
const NEW_BRAND: Issue = { key: "brand", label: "Brand", help: "Choose another brand, or create the maker as a new brand.", tone: "info", fixes: [{ kind: "brand", label: "Choose or create a brand", primary: true }] };

/** A searchable checkbox list of categories or brands, with the chosen ones as chips. */
function TermPicker({ terms, chosen, kind, bulk, mode, busy, onMode, onSave, onCancel, onCreate }: {
  terms: TermOption[] | null; chosen: number[]; kind: TermKind; bulk: number; mode: "replace" | "add"; busy: boolean;
  onMode: (m: "replace" | "add") => void; onSave: (ids: number[]) => void; onCancel: () => void;
  /** One product's brand: open the brand panel, where a new brand is created. */
  onCreate?: () => void;
}) {
  const [q, setQ] = useState("");
  const [picked, setPicked] = useState<number[]>(chosen);
  const noun = kind === "cats" ? "categories" : "brands";
  const name = (id: number) => terms?.find((t) => t.id === id)?.path ?? `#${id}`;
  return (
    <div className="mmi-wb-pr-terms" id="mmi-wb-pr-terms">
      <div className="mmi-wb-tr-fields">
        <label className="mmi-wb-tr-field mmi-wb-tr-grow">
          <span className="mmi-wb-label">{bulk ? `${kind === "cats" ? "Categories" : "Brands"} for ${bulk} ticked product${bulk === 1 ? "" : "s"}` : kind === "cats" ? "Categories" : "Brands"}</span>
          <input type="search" id="mmi-wb-pr-terms-q" placeholder={`Search ${noun}`} value={q} onChange={(e) => setQ(e.target.value)} />
        </label>
        {bulk > 0 && (
          <label className="mmi-wb-tr-field">
            <span className="mmi-wb-label">How</span>
            <select id="mmi-wb-pr-terms-mode" value={mode} onChange={(e) => onMode(e.target.value as "replace" | "add")}>
              <option value="replace">Replace their {noun}</option>
              <option value="add">Add to their {noun}</option>
            </select>
          </label>
        )}
      </div>
      <div className="mmi-wb-pr-chips">{picked.map((id) => (
        <button key={id} type="button" className="mmi-wb-pr-chip is-active" aria-label={`Remove ${name(id)}`} onClick={() => setPicked((p) => p.filter((x) => x !== id))}>{name(id)} ×</button>
      ))}</div>
      <div className="mmi-wb-pr-term-list" role="group" aria-label={noun}>
        {!terms && <p className="description">Loading {noun}…</p>}
        {terms && filterTerms(terms, q, picked).map((t) => (
          <label key={t.id} className="mmi-wb-pr-term" style={{ paddingLeft: `${t.depth * 14}px` }} title={t.path}>
            <input type="checkbox" checked={picked.includes(t.id)} onChange={(e) => setPicked((p) => (e.target.checked ? [...p, t.id] : p.filter((x) => x !== t.id)))} />
            {" "}{q ? t.path : t.name}
          </label>
        ))}
      </div>
      <p className="description">Saved {noun} are locked against supplier imports; the title is recomposed now.</p>
      <div className="mmi-wb-tr-buttons">
        <BusyButton id="mmi-wb-pr-terms-save" className="button button-primary" busy={busy} icon="saved" label={`Save ${noun}`} busyLabel="Saving…"
          disabled={kind === "cats" && !picked.length || mode === "add" && !picked.length} onClick={() => onSave(picked)} />
        <button type="button" className="button" id="mmi-wb-pr-terms-cancel" onClick={onCancel}>Cancel</button>
        {onCreate && <button type="button" className="button-link mmi-wb-pr-terms-create" onClick={onCreate}><Dashicon name="plus-alt2" /> Not listed? Create a new brand…</button>}
      </div>
    </div>
  );
}

export function TitleProposals({ cfg }: { cfg: TitleRulesConfig }) {
  // A link to a product's title (wb_sec=titles&wb_q=…) opens the queue on it, any status.
  const landed = landing().sec === "titles" && landing().q ? landing().q : "";
  const [filter, setFilter] = useState<QueueFilter>(() => (landed ? { ...EMPTY_FILTER, q: landed, status: "any" } : EMPTY_FILTER));
  const [q, setQ] = useState(landed);
  const [page, setPage] = useState<ProposalPage | null>(null);
  const [loading, setLoading] = useState(false);
  const [summary, setSummary] = useState<QueueSummary | null>(null);
  const [selected, setSelected] = useState<number[]>([]);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [rowNotice, setRowNotice] = useState<{ id: number } & Notice | null>(null);
  const [busy, setBusy] = useState<number | "bulk" | null>(null);
  const [editing, setEditing] = useState<Editing | null>(null);
  const [apply, setApply] = useState<ApplyStatus | null>(null);
  const [applyBusy, setApplyBusy] = useState(false);
  const [now, setNow] = useState(() => Date.now() / 1000);
  const seq = useRef(0);
  const skew = useRef(0);
  const lastApply = useRef<ApplyStatus | null>(null);
  const [terms, setTerms] = useState<TermEdit | null>(null);
  const [termLists, setTermLists] = useState<Record<TermKind, TermOption[] | null>>({ cats: null, brands: null });
  /** The issue whose fixes are open under its row. */
  const [issueOpen, setIssueOpen] = useState<{ id: number; key: string } | null>(null);

  const msg = (e: unknown) => (e instanceof ApiError ? e.message : String(e));

  const loadSummary = useCallback(() => {
    rest<QueueSummary>(cfg, "GET", "summary").then(setSummary).catch(() => {});
  }, [cfg]);

  const load = useCallback(async (f: QueueFilter) => {
    const mine = ++seq.current;
    setLoading(true);
    try {
      const d = await rest<ProposalPage>(cfg, "GET", `proposals?${filterQuery(f)}`);
      if (mine === seq.current) setPage(d);
    } catch (e) {
      if (mine === seq.current) { setPage({ rows: [], total: 0, pages: 1 }); setNotice({ text: msg(e), kind: "error" }); }
    } finally {
      if (mine === seq.current) setLoading(false);
    }
  }, [cfg]);

  useEffect(() => { loadSummary(); }, [loadSummary]);
  useEffect(() => { void load(filter); setSelected([]); }, [filter, load]);
  // The search waits for typing to pause.
  useEffect(() => {
    if (q === filter.q) return;
    const t = window.setTimeout(() => setFilter((f) => ({ ...f, q, page: 1 })), 400);
    return () => window.clearTimeout(t);
  }, [q, filter.q]);

  // The writer's status: polled often while it works, rarely otherwise; the countdown ticks every second.
  const pollApply = useCallback(async () => {
    try {
      const s = await rest<ApplyStatus>(cfg, "GET", "apply-status");
      skew.current = Date.now() / 1000 - s.now;
      const before = lastApply.current;
      lastApply.current = s;
      setApply(s);
      // Titles were written (or queued) since the last look: the rows on screen changed status.
      if (before && (before.applied_today !== s.applied_today || before.queue !== s.queue)) { void load(filter); loadSummary(); }
    } catch { /* the bar keeps its last state */ }
  }, [cfg, filter, load, loadSummary]);
  // Both pause while another section is on screen, and look again as soon as this one is back.
  const visible = useSectionVisible();
  useEffect(() => {
    if (!visible) return;
    void pollApply();
    const active = apply && (apply.phase === "running" || apply.phase === "waiting");
    const t = window.setInterval(() => void pollApply(), active ? 5000 : 30000);
    return () => window.clearInterval(t);
  }, [pollApply, apply?.phase, visible]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    if (apply?.phase !== "waiting" || !visible) return;
    setNow(Date.now() / 1000 - skew.current);
    const t = window.setInterval(() => setNow(Date.now() / 1000 - skew.current), 1000);
    return () => window.clearInterval(t);
  }, [apply?.phase, visible]);

  const refresh = () => { void load(filter); loadSummary(); void pollApply(); };
  // A product decided in the drawer (Needs a decision): this list shows it as it is now.
  const refreshRef = useRef(refresh);
  refreshRef.current = refresh;
  useEffect(() => {
    const on = () => refreshRef.current();
    window.addEventListener(PRODUCT_CHANGED, on);
    return () => window.removeEventListener(PRODUCT_CHANGED, on);
  }, []);

  const decide = async (action: "accept" | "reject" | "reset", ids: number[]) => {
    setBusy(ids.length === 1 ? ids[0]! : "bulk");
    setNotice(null);
    try {
      const d = await rest<{ updated: number }>(cfg, "POST", "decide", { action, ids });
      const word = action === "accept" ? "accepted" : action === "reject" ? "rejected" : "back to review";
      setNotice({ text: `${d.updated} title${d.updated === 1 ? "" : "s"} ${word}.${action === "accept" ? " They are written in the next batch." : ""}`, kind: "ok" });
      setSelected([]);
      refresh();
    } catch (e) { setNotice({ text: msg(e), kind: "error" }); } finally { setBusy(null); }
  };

  const writeOne = async (row: ProposalRow, path: "apply" | "revert") => {
    if (!window.confirm(path === "apply"
      ? "Write this title to the product now? It is locked against imports and can be put back."
      : "Put the product's previous title back?")) return;
    setBusy(row.id);
    try {
      const r = await rest<{ result: string; message: string }>(cfg, "POST", `proposals/${row.id}/${path}`);
      const ok = r.result === "applied" || r.result === "reverted";
      setRowNotice({ id: row.id, text: ok ? (path === "apply" ? `Written: ${r.message}` : `Put back: ${r.message}`) : r.message, kind: ok ? "ok" : "error" });
      refresh();
    } catch (e) { setRowNotice({ id: row.id, text: msg(e), kind: "error" }); } finally { setBusy(null); }
  };

  // Editing: rules are suggested from the change as you type (CustomRules::suggest), ticked ones are saved with the title.
  useEffect(() => {
    if (!editing) return;
    const row = page?.rows.find((r) => r.id === editing.id);
    if (!row || editing.text.trim() === "" || editing.text === target(row)) { if (editing.suggestions?.length) setEditing((e) => e && { ...e, suggestions: [] }); return; }
    const t = window.setTimeout(() => {
      rest<{ suggestions: Suggestion[] }>(cfg, "POST", `proposals/${editing.id}/suggest-rules`, { title: editing.text })
        .then((d) => setEditing((e) => (e && e.id === editing.id ? { ...e, suggestions: d.suggestions } : e)))
        .catch(() => {});
    }, 400);
    return () => window.clearTimeout(t);
  }, [cfg, editing?.id, editing?.text]); // eslint-disable-line react-hooks/exhaustive-deps

  const saveEdit = async () => {
    if (!editing) return;
    setBusy(editing.id);
    try {
      const rules = Object.entries(editing.picks).filter(([, scope]) => scope).map(([key, scope]) => ({ key, scope }));
      const d = await rest<ProposalRow & { rules_saved: number; recomposed: { changed?: number; new?: number } | null }>(cfg, "POST", `proposals/${editing.id}/edit`, { title: editing.text.trim(), rules });
      const more = d.rules_saved ? ` ${d.rules_saved} rule${d.rules_saved === 1 ? "" : "s"} saved; ${(d.recomposed?.changed ?? 0) + (d.recomposed?.new ?? 0)} other proposals updated.` : "";
      setNotice({ text: `Your title for #${editing.id} is saved and goes out in the next batch.${more}`, kind: "ok" });
      setEditing(null);
      refresh();
    } catch (e) { setRowNotice({ id: editing.id, text: msg(e), kind: "error" }); } finally { setBusy(null); }
  };

  const runWriter = async (stop = false) => {
    if (!stop && apply && !window.confirm(`Write ${apply.queue} accepted title${apply.queue === 1 ? "" : "s"} to the live products? They go out ${apply.batch_size} at a time, a couple of minutes apart; each is locked against imports and can be put back.`)) return;
    setApplyBusy(true);
    try {
      const s = await rest<ApplyStatus>(cfg, "POST", stop ? "apply-stop" : "apply-run");
      setApply(s);
      if (s.error) setNotice({ text: s.error, kind: "error" });
    } catch (e) { setNotice({ text: msg(e), kind: "error" }); } finally { setApplyBusy(false); }
  };

  /** A fix was made in a row: say so beside it (or above, when the row left this view) and reload. */
  const fixed = (r: FixResult) => {
    setIssueOpen(null);
    if (r.row && !r.removed) setRowNotice({ id: r.id, text: r.message, kind: "ok" });
    else setNotice({ text: `#${r.id}: ${r.message}${r.removed ? " Its title now matches the rules, so it left the queue." : ""}`, kind: "ok" });
    refresh();
  };
  const openIssue = (id: number, key: string | null) => {
    setEditing(null);
    setTerms(null);
    setIssueOpen(key ? { id, key } : null);
  };
  const editTitle = (row: ProposalRow) => {
    setIssueOpen(null);
    setTerms(null);
    setEditing({ id: row.id, text: target(row), suggestions: null, picks: {} });
  };

  const openTerms = (t: TermEdit) => {
    setEditing(null);
    setIssueOpen(null);
    setTerms(t);
    if (!termLists[t.kind]) {
      const path = t.kind === "cats" ? "categories" : "brands";
      rest<Record<string, TermOption[]>>(cfg, "GET", path).then((d) => setTermLists((l) => ({ ...l, [t.kind]: d[path] ?? [] }))).catch((e) => setNotice({ text: msg(e), kind: "error" }));
    }
  };

  const saveTerms = async (ids: number[]) => {
    if (!terms) return;
    const noun = terms.kind === "cats" ? "Categories" : "Brands";
    setBusy(terms.target === "bulk" ? "bulk" : terms.target);
    try {
      if (terms.target === "bulk") {
        const d = await rest<{ rows: ProposalRow[]; removed: number[]; errors: Record<string, string> }>(cfg, "POST", "proposals/bulk-terms",
          { kind: terms.kind, mode: terms.mode, products: selected, ids });
        const n = d.rows.length + d.removed.length;
        const failed = Object.keys(d.errors).length;
        setNotice({ text: `${noun} saved on ${n} product${n === 1 ? "" : "s"}; their titles were recomposed.${failed ? ` ${failed} failed: ${Object.values(d.errors)[0]}` : ""}`, kind: failed ? "error" : "ok" });
        setSelected([]);
      } else {
        const id = terms.target;
        const d = await rest<{ removed?: boolean }>(cfg, "POST", `proposals/${id}/${terms.kind === "cats" ? "categories" : "brands"}`, { ids });
        setNotice({ text: d.removed ? `${noun} saved for #${id}. Its title now matches the rules, so it left the queue.` : `${noun} saved for #${id}; its title was recomposed.`, kind: "ok" });
      }
      setTerms(null);
      refresh();
    } catch (e) { setNotice({ text: msg(e), kind: "error" }); } finally { setBusy(null); }
  };

  const termCell = (row: ProposalRow, kind: TermKind) => {
    const list = kind === "cats" ? row.cats : row.brands;
    const locked = kind === "cats" ? row.cat_locked : row.brand_locked;
    return (
      <td className="mmi-wb-pr-termcell">
        {list.map((x) => x.name).join(", ") || <span className="description">None</span>}
        {locked && <span title="Locked against supplier imports"> <Dashicon name="lock" /></span>}
        {" "}<button type="button" className="button-link mmi-wb-pr-term-edit" aria-label={`Change ${kind === "cats" ? "categories" : "brands"} of #${row.id}`}
          disabled={busy !== null} onClick={() => openTerms({ target: row.id, kind, mode: "replace" })}><Dashicon name="edit" /></button>
      </td>
    );
  };

  const sortBy = (key: QueueSort) => setFilter((f) => ({ ...f, orderby: key, order: f.orderby === key && f.order === "desc" ? "asc" : "desc", page: 1 }));
  const onKey = (e: KeyboardEvent, key: QueueSort) => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); sortBy(key); } };
  const rows = page?.rows ?? [];
  const allTicked = rows.length > 0 && rows.every((r) => selected.includes(r.id));
  const ruleLabel = (id: string) => summary?.rules[id]?.label ?? id;
  const openTicked = selected.filter((id) => actionsFor(rows.find((r) => r.id === id)?.status ?? "applied").includes("accept"));

  const button = (row: ProposalRow, a: RowAction): ReactNode => {
    const dis = busy !== null;
    switch (a) {
      case "accept": return <button key={a} type="button" className="button button-small button-primary" disabled={dis} onClick={() => decide("accept", [row.id])}><Dashicon name="yes" /> Accept</button>;
      case "reject": return <button key={a} type="button" className="button button-small mmi-wb-icon-btn mmi-wb-rule-delete" disabled={dis} aria-label={`Reject #${row.id}`} title="Reject: keep the current title" onClick={() => decide("reject", [row.id])}><Dashicon name="no-alt" /></button>;
      case "reset": return <button key={a} type="button" className="button button-small" disabled={dis} onClick={() => decide("reset", [row.id])}><Dashicon name="undo" /> Undo</button>;
      case "edit": return <button key={a} type="button" className="button button-small" disabled={dis} onClick={() => editTitle(row)}><Dashicon name="edit" /> Edit</button>;
      case "apply": return <button key={a} type="button" className="button button-small" disabled={dis} onClick={() => writeOne(row, "apply")}><Dashicon name="upload" /> Write now</button>;
      case "revert": return <button key={a} type="button" className="button button-small" disabled={dis} onClick={() => writeOne(row, "revert")}><Dashicon name="backup" /> Put back</button>;
    }
  };

  return (
    <Section id="mmi-wb-proposals" icon="editor-textcolor" title="Title proposals"
      desc="Products whose title the rules would change. Accept a proposal to have it written, edit it to write your own (and save a rule from your change), or reject it to keep the current title. Written titles are locked against imports and can be put back"
      actions={
        <BusyButton id="mmi-wb-pr-write" className="button button-primary" busy={applyBusy} icon="upload" label="Write accepted titles" busyLabel="Starting…"
          disabled={!apply || !apply.enabled || apply.queue === 0 || apply.phase === "running" || apply.phase === "waiting"} onClick={() => runWriter()} />
      }>
      <div className="mmi-wb-pr-writer" id="mmi-wb-pr-writer">
        {apply ? applyLine(apply, now) : "Checking the writer…"}
        {apply && (apply.phase === "running" || apply.phase === "waiting") && (
          <button type="button" className="button button-small" id="mmi-wb-pr-stop" disabled={applyBusy} onClick={() => runWriter(true)}>Stop</button>
        )}
      </div>
      <TitleWriting cfg={cfg} onChange={refresh} onShowAuto={() => setFilter((f) => ({ ...f, status: "auto", page: 1 }))} />
      {notice && <div className={`notice notice-${notice.kind === "error" ? "error" : "success"} inline`} role="status" id="mmi-wb-pr-notice"><p>{notice.text}</p></div>}

      <div className="mmi-filter-pills mmi-wb-pr-views" role="group" aria-label="Show">
        {STATUS_VIEWS.map((v) => (
          <button key={v.value} type="button" className={`mmi-pill${filter.status === v.value ? " active" : ""}`} aria-pressed={filter.status === v.value}
            data-view={v.value} onClick={() => setFilter((f) => ({ ...f, status: v.value, page: 1 }))}>
            {v.label} <span className="mmi-pill-count">{summary ? (v.value === "auto" ? summary.auto ?? 0 : viewCount(summary.by_status, v.value)) : "…"}</span>
          </button>
        ))}
      </div>
      <div className="mmi-wb-filters mmi-wb-pr-filters">
        <input type="search" id="mmi-wb-pr-q" placeholder="Search titles, SKU or ID" value={q} onChange={(e) => setQ(e.target.value)} aria-label="Search proposals" />
        <select id="mmi-wb-pr-brand" value={filter.brand} aria-label="Brand" onChange={(e) => setFilter((f) => ({ ...f, brand: e.target.value, page: 1 }))}>
          <option value="">Every brand</option>
          {(summary?.brands ?? []).map((b) => <option key={b} value={b}>{b}</option>)}
        </select>
        <select id="mmi-wb-pr-rule" value={filter.rule} aria-label="Rule" onChange={(e) => setFilter((f) => ({ ...f, rule: e.target.value, page: 1 }))}>
          <option value="">Any rule</option>
          {Object.entries(summary?.rules ?? {}).map(([id, r]) => <option key={id} value={id}>{r.label}</option>)}
        </select>
        <span className="mmi-wb-count" id="mmi-wb-pr-count">{page ? `${page.total} proposal${page.total === 1 ? "" : "s"}` : ""}</span>
        {selected.length > 0 && (
          <span className="mmi-wb-pr-bulk">
            <span className="mmi-wb-selected">{selected.length} ticked</span>
            <button type="button" className="button button-small button-primary" id="mmi-wb-pr-accept-ticked" disabled={busy !== null || !openTicked.length} onClick={() => decide("accept", openTicked)}>Accept {openTicked.length}</button>
            <button type="button" className="button button-small" id="mmi-wb-pr-reject-ticked" disabled={busy !== null || !openTicked.length} onClick={() => decide("reject", openTicked)}>Reject {openTicked.length}</button>
            <button type="button" className="button button-small" id="mmi-wb-pr-cats-ticked" disabled={busy !== null} onClick={() => openTerms({ target: "bulk", kind: "cats", mode: "replace" })}>Change category</button>
            <button type="button" className="button button-small" id="mmi-wb-pr-brands-ticked" disabled={busy !== null} onClick={() => openTerms({ target: "bulk", kind: "brands", mode: "replace" })}>Change brand</button>
          </span>
        )}
      </div>

      {terms && terms.target === "bulk" && (
        <TermPicker key={`bulk-${terms.kind}`} terms={termLists[terms.kind]} chosen={[]} kind={terms.kind} bulk={selected.length} mode={terms.mode} busy={busy === "bulk"}
          onMode={(m) => setTerms({ ...terms, mode: m })} onSave={saveTerms} onCancel={() => setTerms(null)} />
      )}
      <div className="mmi-table-scroll-wrapper">
        <table className={`wp-list-table widefat striped mmi-wb-pr-table${loading ? " mmi-wb-loading" : ""}`} id="mmi-wb-pr-table">
          <thead>
            <tr>
              <td className="check-column"><input type="checkbox" aria-label="Tick every row on this page" checked={allTicked}
                onChange={(e) => setSelected(e.target.checked ? rows.map((r) => r.id) : [])} /></td>
              {COLS.map((c) => (
                <th key={c.key} data-col={c.key} tabIndex={0}
                  className={`mmi-wb-job-sortable${filter.orderby === c.key ? " is-active" : ""}${filter.orderby === c.key && filter.order === "desc" ? " is-desc" : ""}`}
                  aria-sort={filter.orderby === c.key ? (filter.order === "desc" ? "descending" : "ascending") : "none"}
                  onClick={() => sortBy(c.key)} onKeyDown={(e) => onKey(e, c.key)}>
                  {c.label} <span className="sort-icon" />
                </th>
              ))}
              <th><span className="screen-reader-text">Actions</span></th>
            </tr>
          </thead>
          <tbody id="mmi-wb-pr-rows">
            {!page && <tr><td colSpan={10} className="mmi-wb-empty-cell">Loading proposals…</td></tr>}
            {page && !rows.length && <tr><td colSpan={10} className="mmi-wb-empty-cell">Nothing here.</td></tr>}
            {rows.map((row) => {
              const shown = row.status === "applied" || row.status === "reverted" ? wordDiff(row.original ?? "", row.current) : wordDiff(row.current, target(row));
              const [statusText, statusKind] = STATUS_LABEL[row.status] ?? [row.status, ""];
              const mine = rowNotice && rowNotice.id === row.id ? rowNotice : null;
              const ed = editing && editing.id === row.id ? editing : null;
              return [
                <tr key={row.id} data-id={row.id} className={ed ? "is-editing" : undefined}>
                  <th scope="row" className="check-column"><input type="checkbox" aria-label={`Tick #${row.id}`} checked={selected.includes(row.id)}
                    onChange={(e) => setSelected((s) => (e.target.checked ? [...s, row.id] : s.filter((x) => x !== row.id)))} /></th>
                  <td className="mmi-wb-col-id"><a href={row.edit_url} target="_blank" rel="noreferrer">{row.id}</a></td>
                  <td className="mmi-wb-pr-title">
                    <div className="mmi-wb-pr-now"><Diff parts={shown.before} /></div>
                    <div className="mmi-wb-pr-new"><Diff parts={shown.after} /></div>
                    {row.renamed && <div className="description">The supplier renamed it: “{row.renamed.from}” → “{row.renamed.to}”.</div>}
                    <IssueChips issues={row.issues ?? []} open={issueOpen?.id === row.id ? issueOpen.key : null} onOpen={(k) => openIssue(row.id, k)} />
                    {row.error && <div className="notice notice-error inline"><p>{row.error}</p></div>}
                    {mine && <div className={`notice notice-${mine.kind === "error" ? "error" : "success"} inline mmi-wb-pr-row-notice`} role="status"><p>{mine.text}</p></div>}
                  </td>
                  {termCell(row, "brands")}
                  {termCell(row, "cats")}
                  <td className="mmi-wb-pr-rules">
                    {row.rules.map((r) => (
                      <button key={r} type="button" className={`mmi-wb-pr-chip${filter.rule === r ? " is-active" : ""}`} title={row.why[r] ? `${row.why[r]!.from || "—"} → ${row.why[r]!.to} (${row.why[r]!.source})` : undefined}
                        onClick={() => setFilter((f) => ({ ...f, rule: f.rule === r ? "" : r, page: 1 }))}>{ruleLabel(r)}</button>
                    ))}
                  </td>
                  <td><span className={`mmi-badge ${confKind(row.confidence)} inline`}>{row.confidence}%</span></td>
                  <td>{row.stock === "instock" ? "In stock" : row.stock === "outofstock" ? "Out of stock" : row.stock === "onbackorder" ? "Backorder" : ""}</td>
                  <td><span className={`mmi-badge ${statusKind} inline`}>{statusText}</span></td>
                  <td className="mmi-wb-job-actions">{actionsFor(row.status).map((a) => button(row, a))}</td>
                </tr>,
                ed ? (
                  <tr key={`${row.id}-edit`} className="mmi-wb-pr-edit-row">
                    <td colSpan={10}>
                      <div className="mmi-wb-pr-edit" id="mmi-wb-pr-edit">
                        <label className="mmi-wb-tr-field mmi-wb-tr-grow">
                          <span className="mmi-wb-label">Your title for #{row.id}</span>
                          <input type="text" id="mmi-wb-pr-edit-input" value={ed.text} maxLength={250}
                            onChange={(e) => setEditing({ ...ed, text: e.target.value, picks: {} })} />
                        </label>
                        {ed.suggestions && ed.suggestions.length > 0 && (
                          <div className="mmi-wb-pr-suggest" id="mmi-wb-pr-suggest">
                            <p className="description">Save a rule from your change, so other titles get it too (saving approves it):</p>
                            {ed.suggestions.map((s) => (
                              <div key={s.key} className="mmi-wb-pr-suggestion" data-key={s.key}>
                                <strong>{s.label}</strong>
                                <label><input type="checkbox" checked={ed.picks[s.key] === "brand"} disabled={!s.brand}
                                  onChange={(e) => setEditing({ ...ed, picks: { ...ed.picks, [s.key]: e.target.checked ? "brand" : undefined } as Editing["picks"] })} />
                                  {" "}for {s.brand || "this brand"} ({s.reach.brand} more)</label>
                                <label><input type="checkbox" checked={ed.picks[s.key] === "all"}
                                  onChange={(e) => setEditing({ ...ed, picks: { ...ed.picks, [s.key]: e.target.checked ? "all" : undefined } as Editing["picks"] })} />
                                  {" "}for every brand ({s.reach.all} more)</label>
                              </div>
                            ))}
                          </div>
                        )}
                        <div className="mmi-wb-tr-buttons">
                          <BusyButton id="mmi-wb-pr-edit-save" className="button button-primary" busy={busy === row.id} icon="saved" label="Save my title" busyLabel="Saving…"
                            disabled={ed.text.trim() === "" || ed.text === target(row)} onClick={saveEdit} />
                          <button type="button" className="button" id="mmi-wb-pr-edit-cancel" onClick={() => setEditing(null)}>Cancel</button>
                        </div>
                      </div>
                    </td>
                  </tr>
                ) : null,
                terms && terms.target === row.id ? (
                  <tr key={`${row.id}-terms`} className="mmi-wb-pr-edit-row">
                    <td colSpan={10}>
                      <TermPicker key={`${row.id}-${terms.kind}`} terms={termLists[terms.kind]} chosen={(terms.kind === "cats" ? row.cats : row.brands).map((x) => x.id)}
                        kind={terms.kind} bulk={0} mode="replace" busy={busy === row.id} onMode={() => {}} onSave={saveTerms} onCancel={() => setTerms(null)}
                        onCreate={terms.kind === "brands" ? () => openIssue(row.id, "brand") : undefined} />
                    </td>
                  </tr>
                ) : null,
                issueOpen && issueOpen.id === row.id ? (
                  <tr key={`${row.id}-issue`} className="mmi-wb-pr-edit-row mmi-wb-issue-row">
                    <td colSpan={10}>
                      <IssuePanel key={issueOpen.key} cfg={cfg} productId={row.id} issue={(row.issues ?? []).find((i) => i.key === issueOpen.key) ?? NEW_BRAND}
                        cats={row.cats.map((c) => c.id)} onTitle={() => editTitle(row)} onDone={fixed} onClose={() => setIssueOpen(null)} />
                    </td>
                  </tr>
                ) : null,
              ];
            })}
          </tbody>
        </table>
      </div>
      {page && page.pages > 1 && (
        <div className="mmi-wb-pr-pager" id="mmi-wb-pr-pager">
          <button type="button" className="button button-small" disabled={filter.page <= 1 || loading} onClick={() => setFilter((f) => ({ ...f, page: f.page - 1 }))}>‹ Previous</button>
          <span>Page {filter.page} of {page.pages}</span>
          <button type="button" className="button button-small" disabled={filter.page >= page.pages || loading} onClick={() => setFilter((f) => ({ ...f, page: f.page + 1 }))}>Next ›</button>
        </div>
      )}
    </Section>
  );
}
