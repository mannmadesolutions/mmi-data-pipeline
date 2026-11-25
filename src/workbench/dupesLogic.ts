/**
 * Duplicates (a pill of Needs a decision): the pure part. Groups of listings that look like one product, from the
 * scans in CanonicalCandidatesController.php, folded into one row per product, filtered, sorted and judged by price.
 * Duplicates.tsx draws them; dupesApi.ts asks the server.
 */

/* ── Shapes the scans return ─────────────────────────────────────────────── */

export type Source = "candidate" | "mpn" | "legacy" | "title" | "older";

/** A listing's stored values for the fields a canonical listing can take from another (asset_snapshot()). */
export interface Assets {
  title?: string;
  has_image?: boolean;
  image_id?: number;
  image_thumb_url?: string;
  gallery_count?: number;
  gallery_key?: string;
  gallery_thumbs?: string[];
  has_content?: boolean;
  content_preview?: string;
  content_words?: number;
  content_hash?: string;
  has_excerpt?: boolean;
  excerpt_preview?: string;
  excerpt_words?: number;
  excerpt_hash?: string;
}

export interface Member {
  supplier: string;
  sku: string;
  vendor?: string;
  dealer_price: number;
  map_price?: number;
  /** 0 = in a feed, not imported yet. */
  wc_product_id: number;
  is_canonical?: boolean;
  is_recommended?: boolean;
  product_name?: string;
  stock_status?: string;
  visibility?: string;
  /** true: its supplier's feed lists it; false: dropped; null: no feed read for it. */
  in_feed?: boolean | null;
  held_by?: string;
  assets?: Assets;
  store_price?: number;
  list_price?: number;
  list_price_source?: "map" | "store" | "";
  price_stale?: boolean;
}

export interface Group {
  group_key: string;
  source: Source;
  brand: string;
  name: string;
  members: Member[];
  price_min: number;
  price_max: number;
  price_spread: number;
  price_count: number;
  price_stale: number;
  price_match: boolean;
  already_linked: boolean;
  canonical_product_id?: number;
  nothing_on_sale?: boolean;
  sale_candidate_id?: number;
  recommended_product_id?: number | null;
  brand_thumbnail_url?: string;
  image_url?: string;
  /** Same MPN: the part number. */
  match?: string;
  multiple_canonicals?: boolean;
}

/** A queue row: a group plus what folding gave it (see fold()). */
export interface Row extends Group {
  availability: GroupAvailability;
  /** How each group this row holds is named in its review hint. */
  covers: string[];
  /** The sources of the groups it holds. */
  alsoSources: Source[];
  /** Their keys, so Not a duplicate dismisses every one. */
  coveredKeys: string[];
  /** Nothing on sale, from a held linked group: its canonical. */
  no_sale_canonical_id?: number;
}

/** Confirmed collisions: two products holding one supplier tracking key. */
export interface CollisionMember {
  product_id: number;
  product_name: string;
  product_url: string;
  sku: string;
  regular_price: string;
  stock_status: string;
  post_status: string;
  is_recommended: boolean;
  reverb_listing_id: string;
  reverb_sync: boolean;
}

export interface Collision {
  supplier: string;
  value: string;
  members: CollisionMember[];
  canonical_id: number;
  resolved: boolean;
  recommended_id: number;
}

/* ── Chips ───────────────────────────────────────────────────────────────── */

export type Chip = "new" | "linked" | "no_sale" | "not_sold" | "older" | "all" | "collisions";

export const CHIPS: { value: Chip; label: string; hint: string }[] = [
  { value: "new", label: "New", hint: "Groups to decide: link them as one product, or mark them not a duplicate" },
  { value: "linked", label: "Linked", hint: "Groups already linked: change which listing customers see, remove a listing or unlink" },
  { value: "no_sale", label: "Nothing on sale", hint: "Linked groups whose canonical listing is out of stock while its duplicates are held out of stock" },
  { value: "not_sold", label: "Not sold", hint: "No listing can be sold: each is out of stock and gone from its supplier's feed or held out by a stock rule. The canonical pick changes nothing customers can buy" },
  { value: "older", label: "Older listings", hint: "An old listing no supplier carries any more, beside a listing of the same product on sale" },
  { value: "all", label: "All", hint: "Every group, new and linked" },
  { value: "collisions", label: "Collisions", hint: "Two products holding the same supplier tracking key: only one of them gets the feed's price and stock" },
];

