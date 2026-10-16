/** Recent changes: every Apply, product by product, with Undo / Finish undo / Resume. */
import { useState, type KeyboardEvent } from "react";
import { jobActions, jobBadge, jobChangedText, sortJobs, type JobSortKey } from "./logic";
import { MESSAGES } from "./messages";
import type { Job } from "./types";
import { Dashicon, Section } from "./ui";

const COLS: { key: JobSortKey; label: string }[] = [
  { key: "created_at", label: "When" },
  { key: "summary", label: "Change" },
  { key: "filter_label", label: "Products searched for" },
  { key: "changed", label: "Changed" },
  { key: "status", label: "Status" },
];

export function RecentChanges({ jobs, busy, onUndo, onResume }: {
  jobs: Job[] | null;
  busy: boolean;
  onUndo: (job: Job, finishing: boolean) => void;
  onResume: (job: Job) => void;
}) {
  const [sort, setSort] = useState<{ key: JobSortKey; dir: "asc" | "desc" }>({ key: "created_at", dir: "desc" });
  const sortBy = (key: JobSortKey) => setSort((s) => ({ key, dir: s.key === key && s.dir === "asc" ? "desc" : "asc" }));
  const onKey = (e: KeyboardEvent, key: JobSortKey) => {
    if (e.key === "Enter" || e.key === " ") { e.preventDefault(); sortBy(key); }
  };
  const rows = jobs ? sortJobs(jobs, sort.key, sort.dir) : [];

  return (
    <Section id="mmi-wb-history" icon="backup" title="Recent changes"
      desc="Every Apply is recorded product by product. Undo restores each product's previous value — unless it has been edited again since, which is left alone">
      <div className="mmi-table-scroll-wrapper">
        <table className="wp-list-table widefat striped mmi-wb-jobs-table" id="mmi-wb-jobs">
          <thead>
            <tr>
              {COLS.map((c) => (
                <th key={c.key} data-col={c.key} tabIndex={0}
                  className={`mmi-wb-job-sortable${sort.key === c.key ? " is-active" : ""}${sort.key === c.key && sort.dir === "desc" ? " is-desc" : ""}`}
                  aria-sort={sort.key === c.key ? (sort.dir === "desc" ? "descending" : "ascending") : "none"}
                  onClick={() => sortBy(c.key)} onKeyDown={(e) => onKey(e, c.key)}>
                  {c.label} <span className="sort-icon" />
                </th>
              ))}
              <th><span className="screen-reader-text">Actions</span></th>
            </tr>
          </thead>
          <tbody id="mmi-wb-jobs-rows">
            {!jobs && <tr><td colSpan={6} className="mmi-wb-empty-cell">{MESSAGES.JOBS_LOADING}</td></tr>}
            {jobs && !jobs.length && <tr><td colSpan={6} className="mmi-wb-empty-cell">{MESSAGES.NO_JOBS}</td></tr>}
            {rows.map((j) => {
              const a = jobActions(j);
              const badge = jobBadge(j);
              return (
                <tr key={j.id}>
                  <td>{j.created_at}<br /><span className="mmi-wb-none">{j.user}</span></td>
                  <td>{j.summary}</td>
                  <td className="mmi-wb-job-filter">{j.filter_label}</td>
                  <td>{jobChangedText(j)}</td>
                  <td><span className={`mmi-badge ${badge.kind} inline`}>{badge.label}</span></td>
                  <td className="mmi-wb-job-actions">
                    {a.undo && (
                      <button type="button" className="button button-small mmi-wb-job-undo" disabled={busy} onClick={() => onUndo(j, false)}>
                        <Dashicon name="undo" /> Undo
                      </button>
                    )}
                    {a.finishUndo && (
                      <button type="button" className="button button-small mmi-wb-job-undo" disabled={busy} onClick={() => onUndo(j, true)}>Finish undo</button>
                    )}
                    {a.resume && (
                      <>{" "}<button type="button" className="button button-small mmi-wb-job-resume" disabled={busy} onClick={() => onResume(j)}>Resume</button></>
                    )}
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
