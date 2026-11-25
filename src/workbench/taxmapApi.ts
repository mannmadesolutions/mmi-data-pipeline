/**
 * Category & brand mapping's endpoints: admin-ajax actions in TaxonomyMappingController.php, checked against their
 * own nonce (mmi_pipeline_taxonomy_mapping), which the page prints in mmiWorkbench.taxmap and MMINonces keeps fresh.
 */
import { post, type FormValue } from "./api";
import { page } from "./globals";
import type { AliasRule, FlatTerm, FoundTerms, TreeTerm, ValueRow, Variant } from "./taxmapLogic";

export const TAXMAP_NONCE_ACTION = "mmi_pipeline_taxonomy_mapping";

function call<T>(action: string, data: Record<string, FormValue> = {}): Promise<T> {
  return post<T>(action, data, undefined, { action: TAXMAP_NONCE_ACTION, value: page().mmiWorkbench?.taxmap?.nonce ?? "" });
}

/** What saving a mapping did to products already in the store (apply_saved_mapping()). */
export interface Applied { updated: number; already: number; locked: number; too_many: number; queued?: boolean }

/** Which value a call is about. */
export interface ValueRef { supplier: string; source_field: string; source_value: string; wc_taxonomy: string }

export interface SetupData {
  supplier_id: string;
  supplier_name: string;
  mapping_on: boolean;
  declared: { source_field: string; wc_taxonomy: string }[];
  taxonomies: { slug: string; label: string }[];
  file: string;
  total_items: number;
  candidates: { path: string; unique_count: number; sample_vals: string[]; suggested_taxonomy: string }[];
  sections: { enabled: boolean; headings: { name: string; count: number }[]; can_apply: boolean };
  error: string;
}

export interface FlatVariation extends Variant { supplier_id: string; source_field: string; source_value: string; wc_taxonomy: string; wc_taxonomy_label: string }

const ref = (v: ValueRef) => ({ supplier_id: v.supplier, source_field: v.source_field, source_value: v.source_value, wc_taxonomy: v.wc_taxonomy });

export const taxmapApi = {
  /** Every source's values with their mappings (the feed read is cached 5 minutes; fresh re-reads). */
  scan: (fresh: boolean) => call<{ values: ValueRow[]; total: number; mapped: number; excluded: number }>("mmi_scan_all_sources", { force_refresh: fresh ? 1 : 0 }),

  /** Map a value: termId > 0 a term, 0 find-or-create termName, -1 skip. profileId "" = every profile. */
  save: (v: ValueRef, termId: number, termName: string, profileId = "") =>
    call<{ mapping_id: number; wc_term_id: number; wc_term_name: string; applied: Applied | null }>("mmi_save_taxonomy_mapping", {
      ...ref(v), wc_term_id: termId, term_name: termName, auto_create: 0, profile_id: profileId,
    }),
  remove: (mappingId: number) => call<{ message: string }>("mmi_delete_taxonomy_mapping", { mapping_id: mappingId }),
  setExcluded: (v: ValueRef, excluded: boolean) =>
    call<{ excluded: boolean; excluded_at: string; excluded_by: string }>("mmi_set_taxonomy_value_excluded", { ...ref(v), excluded: excluded ? 1 : 0 }),

  /** A hierarchical taxonomy's whole tree, or a flat one's matches. */
  terms: async (taxonomy: string, search: string): Promise<FoundTerms> => {
    const d = await call<{ tree?: TreeTerm[]; terms?: FlatTerm[] }>("mmi_search_wc_terms", { taxonomy, search });
    return Array.isArray(d.tree) ? { tree: d.tree } : { terms: d.terms ?? [] };
  },
  /** Find or create a term by name (an alias rule's term). */
  createTerm: (taxonomy: string, name: string) => call<{ wc_term_id: number; wc_term_name: string }>("mmi_create_wc_term", { taxonomy, term_name: name }),

  rulesSave: (rules: AliasRule[]) => call<{ message: string; rules: AliasRule[]; apply_queued: boolean }>("mmi_save_taxonomy_alias_rules", { rules: JSON.stringify(rules) }),

  variations: () => call<{ variations: FlatVariation[]; profiles: Record<string, string> }>("mmi_get_taxonomy_variations"),
  deleteVariations: (o: { ids?: number[]; profile?: string }) =>
    call<{ message: string; deleted: number }>("mmi_bulk_delete_taxonomy_variations", o.profile ? { wipe_profile_id: o.profile } : { mapping_ids: o.ids ?? [] }),

  setup: (supplierId: string) => call<SetupData>("mmi_taxmap_source_setup", { supplier_id: supplierId }),
  declare: (supplierId: string, sourceField: string, taxonomy: string, useSections: boolean) =>
    call<{ source_field: string; wc_taxonomy: string; pill_label: string; records: number }>("mmi_taxmap_declare_source_field", {
      supplier_id: supplierId, source_field: useSections ? "" : sourceField, wc_taxonomy: taxonomy, use_sections: useSections ? 1 : 0,
    }),

  /** Every saved mapping onto products already in the store, in the background. */
  applyAll: () => call<{ message: string; queued: boolean }>("mmi_apply_all_taxonomy_mappings"),
};
