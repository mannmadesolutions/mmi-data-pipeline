/**
 * Title rules: the rules a store writes for its product titles (Product Data,
 * CustomRules). Every proposed title gets them, top to bottom, after the rule
 * pack's own rules (this store's house style, or Basic). A rule can be
 * written here or saved while editing a proposed title; each can be for one
 * brand, some categories or some suppliers. The form shows, as you type, how
 * many titles the rule would change, with examples, before anything is saved.
 */
import { useCallback, useEffect, useRef, useState, type KeyboardEvent } from "react";
import { ApiError } from "./api";
import {
  categoryOptions, draftReady, draftToBody, EMPTY_DRAFT, KINDS, moved, previewSummary, rest, ruleToDraft, unstableNote,
  type OwnRule, type RuleDraft, type RulePreview, type TermOption, type TitleRulesConfig,
} from "./titleRulesApi";
import { BusyButton, Dashicon, MultiSelect, Section } from "./ui";

type SortKey = "position" | "label" | "brand" | "scope" | "at";
const COLS: { key: SortKey; label: string; title?: string }[] = [
  { key: "position", label: "#", title: "Order: each rule works on the title the one before it left" },
  { key: "label", label: "Rule" },
  { key: "brand", label: "Brand" },
  { key: "scope", label: "Applies to" },
  { key: "at", label: "Added" },
];

interface Recomposed { products?: number; changed?: number; new?: number; queued?: number }
interface ListReply { rules: OwnRule[]; recomposed?: Recomposed | null }
interface Notice { text: string; kind: "ok" | "error" }

function sortRules(rules: OwnRule[], key: SortKey, dir: "asc" | "desc"): OwnRule[] {
  const m = dir === "desc" ? -1 : 1;
  return [...rules].sort((a, b) => {
    const x = a[key];
    const y = b[key];
    return (typeof x === "number" && typeof y === "number" ? x - y : String(x).localeCompare(String(y), undefined, { numeric: true })) * m || a.position - b.position;
  });
}

function savedText(verb: string, rc?: Recomposed | null): string {
  if (!rc) return `${verb}.`;
  const now = (rc.changed ?? 0) + (rc.new ?? 0);
  const later = rc.queued ?? 0;
  const parts = [`${verb}.`];
  parts.push(now ? `${now} proposal${now === 1 ? "" : "s"} updated now.` : "No proposal changed now.");
  if (later) parts.push(`${later} more product${later === 1 ? "" : "s"} follow within minutes.`);
  return parts.join(" ");
}

