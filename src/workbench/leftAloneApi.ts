/**
 * Products Product Data leaves alone (Exclusions): brands and single products
 * that get no title proposals, no maker-page reads and no description or
 * picture proposals. Shapes as GET/POST /exclusions return them, and the
 * lists a change saves (the POST replaces both lists, so every change sends
 * the whole of each). Moved from the old Product Data page's "Rules and
 * sources" (migration phase 8).
 */

export interface LeftBrand { id: number; name: string; count: number; fixed: boolean }
export interface LeftProduct { id: number; title: string; sku: string }

export interface LeftAloneSummary {
  brands: LeftBrand[];
  products: LeftProduct[];
  /** Products left alone by brand or by hand, the store's own brand included. */
  excluded: number;
  /** Products no supplier sells, skipped too (this site's NotSold), when a store reports them. */
  not_sold: { count?: number } | unknown[];
  all_brands: { id: number; name: string }[];
}

export interface LeftAloneSaved { excluded: number; removed: number; restored: number; unknown: string[]; summary: LeftAloneSummary }

export interface LeftRow { key: string; kind: "brand" | "product"; id: number; label: string; products: string; fixed: boolean }

/** One row per brand, then per product. Fixed brands (the store's own) can't be removed. */
export function leftRows(s: LeftAloneSummary): LeftRow[] {
  return [
    ...s.brands.map((b) => ({ key: `b${b.id}`, kind: "brand" as const, id: b.id, label: b.name, products: b.count.toLocaleString("en-US"), fixed: b.fixed })),
    ...s.products.map((p) => ({ key: `p${p.id}`, kind: "product" as const, id: p.id, label: `#${p.id} ${p.title}${p.sku ? ` (${p.sku})` : ""}`, products: "1", fixed: false })),
  ];
}

/** The brands not left alone yet, to choose from. */
export const brandChoices = (s: LeftAloneSummary): { id: number; name: string }[] =>
  s.all_brands.filter((b) => !s.brands.some((x) => x.id === b.id));

/**
 * The POST body after one change. Fixed brands are never sent (the server
 * adds them itself and refuses to store them). Products go as IDs, or as the
 * ID or SKU a person typed.
 */
export function saveBody(s: LeftAloneSummary, change: { addBrand?: number; removeBrand?: number; addProduct?: string; removeProduct?: number }): { brands: number[]; products: (number | string)[] } {
  let brands = s.brands.filter((b) => !b.fixed).map((b) => b.id);
  let products: (number | string)[] = s.products.map((p) => p.id);
  if (change.addBrand && !brands.includes(change.addBrand)) brands = [...brands, change.addBrand];
  if (change.removeBrand) brands = brands.filter((id) => id !== change.removeBrand);
  if (change.addProduct && change.addProduct.trim()) products = [...products, change.addProduct.trim()];
  if (change.removeProduct) products = products.filter((id) => id !== change.removeProduct);
  return { brands, products };
}

/** "59 products left alone. Products no supplier sells are skipped too." */
export function leftState(s: LeftAloneSummary): string {
  const ns = s.not_sold && !Array.isArray(s.not_sold) ? s.not_sold.count ?? 0 : 0;
  return `${s.excluded.toLocaleString("en-US")} product${s.excluded === 1 ? "" : "s"} left alone.${ns ? " Products no supplier sells are skipped too." : ""}`;
}

/** What a save did, in one line. */
export function savedText(r: LeftAloneSaved): string {
  const parts = [`${r.excluded.toLocaleString("en-US")} products left alone`];
  if (r.removed) parts.push(`${r.removed} open proposal${r.removed === 1 ? "" : "s"} removed`);
  if (r.restored) parts.push(`${r.restored} product${r.restored === 1 ? "" : "s"} back in Product Data`);
  return `${parts.join("; ")}.${r.unknown.length ? ` Not found: ${r.unknown.join(", ")}.` : ""}`;
}
