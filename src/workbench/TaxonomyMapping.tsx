/**
 * Category & brand mapping (moved from the Import tab's Taxonomy Mapping panel, 2026-11-25): each supplier's brand
 * and category values, mapped to the store's terms. A mapping keeps applying: every import gives the value's products
 * its term, and saving one applies it to products already in the store (unless the field is locked on a product).
 *
 * The split view (ui.tsx): sources on the left, the open one beside them.
 * - A source's values: the term field (pick, or create the name), Skip, Clear, Exclude/Restore, a redirect to another
 *   taxonomy, and per-profile variations. Chips: Unmapped · Mapped · Deleted terms · Excluded · All.
 * - Alias rules: one rule (contains "roland") catches every spelling; first match wins, after exact mappings.
 * - Profile variations: every profile-only override, to review and remove in bulk.
 * - A data source with no brand or category field declared opens on Set up: what its file offers, declared in place.
 *
 * The scan reads the supplier feeds (cached 5 minutes server side), so it runs when the section is first opened, one
 * request; Reload reads them again. Apply all to existing products runs in the background.
 */
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { ApiError } from "./api";
import { page } from "./globals";
import { landing } from "./landing";
import { refreshCounts, useSectionVisible } from "./nav";
import { taxmapApi, type Applied, type FlatVariation, type SetupData, type ValueRef } from "./taxmapApi";
import {
  applyCase, CASE_BUTTONS, chipCounts, CHIPS, EMPTY_RULE, inSource, nextSort, replaceRow, rowKey, RULE_OPERATORS, ruleMatches, sourceKey, statusLabel,
  statusOf, suggestTermName, visibleRows, type AliasRule, type CaseMode, type Chip, type Sort, type SortCol, type SourceDef, type ValueRow, type Variant,
} from "./taxmapLogic";
import { findTerms, forgetTermTree, TermField } from "./TermField";
import type { TaxmapConfig } from "./types";
import { BusyButton, Dashicon, Pager, Section, SortTh, useAddressParam, useColumnResize, useListKeys } from "./ui";

interface Notice { text: string; kind: "ok" | "error" | "warning" }

const PER_PAGE = 100;
const msg = (e: unknown) => (e instanceof ApiError ? e.message : String(e));
const plural = (n: number, one: string, many = `${one}s`) => `${n.toLocaleString("en-US")} ${n === 1 ? one : many}`;
const ALL = "all";
const RULES = "rules";
const VARIATIONS = "variations";
const SETUP = "setup:";

/** What saving a mapping did to products already in the store, said in one line ("" when nothing to say). */
function appliedText(a: Applied | null, value: string, term: string): string {
  if (!a) return "";
  if (a.too_many > 0) return a.queued ? `${plural(a.too_many, "existing product")} have “${value}”: they are being updated in the background.`
    : `${plural(a.too_many, "existing product")} have “${value}”: too many to update now. Use Apply all to existing products.`;
  if (a.updated > 0 || a.locked > 0) return `“${term}” applied to ${plural(a.updated, "existing product")}.${a.locked ? ` ${a.locked} skipped (field locked on the product).` : ""}`;
  return "";
}

/** A link's wb_source: "supplier|field", "setup:supplier", rules, variations, or a bare supplier (the Import tab's links). */
function resolveOpen(raw: string, sources: SourceDef[]): string {
  if (!raw || raw === ALL || raw === RULES || raw === VARIATIONS || raw.startsWith(SETUP)) return raw || ALL;
  if (sources.some((s) => sourceKey(s) === raw)) return raw;
  const first = sources.find((s) => s.supplier === raw);
  return first ? sourceKey(first) : `${SETUP}${raw}`;
}