/** A wb_dupes value (or an old dupes_status) → its chip; "" when it names none. */
export function chipOf(raw: string): Chip | "" {
  return (CHIPS.find((c) => c.value === raw)?.value) ?? "";
}

/* ── Availability ────────────────────────────────────────────────────────── */

export type MemberAvailability = "instock" | "backorder" | "held" | "out" | "gone" | "ruled" | "unimported";
export type GroupAvailability = "onsale" | "sellable" | "out" | "notsold";

export const SUPPLIER_LABELS: Record<string, string> = { xchange: "XChange", skuport: "SkuPort", plugivery: "Plugivery" };
export const supplierName = (m: Member): string => SUPPLIER_LABELS[m.supplier] ?? (m.supplier || "supplier");

/** Whether a listing can be bought, and whether its supplier still sells it. */
export function memberAvailability(m: Member): MemberAvailability {
  if (!m.wc_product_id) return "unimported";
  if (m.stock_status === "instock") return "instock";
  if (m.stock_status === "onbackorder") return "backorder";
  if (m.in_feed === false) return "gone";
  if (m.held_by) return "ruled";
  return m.in_feed == null ? "out" : "held";
}

export const MEMBER_AVAILABILITY: Record<MemberAvailability, { badge: string; label: (s: string) => string; title: (s: string, m: Member) => string }> = {
  instock: { badge: "success", label: () => "In stock", title: () => "Customers can buy this listing" },
  backorder: { badge: "success", label: () => "On backorder", title: () => "Customers can order this listing" },
  held: { badge: "warning", label: (s) => `Out of stock · ${s} sells it`, title: (s) => `Out of stock here, but the ${s} feed still lists it, so it can go back on sale` },
  out: { badge: "error", label: () => "Out of stock", title: () => "Out of stock, and not from a supplier feed this screen reads" },
  gone: { badge: "", label: () => "No longer sold", title: (s) => `Out of stock, and the ${s} feed no longer lists it: the supplier doesn’t sell it any more` },
  ruled: { badge: "", label: () => "Held out by rule", title: (s, m) => `Out of stock: ${m.held_by} keeps it off sale, although the ${s} feed lists it` },
  unimported: { badge: "info", label: () => "Not imported", title: (s) => `In the ${s} feed, not imported into the store yet` },
};

/** Something on sale; nothing on sale but a supplier still sells one; nothing anyone sells; otherwise out. */
export function groupAvailability(g: Pick<Group, "members">): GroupAvailability {
  const keys = g.members.map(memberAvailability);
  if (keys.some((k) => k === "instock" || k === "backorder")) return "onsale";
  if (keys.some((k) => k === "held" || k === "unimported")) return "sellable";
  return keys.every((k) => k === "gone" || k === "ruled") ? "notsold" : "out";
}

export const GROUP_AVAILABILITY: Record<GroupAvailability, { rank: number; badge: string; label: string; title: string }> = {
  onsale: { rank: 0, badge: "success", label: "In stock", title: "At least one listing can be bought" },
  sellable: { rank: 1, badge: "warning", label: "Supplier sells it", title: "Nothing is in stock, but a supplier feed still lists one of these listings" },
  out: { rank: 2, badge: "error", label: "Out of stock", title: "Nothing is in stock" },
  notsold: { rank: 3, badge: "", label: "Not sold", title: "No listing can be sold: each is gone from its supplier’s feed or held out by a stock rule, so which one is canonical changes nothing customers can buy" },
};

export const inStockCount = (g: Pick<Group, "members">): number =>
  g.members.filter((m) => { const a = memberAvailability(m); return a === "instock" || a === "backorder"; }).length;

/* ── Sources ─────────────────────────────────────────────────────────────── */

export const SOURCE_BADGES: Record<Source, { cls: string; label: string; title: (g: Group) => string }> = {
  legacy: { cls: "info", label: "Legacy link", title: () => "A real, pre-existing canonical/associated_product_ids link found directly in the database" },
  mpn: { cls: "success", label: "Same MPN", title: (g) => `Same brand and manufacturer part number (${g.match ?? ""}): the strongest evidence of one product listed more than once` },
  older: { cls: "info", label: "Older listing", title: () => "An old listing no supplier carries any more, beside a listing of the same product on sale" },
  title: { cls: "warning", label: "Same title", title: () => "Listed under the same brand and title in this store; nothing else groups them, so check they are one product before linking" },
  candidate: { cls: "warning", label: "Fresh match", title: () => "Detected by matching brand + product name across live supplier feeds" },
};

