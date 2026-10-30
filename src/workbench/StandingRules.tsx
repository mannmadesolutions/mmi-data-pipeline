/**
 * Standing rules: Workbench searches + a change that every catalog run applies
 * again (they used to be Catalog Maintenance's Custom Rules). Each row says
 * what it searches for, what it changes, how well it works (the efficacy
 * check) and what its last run did. Open loads it into Find products to
 * edit; Run now applies it straight away.
 */
import { useState, type KeyboardEvent } from "react";
import { findingsToShow, gradeBadge, lastRunText, sortRules, type RuleSortKey } from "./logic";
import { MESSAGES } from "./messages";
import type { StandingRule } from "./types";
import { Dashicon, Section } from "./ui";

const COLS: { key: RuleSortKey; label: string; title?: string }[] = [
  { key: "position", label: "#", title: "Run order: the first matching stock rule wins a product" },
  { key: "name", label: "Rule" },
  { key: "search", label: "Products it searches for" },
  { key: "change", label: "Change" },
  { key: "grade", label: "Check" },
  { key: "last_run", label: "Last run" },
  { key: "enabled", label: "On" },
];

export interface RowNotice { id: string; text: string; kind: "ok" | "error" }

export function StandingRules({ rules, busy, editingId, notice, onOpen, onRun, onToggle, onMove, onDelete }: {
  rules: StandingRule[] | null;
  busy: boolean;
  editingId: string;
  notice: RowNotice | null;
  onOpen: (r: StandingRule) => void;
  onRun: (r: StandingRule) => void;
  onToggle: (r: StandingRule, on: boolean) => void;
  onMove: (r: StandingRule, by: -1 | 1) => void;
  onDelete: (r: StandingRule) => void;
}) {
  const [sort, setSort] = useState<{ key: RuleSortKey; dir: "asc" | "desc" }>({ key: "position", dir: "asc" });
  const sortBy = (key: RuleSortKey) => setSort((s) => ({ key, dir: s.key === key && s.dir === "asc" ? "desc" : "asc" }));
  const onKey = (e: KeyboardEvent, key: RuleSortKey) => {
    if (e.key === "Enter" || e.key === " ") { e.preventDefault(); sortBy(key); }
  };
  const rows = rules ? sortRules(rules, sort.key, sort.dir) : [];
  const last = rules ? rules.length : 0;

  return (
    <Section id="mmi-wb-rules" icon="update" title="Standing rules"
      desc="Changes that keep applying: every catalog run finds each rule's products again and changes them, including products that come to match later. Make one from a search with Keep applying">
      {notice && !rows.some((r) => r.id === notice.id) && (
        <div className={`notice notice-${notice.kind === "error" ? "error" : "success"} inline`} role="status"><p>{notice.text}</p></div>
      )}
      <div className="mmi-table-scroll-wrapper">
        <table className="wp-list-table widefat striped mmi-wb-rules-table" id="mmi-wb-rules-table">
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
          <tbody id="mmi-wb-rules-rows">
            {!rules && <tr><td colSpan={8} className="mmi-wb-empty-cell">{MESSAGES.RULES_LOADING}</td></tr>}
            {rules && !rules.length && <tr><td colSpan={8} className="mmi-wb-empty-cell">{MESSAGES.NO_RULES}</td></tr>}
            {rows.map((r) => {
              const badge = r.efficacy ? gradeBadge(r.efficacy.grade) : gradeBadge(undefined);
              const findings = findingsToShow(r.efficacy);
              const mine = notice && notice.id === r.id ? notice : null;
              return (
                <tr key={r.id} data-rule-id={r.id} className={`${r.enabled ? "" : "mmi-wb-rule-off"}${editingId === r.id ? " is-editing" : ""}`}>
                  <td className="mmi-wb-rule-pos">{r.position}</td>
                  <td className="mmi-wb-rule-name">
                    <strong>{r.name}</strong>
                    {findings.map((f) => (
                      <div key={f.code} className={`notice notice-${f.severity === "inefficient" ? "error" : "warning"} inline mmi-wb-rule-finding`}>
                        <p>{f.text} <span className="mmi-wb-rule-fix">{f.fix}</span></p>
                      </div>
                    ))}
                    {mine && (
                      <div className={`notice notice-${mine.kind === "error" ? "error" : "success"} inline mmi-wb-rule-notice`} role="status"><p>{mine.text}</p></div>
                    )}
                  </td>
                  <td className="mmi-wb-job-filter">{r.search}</td>
                  <td>{r.change}</td>
                  <td>
                    <span className={`mmi-badge ${badge.kind} inline`}
                      title={(r.efficacy?.findings ?? []).map((f) => f.text).join("\n") || undefined}>{badge.label}</span>
                  </td>
                  <td>{lastRunText(r)}</td>
                  <td>
                    <label className="mmi-toggle-switch" title={r.enabled ? "On: catalog runs apply it" : "Off: catalog runs skip it"}>
                      <input type="checkbox" checked={r.enabled} disabled={busy} aria-label={`${r.name} on`}
                        onChange={(e) => onToggle(r, e.target.checked)} />
                      <span className="mmi-toggle-slider" />
                    </label>
                  </td>
                  <td className="mmi-wb-job-actions mmi-wb-rule-actions">
                    <button type="button" className="button button-small" disabled={busy} onClick={() => onOpen(r)}
                      title="Load this rule's search and change into Find products to look at its products or edit it">
                      <Dashicon name="edit" /> Open
                    </button>
                    <button type="button" className="button button-small" disabled={busy || !r.enabled} onClick={() => onRun(r)}
                      title={r.enabled ? "Apply this rule now instead of waiting for the next catalog run" : "Turn the rule on first"}>
                      <Dashicon name="controls-play" /> Run now
                    </button>
                    <button type="button" className="button button-small mmi-wb-icon-btn" disabled={busy || r.position === 1}
                      aria-label={`Move ${r.name} earlier`} title="Run earlier" onClick={() => onMove(r, -1)}><Dashicon name="arrow-up-alt2" /></button>
                    <button type="button" className="button button-small mmi-wb-icon-btn" disabled={busy || r.position === last}
                      aria-label={`Move ${r.name} later`} title="Run later" onClick={() => onMove(r, 1)}><Dashicon name="arrow-down-alt2" /></button>
                    <button type="button" className="button button-small mmi-wb-icon-btn mmi-wb-rule-delete" disabled={busy}
                      aria-label={`Delete ${r.name}`} title="Delete this rule" onClick={() => onDelete(r)}><Dashicon name="trash" /></button>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </Section>
  );
}