export function TaxonomyMapping({ cfg }: { cfg: TaxmapConfig }) {
  const visible = useSectionVisible();
  const suppliers = page().mmiWorkbench?.suppliers ?? [];
  const [sources, setSources] = useState<SourceDef[]>(cfg.sources);
  const [rules, setRules] = useState<AliasRule[]>(cfg.aliasRules);
  const [rows, setRows] = useState<ValueRow[] | null>(null);
  const [scanning, setScanning] = useState(false);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [open, setOpen] = useState(() => resolveOpen(landing().source, cfg.sources));
  const [applying, setApplying] = useState(false);
  const [armedApply, setArmedApply] = useState(false);
  const asked = useRef(false);
  const taxLabel = useCallback((slug: string) => cfg.taxonomies.find((t) => t.slug === slug)?.label ?? slug, [cfg.taxonomies]);

  const scan = useCallback(async (fresh: boolean) => {
    setScanning(true);
    try {
      setRows((await taxmapApi.scan(fresh)).values ?? []);
      refreshCounts();
    } catch (e) { setRows((r) => r ?? []); setNotice({ text: msg(e), kind: "error" }); } finally { setScanning(false); }
  }, []);
  useEffect(() => {
    if (!visible || asked.current) return;
    asked.current = true;
    void scan(false);
  }, [visible, scan]);
  useAddressParam("wb_source", open === ALL ? null : open, visible);

  /* ── Changes to one value, kept in the list ───────────────────────────── */

  const update = (r: ValueRow, next: (r: ValueRow) => ValueRow) => setRows((l) => (l ? replaceRow(l, rowKey(r), next) : l));
  const refOf = (r: ValueRow, tax = r.wc_taxonomy): ValueRef => ({ supplier: r.supplier, source_field: r.source_field, source_value: r.source_value, wc_taxonomy: tax });
  const act = async (work: () => Promise<string | void>) => {
    setNotice(null);
    try { const t = await work(); if (t) setNotice({ text: t, kind: "ok" }); } catch (e) { setNotice({ text: msg(e), kind: "error" }); }
  };
  const save = (r: ValueRow, termId: number, termName: string) => act(async () => {
    const d = await taxmapApi.save(refOf(r), termId, termName);
    if (termId === 0) forgetTermTree(r.wc_taxonomy);
    update(r, (x) => ({ ...x, mapping_id: d.mapping_id, wc_term_id: d.wc_term_id, wc_term_name: d.wc_term_id === -1 ? "" : d.wc_term_name, missing_term_id: 0, via_rule: false }));
    refreshCounts();
    return termId === -1 ? `“${r.source_value}” is skipped: imports leave it unmapped.` : appliedText(d.applied, r.source_value, d.wc_term_name) || `“${r.source_value}” → ${d.wc_term_name}.`;
  });
  const clear = (r: ValueRow) => act(async () => {
    if (r.mapping_id) await taxmapApi.remove(r.mapping_id);
    update(r, (x) => ({ ...x, mapping_id: 0, wc_term_id: 0, wc_term_name: "", missing_term_id: 0, via_rule: false }));
    refreshCounts();
  });
  /** Send a value to another taxonomy: a mapping saved under the old one no longer applies, so it goes. */
  const retarget = (r: ValueRow, tax: string) => act(async () => {
    if (r.mapping_id) await taxmapApi.remove(r.mapping_id);
    const home = r.redirected_from || r.wc_taxonomy;
    update(r, (x) => ({ ...x, wc_taxonomy: tax, redirected_from: tax === home ? "" : home, mapping_id: 0, wc_term_id: 0, wc_term_name: "", missing_term_id: 0, via_rule: false }));
  });
  const setExcluded = (r: ValueRow, on: boolean) => act(async () => {
    // Keyed by the taxonomy the field feeds, not a redirect's.
    const d = await taxmapApi.setExcluded(refOf(r, r.redirected_from || r.wc_taxonomy), on);
    update(r, (x) => ({ ...x, excluded: d.excluded, excluded_at: d.excluded_at, excluded_by: d.excluded_by }));
    refreshCounts();
    return on ? `“${r.source_value}” is excluded: imports leave it unmapped. Restore it from Excluded at any time.` : `“${r.source_value}” is back in the mapper.`;
  });
  const saveVariant = (r: ValueRow, profile: { id: string; name: string }, termId: number, termName: string) => act(async () => {
    const d = await taxmapApi.save(refOf(r), termId, termName, profile.id);
    if (termId === 0) forgetTermTree(r.wc_taxonomy);
    const v: Variant = { mapping_id: d.mapping_id, profile_id: profile.id, profile_label: profile.name, wc_term_id: d.wc_term_id, wc_term_name: d.wc_term_name, missing_term_id: 0 };
    update(r, (x) => ({ ...x, variants: [...x.variants.filter((y) => y.profile_id !== profile.id), v] }));
    return `Saved for ${profile.name} only. Products that profile creates or updates from now on get it; existing products aren't changed.`;
  });
  const deleteVariant = (r: ValueRow, v: Variant) => act(async () => {
    await taxmapApi.remove(v.mapping_id);
    update(r, (x) => ({ ...x, variants: x.variants.filter((y) => y.mapping_id !== v.mapping_id) }));
  });
  const applyAll = async () => {
    if (!armedApply) { setArmedApply(true); return; }
    setArmedApply(false);
    setApplying(true);
    await act(async () => (await taxmapApi.applyAll()).message);
    setApplying(false);
  };

  /* ── The list ─────────────────────────────────────────────────────────── */

  const counts = useMemo(() => {
    const m = new Map<string, { unmapped: number; values: number }>();
    for (const r of rows ?? []) {
      if (r.excluded) continue;
      const k = sourceKey(r);
      const c = m.get(k) ?? { unmapped: 0, values: 0 };
      c.values++;
      if (statusOf(r) === "unmapped") c.unmapped++;
      m.set(k, c);
    }
    return m;
  }, [rows]);
  const allUnmapped = [...counts.values()].reduce((n, c) => n + c.unmapped, 0);
  const [lSort, setLSort] = useState<{ col: "source" | "unmapped"; desc: boolean } | null>(null);
  const listed = useMemo(() => {
    const l = [...sources];
    const dir = lSort?.desc ? -1 : 1;
    if (lSort?.col === "unmapped") return l.sort((a, b) => ((counts.get(sourceKey(a))?.unmapped ?? 0) - (counts.get(sourceKey(b))?.unmapped ?? 0)) * dir || a.label.localeCompare(b.label));
    if (lSort?.col === "source") return l.sort((a, b) => a.label.localeCompare(b.label) * dir);
    return l;
  }, [sources, lSort, counts]);
  const unset = suppliers.filter((s) => !sources.some((x) => x.supplier === s.id));
  const keys = [ALL, ...listed.map(sourceKey), ...unset.map((s) => `${SETUP}${s.id}`)];
  const listRef = useRef<HTMLTableElement>(null);
  useColumnResize(listRef, true);
  const onKeys = useListKeys(listRef, keys, open, setOpen);
  const sortListBy = (col: "source" | "unmapped") => setLSort((s) => (!s || s.col !== col ? { col, desc: col === "unmapped" } : s.desc === (col === "unmapped") ? { col, desc: !s.desc } : null));

  const openSource = sources.find((s) => sourceKey(s) === open) ?? null;
  const openRows = rows ? (open === ALL ? rows : openSource ? inSource(rows, open) : []) : null;
  const profiles = cfg.profiles;
  const defaultProfile = landing().profile && profiles.some((p) => p.id === landing().profile) ? landing().profile : "";

  const actions = (
    <>
      <button type="button" className={`button button-primary${armedApply ? " is-armed" : ""}${applying ? " mmi-is-loading" : ""}`} id="mmi-wb-tm-apply" disabled={applying}
        title="Give products already in the store the term each saved mapping and alias rule names; runs in the background" onClick={() => void applyAll()}>
        {applying ? <span className="mmi-loading" aria-hidden="true" /> : <Dashicon name="database-import" />} {armedApply ? "Click again: apply every mapping" : "Apply all to existing products"}
      </button>
      <BusyButton busy={scanning} icon="update" label="Reload" busyLabel="Reading feeds…" id="mmi-wb-tm-reload" title="Read the supplier feeds again" onClick={() => void scan(true)} />
    </>
  );

  return (
    <Section id="mmi-wb-mapping" icon="networking" title="Category & brand mapping" actions={actions}
      desc="Each supplier's brand and category values, mapped to your own terms. Saving a mapping gives the value's products in the store its term (unless the field is locked on a product), and every import keeps applying it">
      {notice && <div className={`notice notice-${notice.kind === "error" ? "error" : notice.kind === "warning" ? "warning" : "success"} inline`} role="status" id="mmi-wb-tm-notice"><p>{notice.text}</p></div>}
      <div className="mmi-wb-split mmi-wb-tm-split">
        <div className="mmi-wb-split-list" id="mmi-wb-tm-list">
          <div className="mmi-table-scroll-wrapper mmi-wb-split-scroll">
            <table ref={listRef} className="wp-list-table widefat striped mmi-wb-pr-table" id="mmi-wb-tm-sources">
              <thead>
                <tr>
                  <SortTh col="source" label="Source" active={lSort?.col === "source"} desc={!!lSort?.desc} onSort={sortListBy} />
                  <SortTh col="unmapped" label="To map" title="Values with no term yet" active={lSort?.col === "unmapped"} desc={!!lSort?.desc} onSort={sortListBy} className="mmi-wb-split-num" />
                </tr>
              </thead>
              <tbody onKeyDown={onKeys}>
                <SourceRow k={ALL} open={open} onOpen={setOpen} name="All sources" meta={rows ? plural(rows.filter((r) => !r.excluded).length, "value") : "Reading feeds…"} n={rows ? allUnmapped : null} />
                {listed.map((s) => {
                  const c = counts.get(sourceKey(s));
                  return (
                    <SourceRow key={sourceKey(s)} k={sourceKey(s)} open={open} onOpen={setOpen} name={s.label} n={rows ? c?.unmapped ?? 0 : null}
                      title={s.suggested ? "No import profile maps this field yet. Mappings saved here are used once a profile maps it in Field Mapping" : undefined}
                      meta={<><code>{s.source_field}</code> → {taxLabel(s.wc_taxonomy)}</>} />
                  );
                })}
                {unset.map((s) => (
                  <SourceRow key={s.id} k={`${SETUP}${s.id}`} open={open} onOpen={setOpen} name={s.name} n={null}
                    meta={<span className="mmi-wb-mp-warn">No brand or category field yet: Set up</span>} />
                ))}
              </tbody>
            </table>
          </div>
          <div className="mmi-wb-tm-tools" role="group" aria-label="Across every source">
            <button type="button" className={`button${open === RULES ? " is-active" : ""}`} id="mmi-wb-tm-open-rules" aria-current={open === RULES ? "true" : undefined} onClick={() => setOpen(RULES)}>
              <Dashicon name="randomize" /> Alias rules{rules.length ? <span className="mmi-pill-count">{rules.length}</span> : null}
            </button>
            <button type="button" className={`button${open === VARIATIONS ? " is-active" : ""}`} id="mmi-wb-tm-open-variations" aria-current={open === VARIATIONS ? "true" : undefined} onClick={() => setOpen(VARIATIONS)}>
              <Dashicon name="networking" /> Profile variations
            </button>
          </div>
          {keys.length > 1 && <p className="description mmi-wb-split-keys">↑ and ↓ move between sources.</p>}
        </div>

        <div className="mmi-wb-split-work" id="mmi-wb-tm-work">
          {open === RULES ? <RulesPanel key="rules" rules={rules} rows={rows ?? []} cfg={cfg} suppliers={suppliers} taxLabel={taxLabel}
            onSaved={(r, t) => { setRules(r); setNotice({ text: t, kind: "ok" }); void scan(false); }} onError={(t) => setNotice({ text: t, kind: "error" })} />
            : open === VARIATIONS ? <VariationsPanel key="variations" onChanged={() => void scan(false)} onNotice={setNotice} />
            : open.startsWith(SETUP) ? <SetupCard key={open} supplierId={open.slice(SETUP.length)} name={suppliers.find((s) => `${SETUP}${s.id}` === open)?.name ?? open.slice(SETUP.length)}
                onDeclared={(src) => { setSources((l) => [...l, src]); setOpen(sourceKey(src)); setNotice({ text: `${src.label}: its ${src.source_field} values are listed for mapping.`, kind: "ok" }); void scan(true); }} />
            : openRows && openSource && openRows.length === 0 && !scanning
              ? <SetupCard key={open} supplierId={openSource.supplier} name={openSource.label} onDeclared={(src) => { setSources((l) => [...l, src]); setOpen(sourceKey(src)); void scan(true); }} />
            : (
              <ValuesPanel key={open} title={open === ALL ? "All sources" : openSource?.label ?? open} source={openSource} rows={openRows} showSource={open === ALL}
                taxLabel={taxLabel} taxonomies={cfg.taxonomies} profiles={profiles} defaultProfile={defaultProfile} sources={sources}
                on={{ save, clear, retarget, setExcluded, saveVariant, deleteVariant }} />
            )}
        </div>
      </div>
    </Section>
  );
}

