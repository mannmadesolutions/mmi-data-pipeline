/**
 * The bridge to the two shared jQuery components the Workbench hosts:
 *
 *   - the suite-wide condition builder (MMI_Condition_Builder markup,
 *     mmi-condition-builder.js), also used by Catalog Maintenance and Field Mapping;
 *   - the change picker (Rule_Builder_View action markup, rule-builder.js),
 *     shared with Catalog Maintenance's Custom Rules.
 *
 * Both stay server-rendered and jQuery-driven so all three screens keep one
 * behavior (owner rule: Workbench and Catalog Maintenance never drift). React
 * moves their markup into its layout (Island) and reads them only through here.
 */
import { page } from "./globals";
import { MESSAGES, fill } from "./messages";
import type { ActionChoice, Condition, ConditionsState, MatchLogic } from "./types";

export const IDS = {
  BUILDER: "mmi-wb-cb",
  CONDITIONS: "mmi-wb-conditions",
  MATCH_LOGIC: "mmi-wb-match-logic",
  ACTION_SCOPE: "mmi-wb-action-scope",
  ACTION: "mmi-wb-action",
  SAVE_SEARCH_SLOT: "mmi-wb-save-search-slot",
} as const;

// Operators that take no value (mmi-condition-builder.js NO_VALUE_OPERATORS).
const NO_VALUE_OPERATORS = ["is_empty", "is_not_empty", "term_default_only"];
const FIELD_READY_POLL_MS = 100;
const FIELD_READY_TIMEOUT_MS = 10000;

const $ = (): JQueryStatic => page().jQuery!;
const rb = () => page().MMIRuleBuilder;
const byId = (id: string): JQuery => $()(`#${id}`);

/** Whether the shared components this page needs loaded (an older bundled copy may lack one). */
export const bridgeReady = (): boolean => !!(page().jQuery && rb() && page().MMIConditionBuilder);

/* ── Conditions ─────────────────────────────────────────────────────────── */

function conditionRows(): JQuery {
  const RB = rb()!;
  return byId(IDS.CONDITIONS).find(RB.SELECTORS.CONDITION_ROW!);
}

/** One readable phrase for a complete condition row ("Category equals \"Bundles\""). */
function phrase($r: JQuery): { text: string; or: boolean } {
  const S = rb()!.SELECTORS;
  const field = $r.find(`${S.COND_FIELD_SEL} option:selected`).text();
  // Option text without its leading symbol ("= equals" → "equals").
  const op = $r.find(`${S.COND_OPERATOR_SEL} option:selected`).text().replace(/^[=≠<>]\s*/, "");
  const $pick = $r.find(`${S.COND_VALUE_SELECT}:not(.mmi-hidden) option:selected`);
  const val = $pick.length && $r.find(S.COND_OPERATOR_SEL!).val() === "matches_pattern"
    ? $pick.text() : String($r.find(S.COND_VALUE_SEL!).val() ?? "");
  // An older bundled builder has no child-terms toggle (no selector).
  const kids = S.COND_CHILDREN_INPUT && $r.find(S.COND_CHILDREN_INPUT).is(":checked") ? " or a child term" : "";
  return { text: `${field} ${op} ${val ? `"${val}"` : ""}${kids}`.trim(), or: $r.find(S.COND_JOIN!).attr("aria-pressed") === "true" };
}

/** Snapshot of the condition builder, read after each of its change events. */
export function readConditions(): ConditionsState {
  const RB = rb()!;
  const S = RB.SELECTORS;
  let firstIncomplete = 0;
  let firstWithoutValue = 0;
  const phrases: ConditionsState["phrases"] = [];
  conditionRows().each(function (i) {
    const $r = $()(this);
    const complete = RB.isConditionComplete($r);
    if (!complete) {
      if (!firstIncomplete) firstIncomplete = i + 1;
      return;
    }
    const op = String($r.find(S.COND_OPERATOR_SEL!).val() ?? "");
    if (!firstWithoutValue && !NO_VALUE_OPERATORS.includes(op) && String($r.find(S.COND_VALUE_SEL!).val() ?? "").trim() === "") {
      firstWithoutValue = i + 1;
    }
    phrases.push(phrase($r));
  });
  return {
    conditions: RB.collectConditions(byId(IDS.CONDITIONS)),
    matchLogic: (String(byId(IDS.MATCH_LOGIC).val() || "all") as MatchLogic),
    firstIncomplete,
    firstWithoutValue,
    phrases,
  };
}

/** Calls back on any edit inside the condition builder (rows, values, match logic, Load saved). */
export function onConditionsChange(cb: () => void): () => void {
  const RB = rb()!;
  const $b = byId(IDS.BUILDER);
  const $doc = $()(document);
  const ns = ".mmiWorkbench";
  $b.on(`${RB.CHANGE_EVENT}${ns}`, cb);
  $doc.on(`change${ns}`, `#${IDS.MATCH_LOGIC}`, cb);
  return () => { $b.off(ns); $doc.off(`change${ns}`, `#${IDS.MATCH_LOGIC}`); };
}

/** Empty the builder (Clear all). */
export function clearConditions(): void {
  byId(IDS.CONDITIONS).empty();
  page().MMIConditionBuilder!.updateEmptyHint(byId(IDS.BUILDER));
}

/** A passing notice beside the builder's buttons (search errors, saved-search results). */
export function builderNotice(text: string): void {
  page().MMIConditionBuilder?.showNotice(byId(IDS.BUILDER), text);
}

/** Drop the builder's cached library so "Load saved conditions" shows a just-saved search. */
export const refreshLibrary = (): void => page().MMIConditionBuilder?.refreshLibrary();