export function TitleRules({ cfg }: { cfg: TitleRulesConfig }) {
  const [rules, setRules] = useState<OwnRule[] | null>(null);
  const [pack, setPack] = useState("");
  const [brands, setBrands] = useState<TermOption[]>([]);
  const [cats, setCats] = useState<TermOption[]>([]);
  const [suppliers, setSuppliers] = useState<Record<string, number>>({});
  const [notice, setNotice] = useState<Notice | null>(null);
  const [editing, setEditing] = useState<string | null>(null); // "" = a new rule
  const [draft, setDraft] = useState<RuleDraft>(EMPTY_DRAFT);
  const [preview, setPreview] = useState<RulePreview | null>(null);
  const [previewError, setPreviewError] = useState("");
  const [previewBusy, setPreviewBusy] = useState(false);
  const [busy, setBusy] = useState<"save" | "row" | null>(null);
  const [sort, setSort] = useState<{ key: SortKey; dir: "asc" | "desc" }>({ key: "position", dir: "asc" });
  const seq = useRef(0);

  const fail = useCallback((e: unknown) => setNotice({ text: e instanceof ApiError ? e.message : String(e), kind: "error" }), []);

  useEffect(() => {
    rest<ListReply>(cfg, "GET", "custom-rules").then((d) => setRules(d.rules)).catch((e) => { setRules([]); fail(e); });
    rest<{ pack: string }>(cfg, "GET", "title-words").then((d) => setPack(d.pack)).catch(() => {});
    rest<{ brands: TermOption[] }>(cfg, "GET", "brands").then((d) => setBrands(d.brands)).catch(() => {});
    rest<{ categories: TermOption[] }>(cfg, "GET", "categories").then((d) => setCats(d.categories)).catch(() => {});
    rest<{ suppliers: Record<string, number> }>(cfg, "GET", "suppliers").then((d) => setSuppliers(d.suppliers)).catch(() => {});
  }, [cfg, fail]);

  // Preview as the person types: once the draft is complete, 400 ms after the last change.
  useEffect(() => {
    if (editing === null) return;
    setPreview(null);
    setPreviewError("");
    if (!draftReady(draft)) { setPreviewBusy(false); return; }
    const mine = ++seq.current;
    setPreviewBusy(true);
    const t = window.setTimeout(() => {
      rest<RulePreview>(cfg, "POST", "custom-rules/preview", draftToBody(draft))
        .then((p) => { if (mine === seq.current) setPreview(p); })
        .catch((e) => { if (mine === seq.current) setPreviewError(e instanceof ApiError ? e.message : String(e)); })
        .finally(() => { if (mine === seq.current) setPreviewBusy(false); });
    }, 400);
    return () => window.clearTimeout(t);
  }, [cfg, draft, editing]);

  const open = (r: OwnRule | null) => {
    setNotice(null);
    setEditing(r ? r.id : "");
    setDraft(r ? ruleToDraft(r) : EMPTY_DRAFT);
  };
  const close = () => { setEditing(null); setPreview(null); setPreviewError(""); seq.current++; };
  const set = (patch: Partial<RuleDraft>) => setDraft((d) => ({ ...d, ...patch }));

  const save = async () => {
    setBusy("save");
    try {
      const d = editing
        ? await rest<ListReply>(cfg, "POST", "custom-rules/update", { id: editing, ...draftToBody(draft) })
        : await rest<ListReply>(cfg, "POST", "custom-rules/add", draftToBody(draft));
      setRules(d.rules);
      setNotice({ text: savedText(editing ? "Rule changed" : "Rule saved", d.recomposed), kind: "ok" });
      close();
    } catch (e) {
      setPreviewError(e instanceof ApiError ? e.message : String(e));
    } finally {
      setBusy(null);
    }
  };

  const remove = async (r: OwnRule) => {
    if (!window.confirm(`Delete the rule ${r.label}? Titles it changed are recomposed without it.`)) return;
    setBusy("row");
    try {
      const d = await rest<ListReply>(cfg, "POST", "custom-rules/delete", { id: r.id });
      setRules(d.rules);
      if (editing === r.id) close();
      setNotice({ text: savedText("Rule deleted", d.recomposed), kind: "ok" });
    } catch (e) { fail(e); } finally { setBusy(null); }
  };

  const move = async (r: OwnRule, by: -1 | 1) => {
    const ids = rules ? moved(rules, r.id, by) : null;
    if (!ids) return;
    setBusy("row");
    try {
      const d = await rest<ListReply>(cfg, "POST", "custom-rules/order", { ids });
      setRules(d.rules);
      setNotice({ text: "Order saved. Proposals follow in the next run, within minutes.", kind: "ok" });
    } catch (e) { fail(e); } finally { setBusy(null); }
  };

  const sortBy = (key: SortKey) => setSort((s) => ({ key, dir: s.key === key && s.dir === "asc" ? "desc" : "asc" }));
  const onKey = (e: KeyboardEvent, key: SortKey) => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); sortBy(key); } };
  const rows = rules ? sortRules(rules, sort.key, sort.dir) : [];
  const last = rules?.length ?? 0;
  const kind = KINDS.find((k) => k.value === draft.kind) ?? KINDS[0]!;
  const supplierOptions = Object.fromEntries(Object.entries(suppliers).map(([k, n]) => [k, `${k} (${n})`]));
  const working = busy !== null;

  return (
    <Section id="mmi-wb-title-rules" icon="editor-spellcheck" title="Title rules"
      desc={`Your own rules for product titles: written here, or saved while editing a proposed title. Every proposed title gets them, top to bottom, after the ${pack || "built-in"} rules. A rule can be for one brand, some categories or some suppliers.`}
      actions={
        <button type="button" className="button button-primary" id="mmi-wb-tr-add" disabled={working || editing === ""} onClick={() => open(null)}>
          <Dashicon name="plus-alt2" /> Add rule
        </button>
      }>
      {notice && (
        <div className={`notice notice-${notice.kind === "error" ? "error" : "success"} inline`} role="status" id="mmi-wb-tr-notice"><p>{notice.text}</p></div>
      )}

      {editing !== null && (
        <div className="mmi-wb-tr-form" id="mmi-wb-tr-form">
          <div className="mmi-wb-tr-fields">
            <label className="mmi-wb-tr-field">
              <span className="mmi-wb-label">What it does</span>
              <select id="mmi-wb-tr-kind" value={draft.kind} onChange={(e) => set({ kind: e.target.value as RuleDraft["kind"] })}>
                {KINDS.map((k) => <option key={k.value} value={k.value}>{k.label}</option>)}
              </select>
            </label>
            <label className="mmi-wb-tr-field mmi-wb-tr-grow">
              <span className="mmi-wb-label">{kind.from}</span>
              <input type="text" id="mmi-wb-tr-from" value={draft.from} spellCheck={false}
                className={draft.kind === "pattern" ? "code" : undefined} onChange={(e) => set({ from: e.target.value })} />
            </label>
            {draft.kind !== "remove" && (
              <label className="mmi-wb-tr-field mmi-wb-tr-grow">
                <span className="mmi-wb-label">{kind.to}</span>
                <input type="text" id="mmi-wb-tr-to" value={draft.to} placeholder={kind.toHint} onChange={(e) => set({ to: e.target.value })} />
              </label>
            )}
          </div>
          <div className="mmi-wb-tr-fields">
            <label className="mmi-wb-tr-field">
              <span className="mmi-wb-label">Brand</span>
              <select id="mmi-wb-tr-brand" value={draft.brand} onChange={(e) => set({ brand: e.target.value })}>
                <option value="">Every brand</option>
                {draft.brand && !brands.some((b) => b.name === draft.brand) && <option value={draft.brand}>{draft.brand}</option>}
                {brands.map((b) => <option key={b.id} value={b.name}>{b.name}</option>)}
              </select>
            </label>
            <div className="mmi-wb-tr-field">
              <span className="mmi-wb-label">Categories</span>
              <MultiSelect id="mmi-wb-tr-cats" label="Every category" options={categoryOptions(cats)} checked={draft.categories}
                onChange={(next) => set({ categories: next })} />
            </div>
            {Object.keys(suppliers).length > 0 && (
              <div className="mmi-wb-tr-field">
                <span className="mmi-wb-label">Suppliers</span>
                <MultiSelect id="mmi-wb-tr-sups" label="Every supplier" options={supplierOptions} checked={draft.suppliers}
                  onChange={(next) => set({ suppliers: next })} />
              </div>
            )}
            <label className="mmi-wb-tr-field mmi-wb-tr-check">
              <input type="checkbox" id="mmi-wb-tr-case" checked={draft.anyCase} onChange={(e) => set({ anyCase: e.target.checked })} />
              {" "}Match any capitals
            </label>
          </div>

          <div className="mmi-wb-preview" id="mmi-wb-tr-preview" aria-live="polite">
            {!draftReady(draft) && <p className="mmi-wb-preview-summary description">Fill in the rule to see which titles it would change.</p>}
            {draftReady(draft) && previewBusy && <p className="mmi-wb-preview-summary"><span className="mmi-loading" aria-hidden="true" /> Checking product titles…</p>}
            {previewError && <div className="notice notice-error inline" id="mmi-wb-tr-error"><p>{previewError}</p></div>}
            {preview && !previewBusy && (
              <>
                <p className="mmi-wb-preview-summary" id="mmi-wb-tr-summary"><strong>{preview.label}</strong> — {previewSummary(preview)}</p>
                {preview.unstable > 0 && <div className="notice notice-warning inline" id="mmi-wb-tr-unstable"><p>{unstableNote(preview)}</p></div>}
                {preview.samples.length > 0 && (
                  <table className="widefat striped mmi-wb-preview-table" id="mmi-wb-tr-samples">
                    <thead><tr><th className="mmi-wb-col-id">ID</th><th>Before</th><th>After</th></tr></thead>
                    <tbody>
                      {preview.samples.map((s) => (
                        <tr key={s.id}>
                          <td className="mmi-wb-col-id"><a href={`post.php?post=${s.id}&action=edit`} target="_blank" rel="noreferrer">{s.id}</a></td>
                          <td className="mmi-wb-before">{s.before}</td>
                          <td className="mmi-wb-after">{s.after}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                )}
              </>
            )}
          </div>

          <div className="mmi-wb-tr-buttons">
            <BusyButton id="mmi-wb-tr-save" className="button button-primary" busy={busy === "save"} icon="saved"
              label={editing ? "Save changes" : "Save rule"} busyLabel="Saving…"
              disabled={!draftReady(draft) || previewBusy || !!previewError || !preview} onClick={save}
              title="Saving the rule approves it: proposals it changes are updated now" />
            <button type="button" className="button" id="mmi-wb-tr-cancel" disabled={busy === "save"} onClick={close}>Cancel</button>
          </div>
        </div>
      )}

      <div className="mmi-table-scroll-wrapper">
        <table className="wp-list-table widefat striped mmi-wb-rules-table" id="mmi-wb-tr-table">
          <thead>
            <tr>
              {COLS.map((c) => (
                <th key={c.key} data-col={c.key} tabIndex={0} title={c.title}
                  className={`mmi-wb-job-sortable${sort.key === c.key ? " is-active" : ""}${sort.key === c.key && sort.dir === "desc" ? " is-desc" : ""}`}
                  aria-sort={sort.key === c.key ? (sort.dir === "desc" ? "descending" : "ascending") : "none"}
                  onClick={() => sortBy(c.key)} onKeyDown={(e) => onKey(e, c.key)}>
                  {c.label} <span className="sort-icon" />
                </th>
              ))}
              <th><span className="screen-reader-text">Actions</span></th>
            </tr>
          </thead>
          <tbody id="mmi-wb-tr-rows">
            {!rules && <tr><td colSpan={6} className="mmi-wb-empty-cell">Loading title rules…</td></tr>}
            {rules && !rules.length && <tr><td colSpan={6} className="mmi-wb-empty-cell">No title rules yet. Add one, or save one while editing a proposed title.</td></tr>}
            {rows.map((r) => (
              <tr key={r.id} data-rule-id={r.id} className={editing === r.id ? "is-editing" : undefined}>
                <td className="mmi-wb-rule-pos">{r.position}</td>
                <td className="mmi-wb-rule-name"><strong>{r.label}</strong></td>
                <td>{r.brand || "Every brand"}</td>
                <td>{r.scope || "Every product"}</td>
                <td title={r.at}>{[r.by, r.at.slice(0, 10)].filter(Boolean).join(" · ")}</td>
                <td className="mmi-wb-job-actions mmi-wb-rule-actions">
                  <button type="button" className="button button-small" disabled={working} onClick={() => open(r)}><Dashicon name="edit" /> Edit</button>
                  <button type="button" className="button button-small mmi-wb-icon-btn" disabled={working || r.position === 1}
                    aria-label={`Move ${r.label} earlier`} title="Apply earlier" onClick={() => move(r, -1)}><Dashicon name="arrow-up-alt2" /></button>
                  <button type="button" className="button button-small mmi-wb-icon-btn" disabled={working || r.position === last}
                    aria-label={`Move ${r.label} later`} title="Apply later" onClick={() => move(r, 1)}><Dashicon name="arrow-down-alt2" /></button>
                  <button type="button" className="button button-small mmi-wb-icon-btn mmi-wb-rule-delete" disabled={working}
                    aria-label={`Delete ${r.label}`} title="Delete this rule" onClick={() => remove(r)}><Dashicon name="trash" /></button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Section>
  );
}