function SourceRow({ k, open, onOpen, name, meta, n, title }: { k: string; open: string; onOpen: (k: string) => void; name: string; meta: ReactNode; n: number | null; title?: string }) {
  return (
    <tr data-key={k} className={`mmi-wb-split-row${open === k ? " is-open" : ""}`} title={title} onClick={() => onOpen(k)}>
      <td>
        <button type="button" className="button-link mmi-wb-split-name" aria-current={open === k ? "true" : undefined}>{name}</button>
        <div className="mmi-wb-split-meta">{meta}</div>
      </td>
      <td className="mmi-wb-split-num">{n === null ? <span className="description">–</span> : n ? n.toLocaleString("en-US") : <span className="description">0</span>}</td>
    </tr>
  );
}

/* ── A source's values ────────────────────────────────────────────────────── */

interface RowActions {
  save: (r: ValueRow, termId: number, name: string) => Promise<void>;
  clear: (r: ValueRow) => Promise<void>;
  retarget: (r: ValueRow, tax: string) => Promise<void>;
  setExcluded: (r: ValueRow, on: boolean) => Promise<void>;
  saveVariant: (r: ValueRow, p: { id: string; name: string }, termId: number, name: string) => Promise<void>;
  deleteVariant: (r: ValueRow, v: Variant) => Promise<void>;
}

