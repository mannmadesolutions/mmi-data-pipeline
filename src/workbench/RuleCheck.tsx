/**
 * What the efficacy check found for the search + change about to become a
 * standing rule, shown in the change bar before it is saved. Saving stays
 * possible whatever the grade: the findings are advice.
 */
import { findingsToShow, gradeBadge, needsLook } from "./logic";
import { MESSAGES, fill, num, plural } from "./messages";
import type { Efficacy } from "./types";
import { Dashicon } from "./ui";

export function RuleCheck({ check, saving, onSaveAnyway }: { check: Efficacy; saving: boolean; onSaveAnyway: () => void }) {
  const badge = gradeBadge(check.grade);
  const look = needsLook(check);
  return (
    <div className={`mmi-wb-rule-check${look ? " needs-look" : ""}`} id="mmi-wb-rule-check" role="status">
      <p className="mmi-wb-rule-check-head">
        <span className={`mmi-badge ${badge.kind} inline`}>{badge.label}</span>{" "}
        {look
          ? MESSAGES.CHECK_LOOK
          : fill(MESSAGES.CHECK_OK, { matched: num(check.matched), s: plural(check.matched), changing: num(check.will_change) })}
      </p>
      {findingsToShow(check, true).length > 0 && (
        <ul className="mmi-wb-rule-check-list">
          {findingsToShow(check, true).map((f) => (
            <li key={f.code} className={`is-${f.severity}`}>{f.text} <span className="mmi-wb-rule-fix">{f.fix}</span></li>
          ))}
        </ul>
      )}
      {look && (
        <button type="button" className="button" id="mmi-wb-rule-save-anyway" disabled={saving} onClick={onSaveAnyway}>
          <Dashicon name="saved" /> {MESSAGES.SAVE_ANYWAY}
        </button>
      )}
    </div>
  );
}