/** Why these listings were grouped, under the verdict's headline. */
export function matchBasis(g: Group): string {
  if (g.source === "mpn") return `Same ${g.brand} part number, ${g.match ?? ""}`;
  if (g.source === "legacy") return `Already linked to canonical #${g.canonical_product_id ?? 0}`;
  if (g.source === "older") return "Same product: the other listings are out of stock and in no supplier feed";
  if (g.source === "title") return "Same brand and product title in this store (no feed match or part number groups them)";
  return "Same brand and product name in the supplier feeds";
}

/* ── Folding: one row per product ────────────────────────────────────────── */

/** Which row holds a product when several sources group the same listings: the strongest evidence first. */
const SOURCE_RANK: Partial<Record<Source, number>> = { mpn: 0, legacy: 1, candidate: 2, title: 3 };

const COVER_LABELS: Record<Source, (g: Group) => string> = {
  mpn: (g) => `the same part number match (${g.match ?? ""})`,
  legacy: (g) => `the existing linked group of #${g.canonical_product_id ?? 0}`,
  candidate: () => "the brand + name match",
  title: () => "the same-title match",
  older: () => "the older-listing match",
};

const idsOf = (g: Group): number[] => g.members.map((m) => m.wc_product_id).filter(Boolean);

/**
 * The scans' groups as queue rows. A row whose listings all sit in another row folds into it, so one product is one
 * row (a feed match, a same-title and a same-MPN row all holding #7403 and #18553 was the 2026-09-30 report). The
 * holder is the row with the most listings, the stronger source on a tie; a linked group holds only rows already
 * linked (a row still to decide stays its own). Older-listing rows never fold: their bulk Link works on them as they
 * are. The holder keeps what it replaced: covers (review hint), alsoSources (Source badges), coveredKeys (Dismiss).
 */
export function fold(groups: Group[]): Row[] {
  const rows: Row[] = groups.map((g) => ({
    ...g, covers: [], alsoSources: [], coveredKeys: [], availability: groupAvailability(g),
    ...(g.source === "legacy" ? {} : { nothing_on_sale: false, sale_candidate_id: 0 }),
  }));
  // A group with a listing not imported yet can't be judged as contained, or hold another.
  const foldable = (r: Row) => r.source in SOURCE_RANK && r.members.length > 0 && r.members.every((m) => m.wc_product_id);
  const entries = rows.filter(foldable).map((r) => ({ row: r, ids: idsOf(r), set: new Set(idsOf(r)) }));
  type Entry = (typeof entries)[number];
  const byId = new Map<number, Entry[]>();
  for (const e of entries) for (const id of e.ids) byId.set(id, [...(byId.get(id) ?? []), e]);
  const rank = (e: Entry) => SOURCE_RANK[e.row.source] ?? 9;
  const outranks = (a: Entry, b: Entry) => a.set.size !== b.set.size ? a.set.size > b.set.size
    : rank(a) !== rank(b) ? rank(a) < rank(b) : a.row.group_key < b.row.group_key;
  const canHold = (o: Entry, e: Entry) => o.row.source !== "legacy" || e.row.already_linked;
  const holderOf = new Map<Row, Row>();
  for (const e of entries) {
    let best: Entry | null = null;
    for (const o of byId.get(e.ids[0]!) ?? []) {
      if (o !== e && canHold(o, e) && outranks(o, e) && e.ids.every((id) => o.set.has(id)) && (!best || outranks(o, best))) best = o;
    }
    if (best) holderOf.set(e.row, best.row);
  }
  return rows.filter((r) => {
    const owner = holderOf.get(r);
    if (!owner) return true;
    owner.covers.push(COVER_LABELS[r.source](r));
    if (!owner.alsoSources.includes(r.source)) owner.alsoSources.push(r.source);
    if (r.source !== "legacy") owner.coveredKeys.push(r.group_key);
    if (r.nothing_on_sale) {
      owner.nothing_on_sale = true;
      owner.no_sale_canonical_id = r.canonical_product_id;
      owner.sale_candidate_id = owner.sale_candidate_id || r.sale_candidate_id;
    }
    return false;
  });
}