function ValuesPanel({ title, source, rows, showSource, taxLabel, taxonomies, profiles, defaultProfile, sources, on }: {
  title: string; source: SourceDef | null; rows: ValueRow[] | null; showSource: boolean; taxLabel: (s: string) => string;
  taxonomies: { slug: string; label: string }[]; profiles: { id: string; name: string }[]; defaultProfile: string; sources: SourceDef[]; on: RowActions;
}) {
  const [chip, setChip] = useState<Chip>("unmapped");
  const [q, setQ] = useState("");
  const [sort, setSort] = useState<Sort | null>(null);
  const [pageNo, setPageNo] = useState(1);
  const [expanded, setExpanded] = useState<{ key: string; what: "variants" | "create" } | null>(null);
  const counts = rows ? chipCounts(rows) : null;
  const list = rows ? visibleRows(rows, chip, q, sort) : [];
  const pages = Math.max(1, Math.ceil(list.length / PER_PAGE));
  const shown = list.slice((Math.min(pageNo, pages) - 1) * PER_PAGE, Math.min(pageNo, pages) * PER_PAGE);
  const tableRef = useRef<HTMLTableElement>(null);
  useColumnResize(tableRef, shown.length > 0);
  useEffect(() => { setPageNo(1); }, [chip, q, sort]);
  const sortBy = (col: SortCol) => setSort((s) => nextSort(s, col));
  const sourceLabel = (r: ValueRow) => sources.find((s) => sourceKey(s) === sourceKey(r))?.label ?? r.supplier;

  return (
    <div className="mmi-wb-tm-values">
      <div className="mmi-wb-split-head">
        <div className="mmi-wb-split-title">
          <h4>{title}</h4>
          <p className="description">{source ? <><code>{source.source_field}</code> → {taxLabel(source.wc_taxonomy)}{source.suggested ? ". No import profile maps this field yet: mappings saved here are used once one does (Field Mapping)" : ""}</> : "Every source's values together"}</p>
        </div>
      </div>
      <div className="mmi-wb-tm-filters">
        <div className="mmi-filter-pills" role="group" aria-label="Which values">
          {CHIPS.map((c) => (
            <button key={c.value} type="button" className={`mmi-pill${chip === c.value ? " active" : ""}`} aria-pressed={chip === c.value} data-chip={c.value} title={c.hint}
              hidden={(c.value === "missing" || c.value === "excluded") && !counts?.[c.value] && chip !== c.value} onClick={() => setChip(c.value)}>
              {c.label} <span className="mmi-pill-count">{counts ? counts[c.value].toLocaleString("en-US") : "…"}</span>
            </button>
          ))}
        </div>
        <input type="search" id="mmi-wb-tm-q" aria-label="Find values" placeholder="Value, product or term" value={q} onChange={(e) => setQ(e.target.value)} />
      </div>
      <div className="mmi-table-scroll-wrapper">
        <table ref={tableRef} className="wp-list-table widefat striped mmi-wb-tm-table" id="mmi-wb-tm-table">
          <thead>
            <tr>
              <SortTh col="value" label="Supplier value" active={sort?.col === "value"} desc={!!sort?.desc} onSort={sortBy} />
              <SortTh col="count" label="Products" title="Records in the supplier's feed with this value" active={sort?.col === "count"} desc={!!sort?.desc} onSort={sortBy} className="mmi-wb-split-num" />
              <th scope="col" className="mmi-wb-tm-col-term"><span className="screen-reader-text">Term</span>Maps to</th>
              <th scope="col" className="mmi-wb-job-actions"><span className="screen-reader-text">Actions</span></th>
            </tr>
          </thead>
          <tbody id="mmi-wb-tm-rows">
            {!rows && <tr><td colSpan={4} className="mmi-wb-empty-cell">Reading the supplier feeds…</td></tr>}
            {rows && !list.length && <tr><td colSpan={4} className="mmi-wb-empty-cell">{q ? "No value matches." : chip === "unmapped" ? "Every value here is mapped." : "Nothing here."}</td></tr>}
            {shown.map((r) => (
              <ValueLine key={rowKey(r)} r={r} sourceLabel={showSource ? sourceLabel(r) : ""} taxLabel={taxLabel} taxonomies={taxonomies} on={on}
                expanded={expanded?.key === rowKey(r) ? expanded.what : null}
                onExpand={(what) => setExpanded((x) => (x?.key === rowKey(r) && x.what === what ? null : what ? { key: rowKey(r), what } : null))}
                profiles={profiles} defaultProfile={defaultProfile} />
            ))}
          </tbody>
        </table>
      </div>
      {pages > 1 && <Pager pages={pages} current={Math.min(pageNo, pages)} onPage={setPageNo} />}
    </div>
  );
}

