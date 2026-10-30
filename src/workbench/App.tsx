/**
 * Product Workbench — find products, change them in bulk, undo.
 *
 * React owns the screen. The condition builder and the change picker are the
 * suite's shared jQuery components, hosted as Islands and read through
 * bridge.ts, so Catalog Maintenance and Field Mapping keep the same behavior.
 */
import { useCallback, useEffect, useMemo, useRef, useState, type CSSProperties } from "react";
import { createPortal } from "react-dom";
import { ApiError, api, type RuleDraft } from "./api";
import * as bridge from "./bridge";
import { IDS } from "./bridge";
import {
  EMPTY_QUICK, EMPTY_SELECTION, allPayload, buildFilter, describeFilter, filterKey, hasParameters, isSelected, lockOptions, needsLook, quickFromFilter,
  selectAllMatching, selectedCount, selectionPayload, targetText, toggleRows, type QuickFilters, type SelectionState,
} from "./logic";
import { CatalogUpkeep } from "./CatalogUpkeep";
import { LABELS, MESSAGES, fill, num, plural } from "./messages";
import { PreviewPanel } from "./PreviewPanel";
import { RecentChanges } from "./RecentChanges";
import { RuleCheck } from "./RuleCheck";
import { StandingRules, type RowNotice } from "./StandingRules";
import { OPTIONAL_COLUMNS, ResultsTable } from "./ResultsTable";
import type { ActionChoice, ConditionsState, Efficacy, Facet, Job, Preview, StandingRule, WorkbenchConfig } from "./types";
import { jobStub, useJobRunner } from "./useJobRunner";
import { SEARCH_DEBOUNCE_MS, useSearch } from "./useSearch";
import { BusyButton, Dashicon, Island, MenuButton, MultiSelect, Pager, Section } from "./ui";

type Message = { text: string; kind: "" | "error" | "ok" };
type Lifetime = "once" | "keep";

/** The Columns menu's choice, kept per browser (a convenience: a blocked store just means the default). */
const COLUMNS_KEY = "mmiWorkbenchColumns";
function storedColumns(): string[] {
  try {
    const v = JSON.parse(window.localStorage.getItem(COLUMNS_KEY) ?? "[]") as unknown;
    return Array.isArray(v) ? v.filter((c): c is string => typeof c === "string") : [];
  } catch {
    return [];
  }
}
function storeColumns(cols: string[]): void {
  try { window.localStorage.setItem(COLUMNS_KEY, JSON.stringify(cols)); } catch { /* not kept; still shown */ }
}