/** A row the server re-read after a change to a linked group (its fold state starts empty). */
export function asRow(g: Group): Row {
  return { ...g, covers: [], alsoSources: [], coveredKeys: [], availability: groupAvailability(g) };
}

/* ── Filters and sort ────────────────────────────────────────────────────── */

export function chipMatches(r: Row, chip: Chip): boolean {
  switch (chip) {
    case "new": return !r.already_linked;
    case "linked": return r.already_linked;
    case "no_sale": return !!r.nothing_on_sale;
    case "not_sold": return r.availability === "notsold";
    case "older": return r.source === "older";
    default: return true;
  }
}

export function chipCounts(rows: Row[]): Record<Exclude<Chip, "collisions">, number> {
  const out = { new: 0, linked: 0, no_sale: 0, not_sold: 0, older: 0, all: rows.length };
  for (const r of rows) for (const c of ["new", "linked", "no_sale", "not_sold", "older"] as const) if (chipMatches(r, c)) out[c]++;
  return out;
}

/** Brands present (lowercased key → name, by name); groups with no brand aren't bucketed. */
export function brandsOf(rows: Row[]): [string, string][] {
  const m = new Map<string, string>();
  for (const r of rows) { const n = (r.brand || "").trim(); if (n) m.set(n.toLowerCase(), n); }
  return [...m.entries()].sort((a, b) => a[1].localeCompare(b[1]));
}

export type SortCol = "name" | "brand" | "members" | "price" | "stock" | "source";
export interface Sort { col: SortCol; desc: boolean }

/** The rows a chip and a brand leave, in the chosen order (name A–Z when none). */
export function visibleRows(rows: Row[], chip: Chip, brand: string, q: string, sort: Sort | null): Row[] {
  const needle = q.trim().toLowerCase();
  const list = rows.filter((r) => chipMatches(r, chip)
    && (!brand || (r.brand || "").toLowerCase() === brand)
    && (!needle || r.name.toLowerCase().includes(needle) || (r.brand || "").toLowerCase().includes(needle)
      || r.members.some((m) => String(m.wc_product_id) === needle || (m.sku || "").toLowerCase() === needle)));
  const s = sort ?? { col: "name", desc: false };
  const dir = s.desc ? -1 : 1;
  const key = (r: Row): number | string => {
    switch (s.col) {
      case "members": return r.members.length;
      case "price": return r.price_spread;
      case "stock": return GROUP_AVAILABILITY[r.availability].rank;
      case "source": return r.source;
      case "brand": return (r.brand || "").toLowerCase();
      default: return r.name.toLowerCase();
    }
  };
  return [...list].sort((a, b) => {
    // Price sorts by spread; a group with fewer than two prices has nothing to compare and stays last either way.
    if (s.col === "price") {
      const na = a.price_count < 2;
      const nb = b.price_count < 2;
      if (na !== nb) return na ? 1 : -1;
    }
    const va = key(a);
    const vb = key(b);
    return va < vb ? -dir : va > vb ? dir : a.group_key < b.group_key ? -1 : 1;
  });
}

/** Click a heading: ascending (members and price start descending), then flipped, then back to none. */
export function nextSort(s: Sort | null, col: SortCol): Sort | null {
  const firstDesc = col === "members" || col === "price";
  if (!s || s.col !== col) return { col, desc: firstDesc };
  return s.desc === firstDesc ? { col, desc: !s.desc } : null;
}

/* ── Price check ─────────────────────────────────────────────────────────── */

