/**
 * Alerts fixed alike (2026-11-12): above a list, one chip per alert on the page with how many products carry it.
 * Opening one offers that alert's fixes for all of them at once:
 *
 * - a one-click fix (Add to Software Bundles, mark it virtual) runs for each product, with its progress;
 * - a category or brand fix opens the list's bulk picker with those products ticked;
 * - a fix made product by product (edit the title, pick a description) shows only those rows, to work through
 *   with each row's own alert.
 *
 * A row's alert chip still fixes that one product in its row (IssueFixes.tsx). The list owns the fixing; this
 * draws the strip and the panel.
 */
import { useState } from "react";
import { groupMode, type Fix, type IssueGroup } from "./issuesApi";
import { Dashicon } from "./ui";

const plural = (n: number, one: string, many = `${one}s`) => `${n} ${n === 1 ? one : many}`;

/** A fix's button for a whole group: "Add to Software Bundles (all 7)", "Change the category of all 7…". */
export function groupFixLabel(f: Fix, n: number): string {
  return groupMode(f) === "each" ? `${f.label} (all ${n})` : `${f.label.replace(/…$/, "")} of all ${n}…`;
}

export function IssueGroups({ groups, open, onOpen, only, onOnly, busy, progress, onFix }: {
  groups: IssueGroup[];
  open: string | null;
  onOpen: (key: string | null) => void;
  /** The alert whose rows alone are shown, if any. */
  only: string | null;
  onOnly: (key: string | null) => void;
  busy: boolean;
  /** "Add to Software Bundles: 3 of 7…" while a fix runs. */
  progress: string;
  onFix: (group: IssueGroup, fix: Fix) => void;
}) {
  // A fix run for every product asks for a second click on the same button (no dialog): "Click again: …".
  const [armed, setArmed] = useState<string | null>(null);
  if (!groups.length && !only) return null;
  const g = groups.find((x) => x.key === open) ?? null;
  const shownOnly = groups.find((x) => x.key === only) ?? null;
  const oneByOne = g ? g.fixes.filter((f) => groupMode(f) === "one-by-one") : [];
  return (
    <div className="mmi-wb-alert-groups" id="mmi-wb-alert-groups">
      <div className="mmi-wb-alert-strip" role="group" aria-label="Alerts on this page">
        <span className="mmi-wb-alert-lead">Alerts on this page</span>
        {groups.map((x) => (
          <button key={x.key} type="button" data-group={x.key} aria-expanded={open === x.key}
            className={`mmi-badge ${x.tone === "info" ? "info" : x.tone} inline mmi-wb-issue mmi-wb-alert-group${open === x.key ? " is-open" : ""}`}
            title={`${x.help ? `${x.help} ` : ""}Fix all ${x.ids.length} at once here, or one in its row.`} onClick={() => onOpen(open === x.key ? null : x.key)}>
            {x.label} <span className="mmi-wb-alert-n">{x.ids.length}</span> <Dashicon name={open === x.key ? "arrow-up-alt2" : "arrow-down-alt2"} />
          </button>
        ))}
        {only && (
          <span className="mmi-wb-alert-only">
            Showing only {shownOnly ? `the ${plural(shownOnly.ids.length, "product")} with “${shownOnly.label}”` : "the products with that alert"}.{" "}
            <button type="button" className="button-link" id="mmi-wb-alert-show-all" onClick={() => onOnly(null)}>Show every row</button>
          </span>
        )}
      </div>
      {g && (
        <div className={`mmi-wb-issue-panel is-${g.tone} mmi-wb-alert-panel`} data-group-panel={g.key}
          onKeyDown={(e) => { if (e.key === "Escape") { e.stopPropagation(); onOpen(null); } }}>
          <div className="mmi-wb-issue-head">
            <strong>{g.label}</strong> <span className="description">{plural(g.ids.length, "product")} on this page</span>
            <button type="button" className="button-link mmi-wb-issue-close" aria-label="Close" onClick={() => onOpen(null)}><Dashicon name="no-alt" /></button>
          </div>
          {g.help && <p className="description">{g.help}</p>}
          <div className="mmi-wb-alert-actions">
            {g.fixes.filter((f) => groupMode(f) !== "one-by-one").map((f) => {
              const id = `${g.key}|${f.kind}|${f.action ?? f.label}`;
              const each = groupMode(f) === "each";
              const isArmed = armed === id;
              return (
                <button key={id} type="button" data-fix={f.action ?? f.kind} disabled={busy}
                  className={`button${f.primary ? " button-primary" : ""}${isArmed ? " is-armed" : ""}`}
                  title={each ? `Changes each of the ${g.ids.length} products now (locked against imports) and recomposes its title` : undefined}
                  onClick={() => { if (each && !isArmed) { setArmed(id); return; } setArmed(null); onFix(g, f); }}
                  onBlur={() => { if (isArmed) setArmed(null); }}>
                  {isArmed ? `Click again: ${f.label.charAt(0).toLowerCase()}${f.label.slice(1)} for all ${g.ids.length}` : groupFixLabel(f, g.ids.length)}
                </button>
              );
            })}
            {only !== g.key && (
              <button type="button" className="button" data-fix="only" disabled={busy} onClick={() => onOnly(g.key)}>
                Show only these {g.ids.length}
              </button>
            )}
          </div>
          {oneByOne.length > 0 && (
            <p className="description">
              {oneByOne.map((f) => `“${f.label}”`).join(" and ")} {oneByOne.length === 1 ? "is" : "are"} done product by product: show only these, then open the alert in each row.
            </p>
          )}
          {progress && <p className="mmi-wb-alert-progress" role="status"><span className="mmi-loading" aria-hidden="true" /> {progress}</p>}
        </div>
      )}
    </div>
  );
}