export function App({ cfg }: { cfg: WorkbenchConfig }) {
  /* ── Inputs ───────────────────────────────────────────────────────────── */

  const [quick, setQuick] = useState<QuickFilters>(EMPTY_QUICK);
  const [cond, setCond] = useState<ConditionsState>(() => bridge.readConditions());
  const [action, setAction] = useState<ActionChoice>(() => bridge.readAction());
  const [switches, setSwitches] = useState({ skip: false, add: false });
  const filter = useMemo(() => buildFilter(quick, cond.conditions, cond.matchLogic), [quick, cond]);
  const lock = useMemo(() => lockOptions(action, switches, cfg), [action, switches, cfg]);

  /* ── Results, selection, preview, messages ────────────────────────────── */

  const [selection, setSelection] = useState<SelectionState>(EMPTY_SELECTION);
  const [preview, setPreview] = useState<Preview | null>(null);
  const [previewBusy, setPreviewBusy] = useState(false);
  const [applying, setApplying] = useState<"selected" | "all" | null>(null);
  const [message, setMessage] = useState<Message>({ text: "", kind: "" });
  const [jobs, setJobs] = useState<Job[] | null>(null);
  const [columns, setColumnsState] = useState<string[]>(storedColumns);
  const metaCols = useMemo(() => columns.filter((c) => c.startsWith("meta:")).map((c) => c.slice(5)), [columns]);

  /* ── Standing rules: Keep applying, the rule being edited, the list ───── */

  const [lifetime, setLifetime] = useState<Lifetime>("once");
  const [editing, setEditing] = useState<StandingRule | null>(null);
  const [ruleName, setRuleName] = useState("");
  const [rules, setRules] = useState<StandingRule[] | null>(null);
  const [ruleNotice, setRuleNotice] = useState<RowNotice | null>(null);
  const [check, setCheck] = useState<Efficacy | null>(null);
  const [ruleBusy, setRuleBusy] = useState<"check" | "save" | "row" | null>(null);
  const keep = lifetime === "keep";

  const search = useSearch(
    { filter, firstIncomplete: cond.firstIncomplete, firstWithoutValue: cond.firstWithoutValue, metaCols },
    cfg.defaultPerPage,
    {
      onNewSearch: () => { setSelection(EMPTY_SELECTION); setPreview(null); },
      onError: (text) => bridge.builderNotice(text),
    },
  );
  const { view } = search;
  const total = view.result?.total ?? 0;
  const haveResults = view.filter !== null && total > 0;

  const loadJobs = useCallback(() => {
    api.jobs().then((d) => setJobs(d.jobs)).catch(() => setJobs((j) => j ?? []));
  }, []);
  const loadRules = useCallback(() => {
    api.rules().then((d) => setRules(d.rules)).catch(() => setRules((r) => r ?? []));
  }, []);

  const runner = useJobRunner((r) => {
    setApplying(null);
    setPreview(null);
    setMessage({ text: r.text, kind: r.ok ? "ok" : "error" });
    loadJobs();
    // The job is finished with these products: drop the selection, or ones that no
    // longer match stay selected out of sight. A failed run keeps it for a retry.
    if (r.ok) setSelection(EMPTY_SELECTION);
    loadRules(); // a rule's Run now is a job too
    search.refetch(); // results now show the new values
  });

  /* ── Search triggers ──────────────────────────────────────────────────── */

  // Each edit searches after the debounce; explicit actions (Enter, Clear all,
  // a chip, a facet) set the delay to 0 and bump the tick.
  // The first search (every product, before any filter) runs as the page opens.
  const nextDelay = useRef(0);
  const [tick, setTick] = useState(0);
  const searchNow = () => { nextDelay.current = 0; setTick((t) => t + 1); };
  const key = filterKey(filter);
  useEffect(() => {
    search.schedule(nextDelay.current);
    nextDelay.current = SEARCH_DEBOUNCE_MS;
    // `key` stands for the whole filter; schedule() is stable and reads the latest inputs itself.
  }, [key, cond.firstIncomplete, cond.firstWithoutValue, tick]);

  /* ── The hosted jQuery components ─────────────────────────────────────── */

  useEffect(() => {
    bridge.initActionPicker();
    setAction(bridge.readAction());
    loadJobs();
    loadRules();
    const offCond = bridge.onConditionsChange(() => setCond(bridge.readConditions()));
    const offAction = bridge.onActionChange((kind) => {
      setPreview(null);
      // Switches start off for each kind of change, so a Lock chosen for
      // prices never carries over to stock unnoticed.
      if (kind === "action") setSwitches({ skip: false, add: false });
      setAction(bridge.readAction());
    });
    return () => { offCond(); offAction(); };
  }, [loadJobs, loadRules]);

  // Force In/Out of Stock is offered only for Keep applying; a check is for one search + change.
  useEffect(() => { bridge.allowActions(cfg.stockActions, keep); }, [keep, cfg.stockActions]);
  const actionKey = `${action.action}|${JSON.stringify(action.params)}`;
  useEffect(() => { setCheck(null); }, [key, actionKey]);

  /* ── Handlers ─────────────────────────────────────────────────────────── */

  const setQ = (p: Partial<QuickFilters>) => setQuick((q) => ({ ...q, ...p }));

  const clearAll = () => {
    setQuick(EMPTY_QUICK);
    bridge.clearConditions();
    setCond(bridge.readConditions());
    searchNow();
  };

  const onHealthChip = (id: string) => { setQ({ health: [id], healthMode: "any" }); searchNow(); };

  const onFacet = (name: string, none: boolean) => {
    bridge.addCategoryCondition(name, none).then(() => { setCond(bridge.readConditions()); searchNow(); });
  };

  const busy = runner.running !== null || view.pending;
  const n = selectedCount(selection, total);
  const tooMany = total > cfg.maxSelection;
  // Browsing everything (no search yet) shows products but never offers to change them all.
  const browsing = !view.filter || !hasParameters(view.filter);

  const runPreview = () => {
    if (!view.filter) return;
    setPreviewBusy(true);
    setMessage({ text: "", kind: "" });
    api.preview(n ? selectionPayload(selection, view.filter) : allPayload(view.filter), action.action, action.params)
      .then(setPreview)
      .catch((e: ApiError) => setMessage({ text: e.message, kind: "error" }))
      .finally(() => setPreviewBusy(false));
  };

  const apply = (all: boolean) => {
    if (!view.filter || busy) return;
    const count = all ? total : n;
    const lockLines = [
      lock.skip ? fill(MESSAGES.CONFIRM_SKIP, { label: lock.label }) : "",
      lock.add ? fill(MESSAGES.CONFIRM_LOCK, { label: lock.label }) : "",
    ].filter(Boolean).join("\n");
    const summary = lockLines ? `${action.summary}\n${lockLines}` : action.summary;
    const filterText = describeFilter(view.filter, bridge.readConditions().phrases, cfg);
    const ok = window.confirm(all
      ? fill(MESSAGES.CONFIRM_APPLY_ALL, { summary, count: num(count), s: plural(count), filter: filterText })
      : fill(MESSAGES.CONFIRM_APPLY, { summary, count: num(count), s: plural(count) }));
    if (!ok) return;
    setApplying(all ? "all" : "selected");
    setMessage({ text: "", kind: "" });
    api.start(all ? allPayload(view.filter) : selectionPayload(selection, view.filter), action.action, action.params, filterText, lock)
      .then((d) => runner.run(d.job, "apply"))
      .catch((e: ApiError) => { setApplying(null); setMessage({ text: e.message, kind: "error" }); });
  };

  const onUndo = (job: Job, finishing: boolean) => {
    if (runner.running) return;
    if (!finishing && !window.confirm(fill(MESSAGES.CONFIRM_UNDO, { summary: job.summary }))) return;
    setMessage({ text: "", kind: "" });
    void runner.run(jobStub(job.id), "undo");
  };
  const onResume = (job: Job) => {
    if (runner.running) return;
    setMessage({ text: "", kind: "" });
    void runner.run(jobStub(job.id), "apply", true);
  };

  /** Show or hide optional columns; a new meta column needs its values, so the page is fetched again. */
  const setColumns = (next: string[]) => {
    const metaChanged = next.filter((c) => c.startsWith("meta:")).join("|") !== columns.filter((c) => c.startsWith("meta:")).join("|");
    setColumnsState(next);
    storeColumns(next);
    if (metaChanged) window.setTimeout(() => search.refetch(), 0); // after the new metaCols reach the search
  };

  /* ── Standing rules ───────────────────────────────────────────────────── */

  const stockOnce = !keep && cfg.stockActions.includes(action.action);

  const draft = (): RuleDraft | null => (view.filter
    ? { id: editing?.id ?? "", name: ruleName, filter: view.filter, action: action.action, params: action.params }
    : null);

  const checkRule = () => {
    const d = draft();
    if (!d) return;
    setRuleBusy("check");
    setMessage({ text: "", kind: "" });
    api.ruleCheck(d).then(setCheck)
      .catch((e: ApiError) => setMessage({ text: e.message, kind: "error" }))
      .finally(() => setRuleBusy(null));
  };

  /** Save the search + change as a standing rule; a rule the check has doubts about waits for "Save anyway". */
  const saveRule = async (anyway = false) => {
    const d = draft();
    if (!d) return;
    setRuleBusy("save");
    setMessage({ text: "", kind: "" });
    try {
      if (!anyway) {
        const e = await api.ruleCheck(d);
        setCheck(e);
        if (needsLook(e)) return;
      }
      const saved = await api.ruleSave(d);
      setMessage({ text: fill(d.id ? MESSAGES.RULE_UPDATED : MESSAGES.RULE_SAVED, { name: saved.rule.name }), kind: "ok" });
      setCheck(saved.efficacy);
      setRuleName(saved.rule.name);
      const list = await api.rules();
      setRules(list.rules);
      setEditing(list.rules.find((r) => r.id === saved.rule.id) ?? null); // further saves update it
    } catch (e) {
      setMessage({ text: (e as ApiError).message, kind: "error" });
    } finally {
      setRuleBusy(null);
    }
  };

  const stopEditing = () => { setEditing(null); setRuleName(""); setCheck(null); };

  /** Load a rule's search and change into Find products, to see its products or edit it. */
  const openRule = (r: StandingRule) => {
    setLifetime("keep");
    bridge.allowActions(cfg.stockActions, true); // before the picker is set to a stock change
    setQuick(quickFromFilter(r.filter));
    const skipped = bridge.loadConditions(r.filter.conditions, r.filter.match_logic);
    bridge.loadAction(r.action, r.params);
    setCond(bridge.readConditions());
    setAction(bridge.readAction());
    setEditing(r);
    setRuleName(r.name);
    setPreview(null);
    setMessage({ text: skipped.length ? fill(MESSAGES.RULE_SKIPPED_FIELDS, { fields: skipped.join(", ") }) : fill(MESSAGES.RULE_OPENED, { name: r.name }), kind: skipped.length ? "error" : "ok" });
    searchNow();
    document.getElementById("mmi-wb-find")?.scrollIntoView({ behavior: "smooth", block: "start" });
  };

  const rowDo = (r: StandingRule, call: Promise<unknown>, ok: string) => {
    setRuleBusy("row");
    call.then(() => { setRuleNotice(ok ? { id: r.id, text: ok, kind: "ok" } : null); loadRules(); })
      .catch((e: ApiError) => { setRuleNotice({ id: r.id, text: e.message, kind: "error" }); loadRules(); })
      .finally(() => setRuleBusy(null));
  };

  const runRule = (r: StandingRule) => {
    if (busy || ruleBusy) return;
    if (r.stock) {
      if (!window.confirm(fill(MESSAGES.CONFIRM_HOLD_RULE, { name: r.name }))) return;
      setRuleBusy("row");
      api.ruleHold(r.id)
        .then((h) => {
          setRuleNotice({ id: r.id, kind: "ok", text: fill(MESSAGES.HOLD_DONE, {
            name: r.name, changed: num(h.changed), matched: num(h.matched),
            released: h.released ? fill(MESSAGES.HOLD_RELEASED, { count: num(h.released) }) : "",
            deferred: h.deferred ? fill(MESSAGES.HOLD_DEFERRED, { count: num(h.deferred) }) : "",
          }) });
          loadRules();
        })
        .catch((e: ApiError) => setRuleNotice({ id: r.id, text: e.message, kind: "error" }))
        .finally(() => setRuleBusy(null));
      return;
    }
    // Any other change runs as an ordinary Workbench job: Recent changes can undo it.
    const count = r.efficacy?.matched ?? r.last_run?.matched ?? 0;
    if (!window.confirm(fill(MESSAGES.CONFIRM_RUN_RULE, { summary: r.change, name: r.name, count: num(count), s: plural(count) }))) return;
    const ruleLock = lockOptions({ action: r.action, params: r.params }, { skip: false, add: false }, cfg);
    setMessage({ text: "", kind: "" });
    api.start(allPayload(r.filter), r.action, r.params, `Standing rule “${r.name}”: ${r.search}`, ruleLock)
      .then((d) => runner.run(d.job, "apply"))
      .catch((e: ApiError) => setRuleNotice({ id: r.id, text: e.message, kind: "error" }));
  };

  const toggleRule = (r: StandingRule, on: boolean) => {
    // The switch moves at once; a failed save puts it back (rowDo reloads either way).
    setRules((list) => list && list.map((x) => (x.id === r.id ? { ...x, enabled: on } : x)));
    rowDo(r, api.ruleEnable(r.id, on), fill(on ? MESSAGES.RULE_ON : MESSAGES.RULE_OFF, { name: r.name }));
  };
  const moveRule = (r: StandingRule, by: -1 | 1) => rowDo(r, api.ruleMove(r.id, by), "");
  const deleteRule = (r: StandingRule) => {
    if (!window.confirm(fill(MESSAGES.CONFIRM_DELETE_RULE, { name: r.name }))) return;
    if (editing?.id === r.id) stopEditing();
    rowDo(r, api.ruleDelete(r.id), fill(MESSAGES.RULE_GONE, { name: r.name }));
  };

  /* ── Render ───────────────────────────────────────────────────────────── */

  const rows = view.result?.rows ?? [];
  const pageAll = rows.length > 0 && rows.every((r) => isSelected(selection, r.id));
  const activeFacets = (view.filter?.conditions ?? [])
    .filter((c) => c.source === "wp_taxonomy" && c.field === "product_cat" && c.operator === "equals").map((c) => c.value);
  const healthOptions = Object.fromEntries(cfg.healthChecks.map((c) => [c.id, c.label]));
  const slot = document.getElementById(IDS.SAVE_SEARCH_SLOT);

  return (
    <>
      <Section id="mmi-wb-find" icon="search" title="Find products"
        desc="Search titles and SKUs, then narrow with conditions on supplier feed fields, categories and other taxonomies, post fields and meta. Results update as you type">
        <div className="mmi-toolbar mmi-wb-quick">
          <input type="search" id="mmi-wb-q" className="mmi-wb-q" placeholder="Search titles and SKUs… (e.g. bundle)" aria-label="Search products"
            value={quick.q} onChange={(e) => setQ({ q: e.target.value })}
            onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); searchNow(); } }} />
          <label className="mmi-cond-case" title="Match case — off: &quot;bundle&quot; also finds &quot;Bundle&quot; and &quot;BUNDLE&quot;">
            <input type="checkbox" id="mmi-wb-q-case" checked={quick.qCase} onChange={(e) => setQ({ qCase: e.target.checked })} />Aa
          </label>
          <select id="mmi-wb-q-in" aria-label="Search in" value={quick.qIn} onChange={(e) => setQ({ qIn: e.target.value as QuickFilters["qIn"] })}>
            <option value="title_sku">in title or SKU</option>
            <option value="title">in title only</option>
            <option value="sku">in SKU only</option>
          </select>
          <span className="mmi-wb-divider" aria-hidden="true" />
          <select id="mmi-wb-supplier" aria-label="Supplier scope" title="Only products tracked under this supplier"
            value={quick.supplier} onChange={(e) => setQ({ supplier: e.target.value })}>
            <option value="all">All suppliers</option>
            {cfg.suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
            <option value="native">No supplier (created here)</option>
          </select>
        </div>

        <div className="mmi-toolbar mmi-wb-filters" role="group" aria-label="Filters">
          <span className="mmi-wb-filters-label">Filter</span>
          <MultiSelect id="mmi-wb-status-ms" label="Status" options={cfg.statuses} checked={quick.statuses} onChange={(v) => setQ({ statuses: v })} />
          <MultiSelect id="mmi-wb-stock-ms" label="Stock" options={cfg.stock} checked={quick.stock} onChange={(v) => setQ({ stock: v })} />
          <MultiSelect id="mmi-wb-health-ms" label="Health" options={healthOptions} checked={quick.health} onChange={(v) => setQ({ health: v })}
            before={
              <label className="mmi-wb-health-mode">Show products that{" "}
                <select id="mmi-wb-health-mode" aria-label="Health filter mode" value={quick.healthMode}
                  onChange={(e) => setQ({ healthMode: e.target.value as QuickFilters["healthMode"] })}>
                  <option value="any">fail any ticked check</option>
                  <option value="all">fail every ticked check</option>
                  <option value="none">pass every ticked check</option>
                </select>
              </label>
            } />
          <button type="button" className="button-link mmi-wb-filters-clear" title="Clear the search box, filters and conditions" onClick={clearAll}>Clear all</button>
        </div>

        <Island sourceId="mmi-wb-island-conditions" className="mmi-wb-island" />
        {slot && createPortal(<SaveSearch matchLogic={cond.matchLogic} />, slot)}
        <p className={`mmi-wb-search-status${view.loading || view.hint ? "" : " mmi-hidden"}`} id="mmi-wb-search-status" role="status">
          {view.loading ? <><span className="mmi-loading" aria-hidden="true" /> {LABELS.SEARCHING}</> : view.hint}
        </p>
      </Section>

      <Section id="mmi-wb-results-section" icon="list-view" title="Results"
        desc="Choose a change. Once applies it to the ticked products or every result now, with Undo; Keep applying saves it as a standing rule that every catalog run applies again. Categories (above the table) narrows to one"
        actions={keep ? <>
          <BusyButton id="mmi-wb-rule-check-btn" busy={ruleBusy === "check"} icon="analytics" label={LABELS.CHECK_RULE} busyLabel={LABELS.CHECKING}
            disabled={!view.filter || busy || ruleBusy !== null} onClick={checkRule} />
          <BusyButton id="mmi-wb-rule-save" className="button button-primary" busy={ruleBusy === "save"} icon="saved"
            label={editing ? LABELS.UPDATE_RULE : LABELS.SAVE_RULE} busyLabel={LABELS.SAVING}
            disabled={!view.filter || busy || ruleBusy !== null} onClick={() => void saveRule()} />
        </> : <>
          <BusyButton id="mmi-wb-preview" busy={previewBusy} icon="visibility" label={LABELS.PREVIEW} busyLabel={LABELS.PREVIEWING}
            disabled={!(n || (haveResults && !browsing)) || busy || stockOnce} onClick={runPreview} />
          <BusyButton id="mmi-wb-apply-all" busy={applying === "all"} icon="database" busyLabel={LABELS.APPLYING}
            label={haveResults ? fill(LABELS.APPLY_ALL, { count: num(total) }) : LABELS.APPLY_ALL_NONE}
            title={browsing ? MESSAGES.APPLY_ALL_BROWSING : tooMany ? fill(MESSAGES.TOO_MANY, { count: num(total), max: num(cfg.maxSelection) }) : MESSAGES.APPLY_ALL_TITLE}
            disabled={!haveResults || browsing || busy || tooMany || applying !== null || stockOnce} onClick={() => apply(true)} />
          <BusyButton id="mmi-wb-apply" className="button button-primary" busy={applying === "selected"} icon="yes"
            label={LABELS.APPLY} busyLabel={LABELS.APPLYING} disabled={!n || busy || applying !== null || stockOnce} onClick={() => apply(false)} />
        </>}>
        <div className="mmi-wb-change" id="mmi-wb-change" role="group" aria-label="Change products">
          <div className="mmi-wb-lifetime-row">
            <span className="mmi-wb-filters-label mmi-wb-change-label">Change</span>
            <div className="mmi-filter-pills mmi-wb-lifetime" role="group" aria-label="How long the change lasts" title={MESSAGES.LIFETIME_TITLE}>
              {(["once", "keep"] as Lifetime[]).map((l) => (
                <button key={l} type="button" id={`mmi-wb-lifetime-${l}`} className={`mmi-pill${lifetime === l ? " active" : ""}`} aria-pressed={lifetime === l}
                  disabled={busy || ruleBusy !== null} onClick={() => { setLifetime(l); setPreview(null); setMessage({ text: "", kind: "" }); }}>
                  {l === "once" ? MESSAGES.LIFETIME_ONCE : MESSAGES.LIFETIME_KEEP}
                </button>
              ))}
            </div>
            {keep && (
              <input type="text" id="mmi-wb-rule-name" className="mmi-wb-rule-name-input" placeholder={MESSAGES.RULE_NAME} aria-label="Rule name"
                maxLength={80} value={ruleName} onChange={(e) => setRuleName(e.target.value)} />
            )}
          </div>
          {keep && editing && (
            <div className="notice notice-info inline mmi-wb-editing" id="mmi-wb-editing">
              <p>{fill(MESSAGES.EDITING_RULE, { name: editing.name })}{" "}
                <button type="button" className="button-link" onClick={stopEditing}>{MESSAGES.STOP_EDITING}</button></p>
            </div>
          )}
          <Island sourceId="mmi-wb-island-action" className="mmi-wb-island" />
          {stockOnce && <div className="notice notice-warning inline" id="mmi-wb-stock-once"><p>{MESSAGES.STOCK_ONCE}</p></div>}
          {!keep && lock.fields.length > 0 && (
            <div className="mmi-wb-lock-options" id="mmi-wb-lock-options">
              <label className="mmi-wb-lock-option">
                <span className="mmi-toggle-switch">
                  <input type="checkbox" checked={switches.skip} onChange={(e) => setSwitches((s) => ({ ...s, skip: e.target.checked }))} />
                  <span className="mmi-toggle-slider" />
                </span>
                <span>{fill(MESSAGES.SKIP_LOCKED, { label: lock.label })}</span>
              </label>
              {!lock.always && (
                <label className="mmi-wb-lock-option">
                  <span className="mmi-toggle-switch">
                    <input type="checkbox" checked={switches.add} onChange={(e) => setSwitches((s) => ({ ...s, add: e.target.checked }))} />
                    <span className="mmi-toggle-slider" />
                  </span>
                  <span>{fill(MESSAGES.ADD_LOCK, { label: lock.label })}</span>
                </label>
              )}
            </div>
          )}
          <p className="mmi-wb-target" id="mmi-wb-target">
            {keep ? (view.filter ? fill(MESSAGES.KEEP_TARGET, { count: num(total), s: plural(total) }) : MESSAGES.KEEP_EMPTY) : targetText(selection, total, haveResults, browsing)}
          </p>
          {runner.progress && (
            <div className="mmi-wb-progress" id="mmi-wb-progress">
              <div className="mmi-progress-track" role="progressbar" aria-valuemin={0} aria-valuemax={100} aria-valuenow={runner.progress.pct}
                aria-label={runner.progress.text}>
                <div className="mmi-progress-fill" style={{ "--pct": `${runner.progress.pct}%` } as CSSProperties} />
              </div>
              <span className="mmi-wb-progress-text">{runner.progress.text}</span>
              {runner.progress.kind === "apply" && <button type="button" className="button-link mmi-wb-stop" onClick={runner.stop}>Stop</button>}
            </div>
          )}
          <div className={`mmi-wb-message${message.kind === "error" ? " is-error" : message.kind === "ok" ? " is-success" : ""}`} id="mmi-wb-message" role="status">
            {message.text}
          </div>
          {!keep && preview && <PreviewPanel preview={preview} lock={lock} />}
          {keep && check && <RuleCheck check={check} saving={ruleBusy !== null} onSaveAnyway={() => void saveRule(true)} />}
        </div>

        {!view.result && <div className="mmi-wb-empty" id="mmi-wb-empty">{MESSAGES.EMPTY}</div>}
        <div className={`mmi-wb-results${view.result ? "" : " mmi-hidden"}`} id="mmi-wb-results">
          <div className="mmi-toolbar mmi-wb-selection">
            <span className="mmi-wb-count" id="mmi-wb-count">{view.result ? fill(MESSAGES.FOUND, { count: num(total), s: plural(total) }) : ""}</span>
            <span className="mmi-wb-selected" id="mmi-wb-selected">{n ? fill(MESSAGES.SELECTED, { count: num(n) }) : ""}</span>
            {selection.mode !== "filter" && pageAll && total > rows.length && (
              <button type="button" className="button-link" id="mmi-wb-select-all-matching" onClick={() => setSelection(selectAllMatching())}>
                {fill(MESSAGES.SELECT_ALL, { count: num(total) })}
              </button>
            )}
            {n > 0 && <button type="button" className="button-link" id="mmi-wb-clear-selection" onClick={() => setSelection(EMPTY_SELECTION)}>Clear selection</button>}
            {view.result && view.result.facets.length > 0 && (
              <CategoryMenu facets={view.result.facets} active={activeFacets} onPick={onFacet} />
            )}
            <ColumnsMenu columns={columns} max={cfg.maxMetaColumns} onChange={setColumns} />
            <label className="mmi-pagination-size-label mmi-wb-per-page">Per page:{" "}
              <select className="mmi-pagination-size" id="mmi-wb-per-page" value={view.perPage}
                onChange={(e) => search.refetch({ perPage: Number(e.target.value) || cfg.defaultPerPage, page: 1 })}>
                {cfg.perPageOptions.map((o) => <option key={o} value={o}>{o}</option>)}
              </select>
            </label>
          </div>
          <ResultsTable result={view.result} sort={view.sort} dir={view.dir} loading={view.loading} selection={selection}
            columns={columns} reservedMeta={cfg.reservedMetaKeys} lockLabels={cfg.lockLabels}
            onSort={search.sortBy} onToggle={(ids, on) => setSelection((s) => toggleRows(s, ids, on))} onHealthChip={onHealthChip}
            onRowSaved={(row) => { search.replaceRow(row); loadJobs(); }} />
          {view.result && <Pager pages={view.result.pages} current={view.page} onPage={(p) => search.refetch({ page: p })} />}
        </div>
      </Section>

      <StandingRules rules={rules} busy={busy || ruleBusy !== null} editingId={keep && editing ? editing.id : ""} notice={ruleNotice}
        onOpen={openRule} onRun={runRule} onToggle={toggleRule} onMove={moveRule} onDelete={deleteRule} />

      <CatalogUpkeep onRunFinished={loadRules} />

      <RecentChanges jobs={jobs} busy={runner.running !== null} onUndo={onUndo} onResume={onResume} />
    </>
  );
}