export const formatPrice = (v: number | undefined): string =>
  `$${(Number(v) || 0).toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

/** The price listings are checked against: the commonest list price, ties going to the canonical's. 0 when none. */
export function referencePrice(members: Member[], canonicalId: number): number {
  const counts = new Map<string, number>();
  for (const m of members) {
    const p = m.price_stale ? 0 : Number(m.list_price) || 0;
    if (p > 0) counts.set(p.toFixed(2), (counts.get(p.toFixed(2)) ?? 0) + 1);
  }
  const canon = members.find((m) => m.wc_product_id && m.wc_product_id === canonicalId);
  const canonKey = canon && !canon.price_stale && Number(canon.list_price) > 0 ? Number(canon.list_price).toFixed(2) : "";
  let best = "";
  let bestN = 0;
  counts.forEach((n, k) => { if (n > bestN || (n === bestN && k === canonKey)) { best = k; bestN = n; } });
  return Number(best) || 0;
}

export type PriceState = "match" | "off" | "none" | "stale";

/** How one listing's price compares; "stale" (out of stock, gone from its feed) is shown but never compared. */
export function priceState(m: Member, ref: number): PriceState {
  const p = Number(m.list_price) || 0;
  if (!p) return "none";
  if (m.price_stale) return "stale";
  return p.toFixed(2) === ref.toFixed(2) ? "match" : "off";
}

export interface Verdict { tone: "match" | "off" | "none"; headline: string; figure: string; note: string }

/** What the prices say about a group, for the dark card atop the open group. */
export function verdict(g: Group, ref: number): Verdict {
  const stale = Number(g.price_stale) || 0;
  const n = g.members.length - stale;
  const count = Number(g.price_count) || 0;
  const off = g.members.filter((m) => priceState(m, ref) === "off").length;
  const tone = !count ? "none" : g.price_spread > 0 ? "off" : "match";
  if (g.source === "older") return { tone, headline: "Older listing of a product on sale", figure: formatPrice(g.price_min), note: "The price on sale. An old listing’s price isn’t compared: nobody can pay it" };
  if (!count) return { tone, headline: "No prices to compare", figure: "—", note: stale ? "The only prices are old ones nobody can pay" : "No listing has a MAP or store price" };
  if (g.price_match) return { tone, headline: stale ? `Same price on the ${n} current listings` : `Same price on all ${n} listings`, figure: formatPrice(g.price_min), note: "Matching prices back up the match" };
  if (g.price_spread > 0) {
    return { tone, headline: `Prices differ by ${formatPrice(g.price_spread)}`, figure: `${formatPrice(g.price_min)} – ${formatPrice(g.price_max)}`,
      note: `${off} of ${count} priced listing${count === 1 ? "" : "s"} differ from ${formatPrice(ref)}` };
  }
  return { tone, headline: `Same price on the ${count} priced listing${count === 1 ? "" : "s"}`, figure: formatPrice(g.price_min), note: `${n - count} of ${n} listings have no price` };
}

/** The list's Price cell: one price ("Same price" when every listing matches), the range when they differ, a dash. */
export function priceCell(g: Group): { text: string; same: boolean; title: string } {
  const count = Number(g.price_count) || 0;
  const stale = Number(g.price_stale) || 0;
  if (!count) return { text: "—", same: false, title: stale ? "The only prices are old ones nobody can pay (out of stock, gone from the supplier’s feed)" : "No listing in this group has a MAP or store price" };
  const title = count < g.members.length && g.source !== "older"
    ? `${count} of ${g.members.length} listings have a current price${stale ? `; ${stale} old price${stale === 1 ? "" : "s"} not compared` : ""}` : "";
  if (g.price_spread > 0) return { text: `${formatPrice(g.price_min)} – ${formatPrice(g.price_max)}`, same: false, title };
  return { text: formatPrice(g.price_min), same: !!g.price_match, title };
}

/** The group's lowest non-zero cost listing (the server's recommendation, when it has a real cost); 0 when none. */
export function lowestCostId(members: Member[]): number {
  return members.find((m) => m.is_recommended && m.wc_product_id && Number(m.dealer_price) > 0)?.wc_product_id ?? 0;
}

/** The canonical a new group opens with: the lowest cost (owner rule 2026-09-30), else one already canonical, else the server's pick. */
export function preselectCanonical(g: Group): number {
  return lowestCostId(g.members)
    || (g.members.find((m) => m.is_canonical === true && m.wc_product_id) ?? g.members.find((m) => m.is_recommended === true))?.wc_product_id
    || 0;
}

/* ── Product data winners ────────────────────────────────────────────────── */

/** The fields a canonical listing can take from another: CanonicalCandidatesController's asset_field_defs(), same keys and order. */
export const ASSET_FIELDS: { key: AssetField; label: string }[] = [
  { key: "image", label: "Image" },
  { key: "gallery", label: "Gallery" },
  { key: "content", label: "Description" },
  { key: "title", label: "Title" },
  { key: "excerpt", label: "Short Desc" },
];
export type AssetField = "image" | "gallery" | "content" | "title" | "excerpt";

export function memberHasAsset(m: Member, f: AssetField): boolean {
  const a = m.assets;
  if (!a) return false;
  switch (f) {
    case "image": return !!a.has_image;
    case "gallery": return (a.gallery_count ?? 0) > 0;
    case "content": return !!a.has_content;
    case "title": return !!(a.title && a.title.trim());
    case "excerpt": return !!a.has_excerpt;
  }
}

/** What makes two listings' values the same (attachment ids, text fingerprints), for "Same as #…"; "" when unknown. */
export function assetValueKey(m: Member, f: AssetField): string {
  const a = m.assets ?? {};
  switch (f) {
    case "image": return a.image_id ? String(a.image_id) : "";
    case "gallery": return a.gallery_key ?? "";
    case "content": return a.content_hash ?? "";
    case "excerpt": return a.excerpt_hash ?? "";
    case "title": return (a.title ?? "").trim();
  }
}

/** The small fact beside a card's id: length or count. */
export function assetMeta(m: Member, f: AssetField): string {
  const a = m.assets ?? {};
  const plural = (n: number, w: string) => `${n} ${w}${n === 1 ? "" : "s"}`;
  switch (f) {
    case "gallery": return plural(a.gallery_count ?? 0, "picture");
    case "content": return a.content_words ? plural(a.content_words, "word") : "";
    case "excerpt": return a.excerpt_words ? plural(a.excerpt_words, "word") : "";
    case "title": return a.title ? plural(a.title.trim().length, "character") : "";
    default: return "";
  }
}

/** Fields at least one imported listing has a value for (no column of nothing but dashes). */
export const visibleAssetFields = (members: Member[]) => ASSET_FIELDS.filter((f) => members.some((m) => m.wc_product_id && memberHasAsset(m, f.key)));

/** Each field's default winner: the canonical if it has the field, else the recommended listing, else the first that does; 0 = none. */
export function winnerDefaults(members: Member[], canonicalId: number): Record<AssetField, number> {
  const out = {} as Record<AssetField, number>;
  const resolved = members.filter((m) => m.wc_product_id && m.assets);
  for (const f of ASSET_FIELDS) {
    const withField = resolved.filter((m) => memberHasAsset(m, f.key));
    out[f.key] = (withField.find((m) => m.wc_product_id === canonicalId) ?? withField.find((m) => m.is_recommended) ?? withField[0])?.wc_product_id ?? 0;
  }
  return out;
}

/** The cards of one field: each listing with a value, whether all are the same, and which earlier card a card repeats. */
export function fieldOptions(members: Member[], f: AssetField): { allSame: boolean; options: { member: Member; sameAs: number }[] } {
  const options = members.filter((m) => m.wc_product_id && memberHasAsset(m, f));
  const keys = options.map((m) => assetValueKey(m, f));
  const allSame = options.length > 1 && keys[0] !== "" && keys.every((k) => k === keys[0]);
  return {
    allSame,
    options: options.map((m, i) => {
      const first = keys[i] !== "" ? options[keys.indexOf(keys[i]!)] : undefined;
      return { member: m, sameAs: !allSame && first && first !== m ? first.wc_product_id : 0 };
    }),
  };
}

/* ── The Distributors catalog (mmi-distributors), when it's there ────────── */

export interface Catalog { url: string; sources: Record<string, string> }

/** A listing's row key in the Distributors catalog ("xchange:1175-251"), or "" when it has none. */
export const catalogKey = (c: Catalog | null, m: Member): string => (c && m.sku && c.sources[m.supplier] ? `${m.supplier}:${m.sku}` : "");

export function catalogUrl(c: Catalog, args: [string, string][]): string {
  const u = new URL(c.url, window.location.origin);
  for (const [k, v] of args) u.searchParams.append(k, v);
  return u.toString();
}

/** The group's feed listings side by side in the catalog: needs two listings it has; at most six. */
export function compareKeys(c: Catalog | null, g: Group): string[] {
  return [...new Set(g.members.map((m) => catalogKey(c, m)).filter(Boolean))].slice(0, 6);
}

/** Listings an older-listings Link would link: every member but the live one, over the groups given. */
export const olderLinkCount = (rows: Row[]): number => rows.filter((r) => r.source === "older").reduce((n, r) => n + r.members.length - 1, 0);