/**
 * Add "Category equals <name>" (or "Category is empty") for a facet click.
 * Resolves once the row's field list has loaded, so the search sees a complete row.
 */
export function addCategoryCondition(name: string, none: boolean): Promise<boolean> {
  const RB = rb()!;
  const $row = RB.appendConditionRow(byId(IDS.CONDITIONS));
  if (!$row) return Promise.resolve(false);
  RB.initConditionCascade($row, "wp_taxonomy", "product_cat", none ? "is_empty" : "equals", none ? "" : name);
  byId(IDS.MATCH_LOGIC).val("all");
  return new Promise((resolve) => {
    const started = Date.now();
    const wait = window.setInterval(() => {
      const ready = !!$row.find(RB.SELECTORS.COND_FIELD_SEL!).val();
      if (ready || Date.now() - started > FIELD_READY_TIMEOUT_MS) {
        window.clearInterval(wait);
        resolve(ready);
      }
    }, FIELD_READY_POLL_MS);
  });
}

/** Precondition for saving a search: every row complete, or the message saying which isn't. */
export function incompleteMessage(): string | null {
  const n = readConditions().firstIncomplete;
  return n ? fill(MESSAGES.INCOMPLETE, { n }) : null;
}

/* ── Change picker ──────────────────────────────────────────────────────── */

/** The change picker's current choice. */
export function readAction(): ActionChoice {
  const RB = rb()!;
  const $scope = byId(IDS.ACTION_SCOPE);
  const $action = byId(IDS.ACTION);
  const action = String($action.val() ?? "");
  const params = RB.collectActionParams($scope);
  let summary = $action.find("option:selected").text();
  if (action === "set_taxonomy_term") {
    const term = $scope.find(`${RB.SELECTORS.TERM_SELECT} option:selected`).text();
    const mode = $scope.find(`${RB.SELECTORS.TAX_MODE_SELECT} option:selected`).text();
    summary += ` → ${term} (${mode})`;
  }
  return { action, params, summary };
}

/**
 * Calls back when the change picker changes. `kind` is "action" when a
 * different change was chosen (lock switches reset), "params" for its settings.
 */
export function onActionChange(cb: (kind: "action" | "params") => void): () => void {
  const RB = rb()!;
  const $scope = byId(IDS.ACTION_SCOPE);
  const ns = ".mmiWorkbench";
  $scope.on(`change${ns}`, `#${IDS.ACTION}`, () => {
    RB.updateActionParamsVisibility($scope);
    cb("action");
  });
  $scope.on(`change${ns}`, "select, input", (e) => {
    if ((e.target as HTMLElement).id !== IDS.ACTION) cb("params");
  });
  return () => $scope.off(ns);
}

/**
 * Put a saved rule's conditions into the builder, replacing what is there.
 * Returns the fields of conditions this builder couldn't take (an unknown source).
 */
export function loadConditions(conditions: Condition[], matchLogic: MatchLogic): string[] {
  return page().MMIConditionBuilder!.loadConditions(byId(IDS.BUILDER), conditions, matchLogic, true).skipped;
}

/** Put a saved rule's change and its settings into the picker. */
export function loadAction(action: string, params: Record<string, string>): void {
  const RB = rb()!;
  const S = RB.SELECTORS;
  const $scope = byId(IDS.ACTION_SCOPE);
  byId(IDS.ACTION).val(action);
  RB.updateActionParamsVisibility($scope);
  const set = (sel: string | undefined, v: string | undefined) => { if (sel && v !== undefined) $scope.find(sel).val(v); };
  if (action === "set_taxonomy_term") {
    set(S.TAX_SELECT, params.taxonomy);
    set(S.TAX_MODE_SELECT, params.mode);
    const $group = $scope.find(`${S.PARAM_GROUP}[data-action-types~="set_taxonomy_term"]`).first();
    RB.loadTaxonomyTerms($group, params.taxonomy ?? "", params.term_id ?? "");
  }
  set(S.PRICE_INPUT, params.value);
  set(S.DATE_FROM_INPUT, params.date_from);
  set(S.DATE_TO_INPUT, params.date_to);
  set(S.SOURCE_FIELD_INPUT, params.source_field);
  set(S.META_KEY_INPUT, params.meta_key);
  set(S.META_VALUE_INPUT, params.meta_value);
  set(S.FIND_INPUT, params.find);
  set(S.REPLACE_INPUT, params.replace);
  if (S.CASE_INPUT) $scope.find(S.CASE_INPUT).prop("checked", params.case_sensitive === "1");
  if (action === "set_wc_field" && S.WC_FIELD_SELECT) {
    $scope.find(S.WC_FIELD_SELECT).val(params.wc_field ?? "");
    RB.updateActionParamsVisibility($scope); // shows the value control for that field
    $scope.find(`${S.WC_VALUE}:not(:disabled)`).val(params.wc_value ?? "");
  }
  // One event, so App re-reads the choice (and resets its lock switches).
  byId(IDS.ACTION).trigger("change");
}

/** Force In/Out of Stock can be picked only for a standing rule; Once greys them out. */
export function allowActions(blocked: string[], allowed: boolean): void {
  const $options = byId(IDS.ACTION).find("option").filter(function () { return blocked.includes(String($()(this).val())); });
  $options.prop("disabled", !allowed);
}

/** First-time setup of the picker: show the chosen change's settings and load the default taxonomy's terms. */
export function initActionPicker(): void {
  const RB = rb()!;
  const $scope = byId(IDS.ACTION_SCOPE);
  RB.updateActionParamsVisibility($scope);
  RB.loadTaxonomyTerms($scope.find(RB.SELECTORS.PARAM_GROUP!).first(), "product_cat", "");
}