/** "Save search": keeps the builder's conditions as a named search, loadable in every condition builder. */
function SaveSearch({ matchLogic }: { matchLogic: string }) {
  const [open, setOpen] = useState(false);
  const [name, setName] = useState("");
  const [saving, setSaving] = useState(false);
  const input = useRef<HTMLInputElement>(null);
  useEffect(() => { if (open) input.current?.focus(); }, [open]);

  const save = () => {
    const bad = bridge.incompleteMessage();
    if (bad) { bridge.builderNotice(bad); return; }
    const label = name.trim();
    setSaving(true);
    api.saveSearch(label, matchLogic, bridge.readConditions().conditions)
      .then(() => {
        bridge.refreshLibrary();
        setOpen(false);
        bridge.builderNotice(fill(MESSAGES.SAVED_SEARCH, { name: label }));
      })
      .catch((e: ApiError) => bridge.builderNotice(e.message))
      .finally(() => setSaving(false));
  };

  if (!open) {
    return (
      <button type="button" className="mmi-cb-btn-secondary" id="mmi-wb-save-search" onClick={() => { setName(""); setOpen(true); }}
        title="Keep these conditions as a named search, loadable from Load saved conditions here and in Field Mapping">
        <Dashicon name="saved" /> Save search
      </button>
    );
  }
  return (
    <span className="mmi-wb-save-search-form" id="mmi-wb-save-search-form">
      <input ref={input} type="text" placeholder={MESSAGES.SAVE_SEARCH_NAME} aria-label="Search name" maxLength={80} value={name}
        onChange={(e) => setName(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === "Enter") { e.preventDefault(); save(); }
          if (e.key === "Escape") setOpen(false);
        }} />
      <button type="button" className="button button-small" disabled={saving} onClick={save}>Save</button>
      <button type="button" className="button-link" onClick={() => setOpen(false)}>Cancel</button>
    </span>
  );
}

