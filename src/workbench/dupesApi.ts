/**
 * Duplicates' endpoints: admin-ajax actions in CanonicalCandidatesController.php and CanonicalOlderListings.php,
 * checked against their own nonce (mmi_pipeline_canonical_candidates), which the page prints in
 * mmiWorkbench.duplicates and MMINonces keeps fresh.
 */
import { post, type FormValue } from "./api";
import type { Collision, Group } from "./dupesLogic";
import { page } from "./globals";

export const DUPES_NONCE_ACTION = "mmi_pipeline_canonical_candidates";

function call<T>(action: string, data: Record<string, FormValue> = {}): Promise<T> {
  return post<T>(action, data, undefined, { action: DUPES_NONCE_ACTION, value: page().mmiWorkbench?.duplicates?.nonce ?? "" });
}

/** A linked group's action: what happened, and the group as it is now (null: it's gone). */
export interface GroupChange { message: string; group: Group | null; old_group_key?: string }

export const dupesApi = {
  /** Brand + name matches across the supplier feeds (cached 10 minutes; fresh rescans). */
  candidates: (fresh: boolean) => call<{ groups: Group[] }>("mmi_scan_duplicate_candidates", { force_refresh: fresh ? 1 : 0 }),
  /** Linked groups, plus same-MPN, same-title and older-listing groups (one request: the ≤2 auto-fired limit). */
  existing: (fresh: boolean) => call<{ groups: Group[] }>("mmi_scan_existing_canonical_groups", { force_refresh: fresh ? 1 : 0 }),
  collisions: (fresh: boolean) => call<{ groups: Collision[] }>("mmi_scan_pk_collisions", { force_refresh: fresh ? 1 : 0 }),

  /** Link: the canonical listing, the others, each field's winner; a same-MPN or same-title group folds a second canonical in. */
  link: (canonicalId: number, associated: number[], winners: Record<string, number>, mergeCanonicals: boolean) =>
    call<{ message: string; fields_applied?: string[] }>("mmi_approve_duplicate_group", {
      canonical_product_id: canonicalId, associated_product_ids: associated, field_winners: JSON.stringify(winners), merge_canonicals: mergeCanonicals ? 1 : 0,
    }),
  /** Not a duplicate: every group the row holds. */
  dismiss: (groupKeys: string[]) => call<{ message: string }>("mmi_dismiss_duplicate_group", { group_keys: groupKeys }),

  applyWinners: (canonicalId: number, winners: Record<string, number>) =>
    call<GroupChange>("mmi_canonical_apply_field_winners", { canonical_product_id: canonicalId, field_winners: JSON.stringify(winners) }),
  makeCanonical: (canonicalId: number, newCanonicalId: number) =>
    call<GroupChange>("mmi_canonical_reassign_canonical", { canonical_product_id: canonicalId, new_canonical_id: newCanonicalId }),
  removeMember: (canonicalId: number, memberId: number) =>
    call<GroupChange>("mmi_canonical_remove_group_member", { canonical_product_id: canonicalId, member_product_id: memberId }),
  unlink: (canonicalId: number) => call<GroupChange>("mmi_canonical_unlink_group", { canonical_product_id: canonicalId }),

  /** Older listings, up to 25 groups a request. */
  linkOlder: (groupKeys: string[]) => call<{ linked: number; skipped: Record<string, string> }>("mmi_link_older_listings", { group_keys: groupKeys }),

  /** The scan switch. */
  setEnabled: (on: boolean) => call<{ enabled: boolean }>("mmi_toggle_duplicate_products", { enabled: on ? "1" : "" }),
};

/** Older listings go 25 groups to a request, so a whole catalog can't outrun one. */
export const OLDER_BATCH = 25;