/** One value's row, and under it (when open) its create-term editor or its profile variations. */
function ValueLine({ r, sourceLabel, taxLabel, taxonomies, on, expanded, onExpand, profiles, defaultProfile }: {
  r: ValueRow; sourceLabel: string; taxLabel: (s: string) => string; taxonomies: { slug: string; label: string }[]; on: RowActions;
  expanded: "variants" | "create" | null; onExpand: (w: "variants" | "create" | null) => void; profiles: { id: string; name: string }[]; defaultProfile: string;
}) {
  const [busy, setBusy] = useState(false);
  const run = (p: Promise<void>) => { setBusy(true); void p.finally(() => setBusy(false)); };
  const s = statusOf(r);
  const home = r.redirected_from || r.wc_taxonomy;
  const notes: ReactNode[] = [];
  if (s === "excluded") {
    notes.push(<span key="x" className="mmi-wb-tm-note"><Dashicon name="hidden" /> Excluded{r.excluded_at ? ` on ${r.excluded_at.slice(0, 10)}` : ""}{r.excluded_by ? ` by ${r.excluded_by}` : ""}. Imports leave this value unmapped and its products' terms as they are.{r.wc_term_id > 0 && !r.via_rule ? ` Its mapping to “${r.wc_term_name}” is kept and applies again once restored.` : ""}</span>);
  } else {
    if (r.missing_term_id > 0) {
      notes.push(<span key="m" className="mmi-wb-tm-note is-missing"><Dashicon name="warning" /> The mapped term (#{r.missing_term_id}) was deleted from {taxLabel(r.wc_taxonomy)}. {r.auto_create ? `The next import creates a new term named “${r.source_value}”.` : "Imports skip this value until you pick another term or clear the mapping."}</span>);
    }
    if (r.redirected_from && s === "mapped") {
      notes.push(<span key="r" className="mmi-wb-tm-note"><Dashicon name="randomize" /> Sent to {taxLabel(r.wc_taxonomy)} instead of {taxLabel(r.redirected_from)}. Imports add this term and leave {taxLabel(r.redirected_from)} unchanged.</span>);
    }
  }
  const variants = r.variants.length;
  return (
    <>
      <tr data-key={rowKey(r)} data-status={s} className={`mmi-wb-tm-row is-${s}${busy ? " mmi-wb-loading" : ""}`}>
        <td>
          <strong className="mmi-wb-tm-value">{r.source_value}</strong>
          <div className="mmi-wb-split-meta">
            <span className={`mmi-badge inline ${s === "mapped" ? "success" : s === "unmapped" ? (r.missing_term_id ? "error" : "warning") : s === "skip" ? "info" : ""}`} data-status-badge>{statusLabel(r)}</span>
            {sourceLabel && <span>{sourceLabel}</span>}
            {r.sample_products.length > 0 && <span className="mmi-wb-tm-samples" title={r.sample_products.join("\n")}>{r.sample_products.join(" · ")}</span>}
          </div>
        </td>
        <td className="mmi-wb-split-num">{r.count.toLocaleString("en-US")}</td>
        <td className="mmi-wb-tm-col-term">
          <TermField taxonomy={r.wc_taxonomy} taxonomyLabel={taxLabel(r.wc_taxonomy)} value={s === "skip" ? "" : r.wc_term_name} currentId={r.wc_term_id}
            label={`Term for ${r.source_value}`} disabled={s === "excluded" || s === "skip" || busy} missing={r.missing_term_id > 0}
            placeholder={s === "excluded" ? "(excluded)" : s === "skip" ? "(skipped)" : r.missing_term_id > 0 ? "Pick a replacement term…" : "Type to search terms…"}
            onPick={(id, name) => run(on.save(r, id, name))} onCreate={(name) => run(on.save(r, 0, name))} />
          <div className="mmi-wb-tm-term-under">
            <label className="mmi-wb-tm-tax" title="A value sent in the wrong field (a category name sent as a brand) can go to another taxonomy">
              <span>in</span>
              <select aria-label={`Taxonomy for ${r.source_value}`} value={r.wc_taxonomy} disabled={s === "excluded" || busy} onChange={(e) => run(on.retarget(r, e.target.value))}>
                {taxonomies.map((t) => <option key={t.slug} value={t.slug}>{t.label}{t.slug === home && r.redirected_from ? " (its field's)" : ""}</option>)}
              </select>
            </label>
            {s !== "excluded" && (
              <button type="button" className={`button-link mmi-wb-tm-variants${variants ? " has-variants" : ""}`} aria-expanded={expanded === "variants"} onClick={() => onExpand("variants")}>
                {variants ? <><Dashicon name="randomize" /> {plural(variants, "profile variation")}</> : <><Dashicon name="plus-alt2" /> Add profile variation</>}
              </button>
            )}
          </div>
          {notes.length > 0 && <div className="mmi-wb-tm-notes">{notes}</div>}
        </td>
        <td className="mmi-wb-job-actions">
          {s === "excluded" ? (
            <button type="button" className="button button-small" data-act="restore" disabled={busy} title="Put this value back in the mapper" onClick={() => run(on.setExcluded(r, false))}><Dashicon name="visibility" /> Restore</button>
          ) : (
            <>
              {s === "unmapped" && !r.via_rule && <button type="button" className="button button-small" data-act="create" aria-expanded={expanded === "create"} disabled={busy} title="Create a term from this value (edit its name first) and map it" onClick={() => onExpand("create")}><Dashicon name="plus-alt2" /><span className="screen-reader-text">Create term</span></button>}
              {s !== "skip" && !r.via_rule && <button type="button" className="button button-small" data-act="skip" disabled={busy} title="Skip this value: never map it" onClick={() => run(on.save(r, -1, "__skip__"))}><Dashicon name="minus" /><span className="screen-reader-text">Skip</span></button>}
              {(s === "mapped" || s === "skip" || r.missing_term_id > 0) && !r.via_rule && <button type="button" className="button button-small" data-act="clear" disabled={busy} title="Clear the mapping" onClick={() => run(on.clear(r))}><Dashicon name="no-alt" /><span className="screen-reader-text">Clear</span></button>}
              <button type="button" className="button button-small" data-act="exclude" disabled={busy} title="Exclude: hide this value (a supplier's test vendor) and never map it, until you restore it from Excluded" onClick={() => run(on.setExcluded(r, true))}><Dashicon name="hidden" /><span className="screen-reader-text">Exclude</span></button>
            </>
          )}
        </td>
      </tr>
      {expanded === "create" && (
        <tr className="mmi-wb-tm-sub"><td colSpan={4}>
          <CreateTerm r={r} taxLabel={taxLabel} onCancel={() => onExpand(null)} onCreate={(name) => { onExpand(null); run(on.save(r, 0, name)); }} />
        </td></tr>
      )}
      {expanded === "variants" && (
        <tr className="mmi-wb-tm-sub"><td colSpan={4}>
          <Variants r={r} profiles={profiles} defaultProfile={defaultProfile} taxLabel={taxLabel}
            onSave={(p, id, name) => run(on.saveVariant(r, p, id, name))} onDelete={(v) => run(on.deleteVariant(r, v))} />
        </td></tr>
      )}
    </>
  );
}

/**
 * The name for a new term, editable before it exists: supplier feeds often send names in capitals ("EXAMPLE
 * AUDIO WORKS"), and renaming a term later means editing it in WooCommerce. Says when the name already exists (names
 * match regardless of case, so it maps to that term unchanged).
 */
function CreateTerm({ r, taxLabel, onCreate, onCancel }: { r: ValueRow; taxLabel: (s: string) => string; onCreate: (name: string) => void; onCancel: () => void }) {
  const [name, setName] = useState(() => suggestTermName(r.source_value));
  const [existing, setExisting] = useState<string | null>(null);
  const input = useRef<HTMLInputElement>(null);
  useEffect(() => { input.current?.focus(); input.current?.select(); }, []);
  useEffect(() => {
    setExisting(null);
    const n = name.trim();
    if (!n) return;
    let live = true;
    const t = window.setTimeout(() => {
      void findTerms(r.wc_taxonomy, n).then((f) => {
        if (!live) return;
        const all = "tree" in f ? f.tree : f.terms;
        setExisting(all.find((x) => x.name.toLowerCase() === n.toLowerCase())?.name ?? null);
      }).catch(() => {});
    }, 250);
    return () => { live = false; window.clearTimeout(t); };
  }, [name, r.wc_taxonomy]);
  const go = () => { if (name.trim()) onCreate(name.trim()); };
  return (
    <div className="mmi-wb-tm-create" onKeyDown={(e) => { if (e.key === "Escape") { e.preventDefault(); onCancel(); } }}>
      <label className="mmi-wb-tm-create-name">
        <span className="mmi-wb-label">New term in {taxLabel(r.wc_taxonomy)}</span>
        <input ref={input} type="text" value={name} onChange={(e) => setName(e.target.value)} onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); go(); } }} />
      </label>
      <div className="mmi-filter-pills" role="group" aria-label="Letter case">
        {CASE_BUTTONS.map((c) => {
          const on = name !== "" && applyCase(c.mode as CaseMode, name, r.source_value) === name;
          return <button key={c.mode} type="button" className={`mmi-pill${on ? " active" : ""}`} aria-pressed={on} title={c.title} onClick={() => setName((n) => applyCase(c.mode, n, r.source_value))}>{c.label}</button>;
        })}
      </div>
      {existing !== null && <p className="description" role="status">{existing === name.trim() ? `“${existing}” already exists. It will be mapped, not created again.` : `“${existing}” already exists. Names match regardless of case, so this maps to it unchanged. Rename it in WooCommerce to change its case.`}</p>}
      <div className="mmi-wb-tm-create-actions">
        <button type="button" className="button button-primary" disabled={!name.trim()} onClick={go}>{existing !== null ? "Map to existing" : "Create & map"}</button>
        <button type="button" className="button" onClick={onCancel}>Cancel</button>
      </div>
    </div>
  );
}