/**
 * The Columns menu: optional columns to show, plus any meta key as a column
 * ("Add a meta key…"). Unticking a meta key removes it.
 */
function ColumnsMenu({ columns, max, onChange }: { columns: string[]; max: number; onChange: (next: string[]) => void }) {
  const [key, setKey] = useState("");
  const [note, setNote] = useState("");
  const metas = columns.filter((c) => c.startsWith("meta:"));
  const options: Record<string, string> = { ...OPTIONAL_COLUMNS };
  metas.forEach((c) => { options[c] = `Meta: ${c.slice(5)}`; });
  const add = () => {
    const k = key.trim();
    if (!k) return;
    if (metas.length >= max) { setNote(fill(MESSAGES.META_COLUMN_MAX, { max })); return; }
    if (!columns.includes(`meta:${k}`)) onChange([...columns, `meta:${k}`]);
    setKey("");
    setNote("");
  };
  return (
    <MultiSelect id="mmi-wb-columns-ms" label={MESSAGES.COLUMNS} options={options} checked={columns}
      onChange={(next) => onChange(Object.keys(options).filter((c) => next.includes(c)))}
      before={
        <span className="mmi-wb-meta-col-add">
          <input type="text" id="mmi-wb-meta-col" placeholder={MESSAGES.ADD_META_COLUMN} aria-label={MESSAGES.ADD_META_COLUMN} value={key}
            onChange={(e) => setKey(e.target.value)} onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); add(); } }} />
          <button type="button" className="button button-small" onClick={add}>Add</button>
          {note && <span className="mmi-wb-none">{note}</span>}
        </span>
      } />
  );
}

