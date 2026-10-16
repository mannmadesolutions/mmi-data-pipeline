/** What a change would do: counts, field-lock notes and a sample of before → after. */
import type { LockOptions } from "./logic";
import { MESSAGES, fill, num } from "./messages";
import type { Preview } from "./types";

export function previewNotes(d: Preview, lock: LockOptions): { changing: number; skip: boolean; notes: string[] } {
  const skip = lock.skip && d.locked > 0;
  const changing = d.will_change - (skip ? d.locked : 0);
  const notes: string[] = [];
  if (d.locked > 0) {
    notes.push(fill(skip ? MESSAGES.PREVIEW_SKIPPED : MESSAGES.PREVIEW_LOCKED,
      { count: num(d.locked), have: d.locked === 1 ? "has" : "have", label: d.lock_label }));
  }
  if (changing > 0 && d.lock_mode === "always") {
    notes.push(fill(MESSAGES.BULK_LOCK, { label: d.lock_label }));
  } else if (changing > 0 && d.lock_mode === "optional") {
    notes.push(fill(lock.add ? MESSAGES.BULK_LOCK : MESSAGES.ADD_LOCK_OFF, { label: d.lock_label }));
  }
  return { changing, skip, notes };
}

export function PreviewPanel({ preview: d, lock }: { preview: Preview; lock: LockOptions }) {
  const { changing, skip, notes } = previewNotes(d, lock);
  return (
    <div className="mmi-wb-preview" id="mmi-wb-preview-out">
      <p className="mmi-wb-preview-summary">
        <strong>{d.summary}</strong>:{" "}
        {fill(MESSAGES.PREVIEW_SUMMARY, {
          changing: num(changing), selected: num(d.selected), unchanged: num(d.unchanged),
          skipped: skip ? fill(MESSAGES.PREVIEW_SKIP_TAIL, { count: num(d.locked) }) : "",
        })}
      </p>
      {notes.map((n) => (
        <p key={n} className="mmi-wb-preview-lock"><span className="dashicons dashicons-lock" aria-hidden="true" /> {n}</p>
      ))}
      {d.sample.length > 0 && (
        <>
          <div className="mmi-table-scroll-wrapper">
            <table className="widefat striped mmi-wb-preview-table">
              <thead>
                <tr>
                  <th className="mmi-wb-lock-cell"><span className="screen-reader-text">Locked</span></th>
                  <th>Product</th><th>Now</th><th /><th>After</th>
                </tr>
              </thead>
              <tbody>
                {d.sample.map((s) => (
                  <tr key={s.id} className={skip && s.locked ? "is-skipped" : ""}>
                    <td className="mmi-wb-lock-cell">
                      {s.locked && <span className="dashicons dashicons-lock" title={MESSAGES.TERMS_LOCKED} aria-label={MESSAGES.TERMS_LOCKED} />}
                    </td>
                    <td><a href={`post.php?post=${s.id}&action=edit`} target="_blank" rel="noopener noreferrer">{s.title}</a></td>
                    <td className="mmi-wb-before">{s.before}</td>
                    <td className="mmi-wb-arrow" aria-hidden="true">→</td>
                    <td className="mmi-wb-after">{s.after}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {d.will_change > d.sample.length && <p className="mmi-wb-more">{fill(MESSAGES.PREVIEW_MORE, { count: num(d.will_change - d.sample.length) })}</p>}
        </>
      )}
    </div>
  );
}