/** A value's profile-only overrides: each one, and a picker to add another. */
function Variants({ r, profiles, defaultProfile, taxLabel, onSave, onDelete }: {
  r: ValueRow; profiles: { id: string; name: string }[]; defaultProfile: string; taxLabel: (s: string) => string;
  onSave: (p: { id: string; name: string }, termId: number, name: string) => void; onDelete: (v: Variant) => void;
}) {
  const free = profiles.filter((p) => !r.variants.some((v) => v.profile_id === p.id));
  const [pid, setPid] = useState(() => (free.some((p) => p.id === defaultProfile) ? defaultProfile : free[0]?.id ?? ""));
  const profile = free.find((p) => p.id === pid) ?? free[0] ?? null;
  return (
    <div className="mmi-wb-tm-variants-panel">
      {r.variants.length ? (
        <ul className="mmi-wb-tm-variant-list">
          {r.variants.map((v) => (
            <li key={v.mapping_id} className={(v.missing_term_id ?? 0) > 0 ? "is-missing" : ""}>
              <strong>{v.profile_label}</strong> <Dashicon name="arrow-right-alt2" />{" "}
              {v.wc_term_id === -1 ? "(skip)" : (v.missing_term_id ?? 0) > 0 ? `Deleted term #${v.missing_term_id}: pick a replacement or remove` : v.wc_term_name || "—"}{" "}
              <button type="button" className="button-link" title="Remove this override" onClick={() => onDelete(v)}>Remove</button>
            </li>
          ))}
        </ul>
      ) : <p className="description">No profile-only overrides: this value maps the same way for every import profile.</p>}
      {profile ? (
        <div className="mmi-wb-tm-variant-add">
          <select aria-label="Import profile" value={profile.id} onChange={(e) => setPid(e.target.value)}>
            {free.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
          <TermField taxonomy={r.wc_taxonomy} taxonomyLabel={taxLabel(r.wc_taxonomy)} value="" currentId={0} label={`Term for ${r.source_value} in ${profile.name}`}
            placeholder={`Term for ${profile.name} only…`} onPick={(id, name) => onSave(profile, id, name)} onCreate={(name) => onSave(profile, 0, name)} />
        </div>
      ) : <p className="description">Every import profile has its own override for this value.</p>}
    </div>
  );
}

/* ── Alias rules ──────────────────────────────────────────────────────────── */

/** Rules are tried top to bottom after exact mappings; the first match wins. Edited here, saved together. */
function RulesPanel({ rules, rows, cfg, suppliers, taxLabel, onSaved, onError }: {
  rules: AliasRule[]; rows: ValueRow[]; cfg: TaxmapConfig; suppliers: { id: string; name: string }[]; taxLabel: (s: string) => string;
  onSaved: (rules: AliasRule[], message: string) => void; onError: (t: string) => void;
}) {
  const [draft, setDraft] = useState<AliasRule[]>(rules);
  const [busy, setBusy] = useState(false);
  const changed = JSON.stringify(draft) !== JSON.stringify(rules);
  const set = (i: number, patch: Partial<AliasRule>) => setDraft((d) => d.map((r, j) => (j === i ? { ...r, ...patch } : r)));
  const move = (i: number, by: -1 | 1) => setDraft((d) => {
    const j = i + by;
    if (j < 0 || j >= d.length) return d;
    const n = [...d];
    [n[i], n[j]] = [n[j]!, n[i]!];
    return n;
  });
  const save = async () => {
    setBusy(true);
    try {
      const d = await taxmapApi.rulesSave(draft);
      setDraft(d.rules);
      onSaved(d.rules, d.apply_queued ? "Alias rules saved. Products already in the store that a rule matches are being updated in the background." : "Alias rules saved.");
    } catch (e) { onError(msg(e)); } finally { setBusy(false); }
  };
  const createTerm = async (i: number, tax: string, name: string) => {
    try {
      const d = await taxmapApi.createTerm(tax, name);
      forgetTermTree(tax);
      set(i, { wc_term_id: d.wc_term_id, wc_term_name: d.wc_term_name, missing_term_id: 0 });
    } catch (e) { onError(msg(e)); }
  };
  return (
    <div className="mmi-wb-tm-rules">
      <div className="mmi-wb-split-head">
        <div className="mmi-wb-split-title">
          <h4>Alias rules</h4>
          <p className="description">Order of resolution: <strong>1.</strong> an exact mapping, <strong>2.</strong> these rules, top to bottom, the first that matches, <strong>3.</strong> unmapped. One rule (contains “roland”) catches every supplier spelling instead of mapping each by hand.</p>
        </div>
        <div className="mmi-wb-split-actions">
          <button type="button" className="button" id="mmi-wb-tm-rule-add" onClick={() => setDraft((d) => [...d, { ...EMPTY_RULE }])}><Dashicon name="plus-alt2" /> Add rule</button>
          <BusyButton className="button button-primary" busy={busy} icon="saved" label="Save rules" busyLabel="Saving…" id="mmi-wb-tm-rule-save" disabled={!changed} onClick={() => void save()} />
        </div>
      </div>
      {!draft.length ? <p className="description" id="mmi-wb-tm-rules-empty">No alias rules yet.</p> : (
        <ol className="mmi-wb-tm-rule-list">
          {draft.map((r, i) => {
            const m = ruleMatches(r, rows);
            const missing = (r.missing_term_id ?? 0) > 0;
            return (
              <li key={i} className="mmi-wb-tm-rule" data-rule={i}>
                <div className="mmi-wb-tm-rule-order">
                  <button type="button" className="button-link" title="Move up (tried earlier)" disabled={i === 0} onClick={() => move(i, -1)}><Dashicon name="arrow-up-alt2" /><span className="screen-reader-text">Move up</span></button>
                  <button type="button" className="button-link" title="Move down" disabled={i === draft.length - 1} onClick={() => move(i, 1)}><Dashicon name="arrow-down-alt2" /><span className="screen-reader-text">Move down</span></button>
                </div>
                <div className="mmi-wb-tm-rule-fields">
                  <label><span className="mmi-wb-label">Label</span><input type="text" value={r.label} placeholder="e.g. Roland family" onChange={(e) => set(i, { label: e.target.value })} /></label>
                  <label><span className="mmi-wb-label">Supplier</span>
                    <select value={r.supplier_id} onChange={(e) => set(i, { supplier_id: e.target.value })}>
                      <option value="">Any supplier</option>
                      {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                    </select>
                  </label>
                  <label><span className="mmi-wb-label">Field</span><input type="text" value={r.source_field} placeholder="any field" onChange={(e) => set(i, { source_field: e.target.value })} /></label>
                  <label><span className="mmi-wb-label">When the value</span>
                    <select value={r.operator} onChange={(e) => set(i, { operator: e.target.value as AliasRule["operator"] })}>
                      {RULE_OPERATORS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                    </select>
                  </label>
                  <label><span className="mmi-wb-label">Text</span><input type="text" value={r.value} placeholder="roland" onChange={(e) => set(i, { value: e.target.value })} /></label>
                  <label><span className="mmi-wb-label">Taxonomy</span>
                    <select value={r.wc_taxonomy} onChange={(e) => set(i, { wc_taxonomy: e.target.value, wc_term_id: 0, wc_term_name: "" })}>
                      {cfg.taxonomies.map((t) => <option key={t.slug} value={t.slug}>{t.label}</option>)}
                    </select>
                  </label>
                  <label className="mmi-wb-tm-rule-term"><span className="mmi-wb-label">Maps to</span>
                    <TermField taxonomy={r.wc_taxonomy} taxonomyLabel={taxLabel(r.wc_taxonomy)} value={r.wc_term_id === -1 ? "(skipped)" : r.wc_term_name} currentId={r.wc_term_id}
                      label={`Term for rule ${i + 1}`} missing={missing} placeholder={missing ? `Term #${r.missing_term_id} was deleted: pick a replacement` : "Type to search terms…"}
                      onPick={(id, name) => set(i, { wc_term_id: id, wc_term_name: name, missing_term_id: 0 })} onCreate={(name) => void createTerm(i, r.wc_taxonomy, name)} />
                  </label>
                </div>
                <div className="mmi-wb-tm-rule-side">
                  <span className="description" data-matches>{m === null ? "—" : m.total ? `${plural(m.total, "match", "matches")} (${m.unmapped} unmapped)` : "0 matches"}</span>
                  <button type="button" className="button button-small" title="Delete this rule" onClick={() => setDraft((d) => d.filter((_, j) => j !== i))}><Dashicon name="no-alt" /><span className="screen-reader-text">Delete rule</span></button>
                </div>
              </li>
            );
          })}
        </ol>
      )}
      {changed && <p className="description" role="status">Unsaved changes: Save rules keeps them.</p>}
    </div>
  );
}

/* ── Profile variations ───────────────────────────────────────────────────── */

type VSortCol = "value" | "source" | "profile" | "term";

/** Every profile-only override, to review and remove one at a time or in bulk. Adding one happens on its value's row. */
function VariationsPanel({ onChanged, onNotice }: { onChanged: () => void; onNotice: (n: Notice) => void }) {
  const [list, setList] = useState<FlatVariation[] | null>(null);
  const [profiles, setProfiles] = useState<Record<string, string>>({});
  const [profile, setProfile] = useState("");
  const [picked, setPicked] = useState<Set<number>>(new Set());
  const [armed, setArmed] = useState<"selected" | "profile" | null>(null);
  const [sort, setSort] = useState<{ col: VSortCol; desc: boolean } | null>(null);
  const load = useCallback(async () => {
    try { const d = await taxmapApi.variations(); setList(d.variations); setProfiles(d.profiles ?? {}); } catch (e) { setList([]); onNotice({ text: msg(e), kind: "error" }); }
  }, [onNotice]);
  useEffect(() => { void load(); }, [load]);
  const shown = useMemo(() => {
    const l = (list ?? []).filter((v) => !profile || v.profile_id === profile);
    if (!sort) return l;
    const key = (v: FlatVariation) => (sort.col === "value" ? v.source_value : sort.col === "source" ? `${v.supplier_id}|${v.wc_taxonomy_label}` : sort.col === "profile" ? v.profile_label : v.wc_term_name).toLowerCase();
    return [...l].sort((a, b) => key(a).localeCompare(key(b)) * (sort.desc ? -1 : 1));
  }, [list, profile, sort]);
  const tableRef = useRef<HTMLTableElement>(null);
  useColumnResize(tableRef, shown.length > 0);
  const del = async (o: { ids?: number[]; profile?: string }) => {
    try {
      const d = await taxmapApi.deleteVariations(o);
      onNotice({ text: d.message, kind: "ok" });
      setPicked(new Set());
      await load();
      onChanged();
    } catch (e) { onNotice({ text: msg(e), kind: "error" }); }
  };
  const arm = (k: "selected" | "profile", go: () => void) => { if (armed !== k) { setArmed(k); return; } setArmed(null); go(); };
  const sortBy = (col: VSortCol) => setSort((s) => (!s || s.col !== col ? { col, desc: false } : !s.desc ? { col, desc: true } : null));
  const allPicked = shown.length > 0 && shown.every((v) => picked.has(v.mapping_id));
  return (
    <div className="mmi-wb-tm-variations">
      <div className="mmi-wb-split-head">
        <div className="mmi-wb-split-title">
          <h4>Profile variations</h4>
          <p className="description">Every profile-only override across every value. Add one from the value's own row (Add profile variation); remove them here, one at a time or many at once.</p>
        </div>
        <div className="mmi-wb-split-actions">
          {picked.size > 0 && <button type="button" className={`button${armed === "selected" ? " is-armed" : ""}`} id="mmi-wb-tm-var-delete" onClick={() => arm("selected", () => void del({ ids: [...picked] }))}>{armed === "selected" ? `Click again: delete ${picked.size}` : `Delete ${picked.size} selected`}</button>}
          <button type="button" className="button" onClick={() => void load()}><Dashicon name="update" /> Refresh</button>
        </div>
      </div>
      <div className="mmi-wb-split-filters">
        <select aria-label="Profile" value={profile} onChange={(e) => { setProfile(e.target.value); setArmed(null); }}>
          <option value="">Every profile</option>
          {Object.entries(profiles).map(([id, name]) => <option key={id} value={id}>{name}</option>)}
        </select>
        {profile && <button type="button" className={`button${armed === "profile" ? " is-armed" : ""}`} id="mmi-wb-tm-var-wipe" onClick={() => arm("profile", () => void del({ profile }))}>{armed === "profile" ? `Click again: delete every one for ${profiles[profile] ?? profile}` : "Delete all for this profile"}</button>}
        <span className="mmi-wb-count">{list ? plural(shown.length, "variation") : ""}</span>
      </div>
      <div className="mmi-table-scroll-wrapper">
        <table ref={tableRef} className="wp-list-table widefat striped" id="mmi-wb-tm-var-table">
          <thead>
            <tr>
              <td className="check-column"><input type="checkbox" aria-label="Select every variation shown" checked={allPicked} onChange={(e) => setPicked(e.target.checked ? new Set(shown.map((v) => v.mapping_id)) : new Set())} /></td>
              <SortTh col="value" label="Supplier value" active={sort?.col === "value"} desc={!!sort?.desc} onSort={sortBy} />
              <SortTh col="source" label="Source" active={sort?.col === "source"} desc={!!sort?.desc} onSort={sortBy} />
              <SortTh col="profile" label="Profile" active={sort?.col === "profile"} desc={!!sort?.desc} onSort={sortBy} />
              <SortTh col="term" label="Maps to" active={sort?.col === "term"} desc={!!sort?.desc} onSort={sortBy} />
              <th scope="col" className="mmi-wb-job-actions"><span className="screen-reader-text">Delete</span></th>
            </tr>
          </thead>
          <tbody>
            {!list && <tr><td colSpan={6} className="mmi-wb-empty-cell">Loading…</td></tr>}
            {list && !shown.length && <tr><td colSpan={6} className="mmi-wb-empty-cell">No profile-only variations saved.</td></tr>}
            {shown.map((v) => {
              const missing = (v.missing_term_id ?? 0) > 0;
              return (
                <tr key={v.mapping_id} data-mapping={v.mapping_id}>
                  <th scope="row" className="check-column"><input type="checkbox" aria-label={`Select ${v.source_value} for ${v.profile_label}`} checked={picked.has(v.mapping_id)}
                    onChange={(e) => setPicked((s) => { const n = new Set(s); if (e.target.checked) n.add(v.mapping_id); else n.delete(v.mapping_id); return n; })} /></th>
                  <td>{v.source_value}</td>
                  <td>{v.supplier_id || "Any supplier"}<div className="description">{v.wc_taxonomy_label}</div></td>
                  <td>{v.profile_label}</td>
                  <td className={missing ? "mmi-wb-mp-warn" : undefined}>{v.wc_term_id === -1 ? "(skip)" : missing ? `Deleted term #${v.missing_term_id}` : v.wc_term_name || "—"}</td>
                  <td className="mmi-wb-job-actions"><button type="button" className="button button-small" title="Delete this variation" onClick={() => void del({ ids: [v.mapping_id] })}><Dashicon name="no-alt" /><span className="screen-reader-text">Delete</span></button></td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}

/* ── Set up a source ──────────────────────────────────────────────────────── */

/**
 * A source with nothing to map: no brand or category field declared, or a declared one its file doesn't have. Lists
 * what the file offers (fields with a short set of repeating values; section heading rows in a price list) and
 * declares the one picked. Declaring never switches an import on: Field Mapping's "Enable for …" does that per profile.
 */
function SetupCard({ supplierId, name, onDeclared }: { supplierId: string; name: string; onDeclared: (src: SourceDef) => void }) {
  const [d, setD] = useState<SetupData | null>(null);
  const [err, setErr] = useState("");
  const [tax, setTax] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState("");
  const [said, setSaid] = useState("");
  useEffect(() => {
    let live = true;
    taxmapApi.setup(supplierId).then((x) => { if (live) setD(x); }).catch((e) => { if (live) setErr(msg(e)); });
    return () => { live = false; };
  }, [supplierId]);
  const taxLabel = (slug: string) => d?.taxonomies.find((t) => t.slug === slug)?.label ?? slug;
  const use = async (key: string, field: string, sections: boolean, fallback: string) => {
    const t = tax[key] ?? fallback;
    if (!t) { setSaid("Pick the taxonomy these values are, then Use."); return; }
    setBusy(key);
    setSaid(sections ? "Reading the file again with its section headings…" : "Saving…");
    try {
      const x = await taxmapApi.declare(supplierId, field, t, sections);
      onDeclared({ supplier: supplierId, source_field: x.source_field, wc_taxonomy: x.wc_taxonomy, label: x.pill_label, suggested: true });
    } catch (e) { setSaid(msg(e)); setBusy(""); }
  };
  const taxSelect = (key: string, fallback: string) => (
    <select aria-label="Taxonomy" value={tax[key] ?? fallback} onChange={(e) => setTax((m) => ({ ...m, [key]: e.target.value }))}>
      <option value="">— Taxonomy —</option>
      {(d?.taxonomies ?? []).map((t) => <option key={t.slug} value={t.slug}>{t.label}</option>)}
    </select>
  );
  return (
    <div className="mmi-wb-tm-setup" id="mmi-wb-tm-setup" data-supplier={supplierId}>
      <div className="mmi-wb-split-head"><div className="mmi-wb-split-title"><h4><Dashicon name="admin-generic" /> Set up {name}</h4></div></div>
      {err && <p className="mmi-wb-split-why is-blocked">{err}</p>}
      {!d && !err && <p className="description"><span className="mmi-loading" aria-hidden="true" /> Reading {name}'s file…</p>}
      {d && !d.mapping_on && <p className="mmi-wb-split-why">Mapping is switched off for this source (the switch beside it in the Import tab's Supplier Data Sources). Switch it on to map its values.</p>}
      {d && d.mapping_on && d.declared.length > 0 && (
        <p className="mmi-wb-split-why">This source reads {d.declared.map((f, i) => <span key={f.source_field}>{i ? ", " : ""}<code>{f.source_field}</code> → {taxLabel(f.wc_taxonomy)}</span>)}, but its fetched file has no values there{d.file ? <> (<code>{d.file}</code>)</> : null}. Fetch its data again, or change the field in Configure › Taxonomies.</p>
      )}
      {d && d.mapping_on && !d.declared.length && d.error && <p className="mmi-wb-split-why is-blocked">{d.error}</p>}
      {d && d.mapping_on && !d.declared.length && !d.error && (
        <>
          <p className="description">No field of this source is set as its brand or category yet, so there are no values to map. Pick where {name}'s file keeps them ({plural(d.total_items, "record")} in <code>{d.file}</code>). This only lists the values here; an import profile still switches the field on in Field Mapping.</p>
          <ul className="mmi-wb-tm-setup-options">
            {d.sections.headings.length > 0 && (
              <li data-option="sections">
                <div><strong>Section headings</strong>: the file groups its products under {d.sections.headings.length} heading rows: {d.sections.headings.slice(0, 6).map((h) => `${h.name} (${h.count})`).join(", ")}{d.sections.headings.length > 6 ? `, and ${d.sections.headings.length - 6} more` : ""}.{" "}
                  {d.sections.can_apply ? "Using them turns on Section heading rows for this source: the heading rows stop counting as records, and each product gets its heading in a Section field." : "Turn on Section heading rows in Configure › Format, then fetch again."}</div>
                {d.sections.can_apply && <div className="mmi-wb-tm-setup-use">{taxSelect("sections", "product_cat")}<button type="button" className="button button-primary" disabled={!!busy} onClick={() => void use("sections", "", true, "product_cat")}>Use headings</button></div>}
              </li>
            )}
            {d.candidates.map((c) => (
              <li key={c.path} data-option={c.path}>
                <div><code>{c.path}</code>: {plural(c.unique_count, "value")}: {c.sample_vals.slice(0, 4).join(", ")}</div>
                <div className="mmi-wb-tm-setup-use">{taxSelect(c.path, c.suggested_taxonomy)}<button type="button" className="button" disabled={!!busy} onClick={() => void use(c.path, c.path, false, c.suggested_taxonomy)}>Use field</button></div>
              </li>
            ))}
          </ul>
          {!d.sections.headings.length && !d.candidates.length && <p className="mmi-wb-split-why">Nothing in this file names a brand or category: no field repeats a short set of values, and there are no section heading rows. If every product in it is from one maker, set Brand to a fixed value in the import profile's Field Mapping instead.</p>}
        </>
      )}
      {said && <p className="description" role="status">{said}</p>}
    </div>
  );
}