/**
 * "Categories" in the results toolbar: how the results are categorized
 * (name and count, most first), in a menu instead of a row of chips. Picking
 * one narrows the search to it, as a category condition. A filter box helps
 * when the list is long.
 */
function CategoryMenu({ facets, active, onPick }: {
  facets: Facet[]; active: string[]; onPick: (name: string, none: boolean) => void;
}) {
  const [q, setQ] = useState("");
  const needle = q.trim().toLowerCase();
  const shown = needle ? facets.filter((f) => f.name.toLowerCase().includes(needle)) : facets;
  return (
    <MenuButton id="mmi-wb-facets" label={MESSAGES.CATEGORIES} count={active.length} title={MESSAGES.CATEGORIES_TITLE}>
      {(close) => (
        <>
          {facets.length > 10 && (
            <input type="search" className="mmi-wb-facet-search" placeholder={MESSAGES.CATEGORIES_SEARCH} aria-label={MESSAGES.CATEGORIES_SEARCH}
              value={q} onChange={(e) => setQ(e.target.value)} autoFocus />
          )}
          <div className="mmi-wb-facet-list" role="menu">
            {shown.map((f) => (
              <button key={`${f.id}-${f.name}`} type="button" role="menuitem"
                className={`mmi-wb-facet${active.includes(f.name) ? " is-active" : ""}`}
                title={fill(MESSAGES.FACET_TITLE, { name: f.name })} onClick={() => { onPick(f.name, f.id === 0); close(); }}>
                <span>{f.name}</span> <b>{num(f.count)}</b>
              </button>
            ))}
            {!shown.length && <span className="mmi-wb-none">{MESSAGES.NO_MATCH}</span>}
          </div>
        </>
      )}
    </MenuButton>
  );
}
