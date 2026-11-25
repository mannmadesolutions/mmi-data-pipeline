/**
 * Duplicates, a pill of Needs a decision (moved from the Import tab 2026-11-25): listings that look like one
 * product, one decision a row. The list stays on the left (the split view, ui.tsx); the open group beside it shows
 * the price check, the listings and which listing's data the canonical keeps.
 *
 * - A new group: pick the canonical listing (the lowest cost is preselected, owner rule 2026-09-30), tick the
 *   listings that are the same item, Link; or Not a duplicate.
 * - A linked group: Make canonical, Remove a listing, Apply the picked data, Unlink the group.
 * - Collisions: two products holding one supplier tracking key; Keep this one sets the canonical.
 *
 * The two scans read the supplier feeds (cached 10 minutes server side), so they run when the pill is first opened,
 * never with the page: two requests at once, the auto-fired limit. Reload rescans. Decisions that change stock or
 * unlink ask for a second click on the same button (no dialogs).
 */
import { useCallback, useEffect, useMemo, useRef, useState, type CSSProperties, type ReactNode } from "react";
import { ApiError } from "./api";
import { dupesApi, OLDER_BATCH, type GroupChange } from "./dupesApi";
import {
  asRow, assetMeta, brandsOf, catalogKey, catalogUrl, chipCounts, chipOf, CHIPS, compareKeys, fieldOptions, fold, formatPrice, GROUP_AVAILABILITY, inStockCount,
  lowestCostId, matchBasis, MEMBER_AVAILABILITY, memberAvailability, nextSort, olderLinkCount, preselectCanonical, priceCell, priceState, referencePrice,
  SOURCE_BADGES, supplierName, verdict, visibleAssetFields, visibleRows, winnerDefaults,
  type AssetField, type Catalog, type Chip, type Collision, type Group, type Member, type Row, type Sort, type SortCol,
} from "./dupesLogic";
import { page } from "./globals";
import { landing } from "./landing";
import { DUPES_GROUP_EVENT, refreshCounts, takeDupesGroup, useSectionVisible } from "./nav";
import { Dashicon, Section, SortTh, useAddressParam, useColumnResize, useListKeys } from "./ui";

interface Notice { text: string; kind: "ok" | "error" | "warning" }

const msg = (e: unknown) => (e instanceof ApiError ? e.message : String(e));
const plural = (n: number, one: string, many = `${one}s`) => `${n.toLocaleString("en-US")} ${n === 1 ? one : many}`;
const editUrl = (id: number) => `post.php?post=${id}&action=edit`;
const VISIBILITY_LABELS: Record<string, string> = { catalog: "shop only", search: "search only", hidden: "hidden" };

/** Names stored with get_the_title() arrive with "&#038;": decoded once (a textarea decodes without running anything). */
function decode(s: string): string {
  const el = document.createElement("textarea");
  el.innerHTML = s;
  return el.value;
}

const collisionKey = (c: Collision) => `${c.supplier}:${c.value}`;

export function Duplicates() {
  const cfg = page().mmiWorkbench?.duplicates;
  const catalog: Catalog | null = cfg?.catalog?.url ? cfg.catalog : null;
  const visible = useSectionVisible();
  const [enabled, setEnabled] = useState(!!cfg?.enabled);
  const [candidates, setCandidates] = useState<Group[] | null>(null);
  const [existing, setExisting] = useState<Group[] | null>(null);
  const [collisions, setCollisions] = useState<Collision[] | null>(null);
  const [scanning, setScanning] = useState(0);
  // A group the product drawer asked for before this pill was drawn: every group listed, that one open.
  const [fromDrawer] = useState(takeDupesGroup);
  const [chip, setChip] = useState<Chip>(() => (fromDrawer ? "all" : chipOf(landing().dupes) || "new"));
  // A link's brand waits until a scan includes it (the two scans land separately).
  const [brand, setBrand] = useState("");
  const wantBrand = useRef(landing().dupesBrand.toLowerCase());
  const [q, setQ] = useState("");
  const [sort, setSort] = useState<Sort | null>(null);
  const [open, setOpen] = useState<string | null>(() => fromDrawer || landing().dupesGroup || null);
  // The product drawer's Open group: every group (a linked or older one isn't under New), that one open.
  useEffect(() => {
    const on = (e: Event) => { takeDupesGroup(); setChip("all"); setOpen((e as CustomEvent<string>).detail); };
    window.addEventListener(DUPES_GROUP_EVENT, on);
    return () => window.removeEventListener(DUPES_GROUP_EVENT, on);
  }, []);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);

  const scan = useCallback((fresh: boolean) => {
    setNotice(null);
    setScanning((n) => n + 2);
    dupesApi.existing(fresh).then((d) => setExisting(d.groups ?? [])).catch((e) => { setExisting((x) => x ?? []); setNotice({ text: `Linked groups: ${msg(e)}`, kind: "error" }); })
      .finally(() => setScanning((n) => n - 1));
    dupesApi.candidates(fresh).then((d) => setCandidates((d.groups ?? []).map((g) => ({ ...g, source: "candidate" as const })))).catch((e) => { setCandidates((x) => x ?? []); setNotice({ text: `Feed matches: ${msg(e)}`, kind: "error" }); })
      .finally(() => setScanning((n) => n - 1));
  }, []);
  const scanCollisions = useCallback((fresh: boolean) => {
    setScanning((n) => n + 1);
    dupesApi.collisions(fresh).then((d) => setCollisions(d.groups ?? [])).catch((e) => { setCollisions((x) => x ?? []); setNotice({ text: msg(e), kind: "error" }); })
      .finally(() => setScanning((n) => n - 1));
  }, []);

  // Seen with scanning on: each scan set runs once, the groups' when a groups chip is shown, the collisions' with theirs.
  const asked = useRef({ groups: false, collisions: false });
  useEffect(() => {
    if (!visible || !enabled) return;
    if (chip === "collisions" ? asked.current.collisions : asked.current.groups) return;
    if (chip === "collisions") { asked.current.collisions = true; scanCollisions(false); } else { asked.current.groups = true; scan(false); }
  }, [visible, enabled, chip, scan, scanCollisions]);

  const rows = useMemo(() => (candidates || existing ? fold([...(existing ?? []), ...(candidates ?? [])]) : null), [candidates, existing]);
  const brands = useMemo(() => (rows ? brandsOf(rows) : []), [rows]);
  useEffect(() => {
    if (wantBrand.current && brands.some(([k]) => k === wantBrand.current)) { setBrand(wantBrand.current); wantBrand.current = ""; }
  }, [brands]);
  const counts = rows ? chipCounts(rows) : null;
  const list = rows && chip !== "collisions" ? visibleRows(rows, chip, brand, q, sort) : [];
  // A group folded into another row opens that row.
  const openRow = chip !== "collisions" && open ? rows?.find((r) => r.group_key === open) ?? rows?.find((r) => r.coveredKeys.includes(open)) ?? null : null;
  const openCollision = chip === "collisions" ? collisions?.find((c) => collisionKey(c) === open) ?? null : null;

  useAddressParam("wb_dupes", chip, visible);
  useAddressParam("wb_dupes_group", open, visible);

  const choose = (c: Chip) => { setChip(c); setOpen(null); setNotice(null); };

  /* ── Changes, kept in step with the lists ─────────────────────────────── */

  /** A linked group re-read by the server replaces its row (null: it's gone). */
  const replaceGroup = (oldKey: string, g: Group | null) => {
    const swap = (l: Group[] | null) => (l ? (g ? l.map((x) => (x.group_key === oldKey ? g : x)) : l.filter((x) => x.group_key !== oldKey)) : l);
    setExisting(swap);
    setCandidates(swap);
    if (open === oldKey) setOpen(g ? g.group_key : null);
  };
  const groupDone = (oldKey: string) => (d: GroupChange) => {
    setNotice({ text: d.message, kind: "ok" });
    replaceGroup(d.old_group_key || oldKey, d.group ? asRow(d.group) : null);
    refreshCounts();
  };
  const run = async (work: () => Promise<void>) => {
    setBusy(true);
    setNotice(null);
    try { await work(); } catch (e) { setNotice({ text: msg(e), kind: "error" }); } finally { setBusy(false); }
  };

  const link = (r: Row, canonical: number, associated: number[], winners: Record<string, number>) => run(async () => {
    const d = await dupesApi.link(canonical, associated, winners, r.source === "mpn" || r.source === "title");
    const n = d.fields_applied?.length ?? 0;
    setNotice({ text: n ? `${d.message} Applied ${plural(n, "winning field")} to the canonical listing.` : d.message, kind: "ok" });
    const mark = (l: Group[] | null) => (l ? l.map((x) => (x.group_key === r.group_key || r.coveredKeys.includes(x.group_key) ? { ...x, already_linked: true } : x)) : l);
    setExisting(mark);
    setCandidates(mark);
    refreshCounts();
  });
  const dismiss = (r: Row) => run(async () => {
    const keys = [...new Set([r.group_key, ...r.coveredKeys])];
    await dupesApi.dismiss(keys);
    const kept = (l: Group[] | null) => (l ? l.filter((x) => !keys.includes(x.group_key)) : l);
    setExisting(kept);
    setCandidates(kept);
    setOpen(null);
    setNotice({ text: `${decode(r.name)}: not a duplicate. It won't be flagged again.`, kind: "ok" });
    refreshCounts();
  });
  const linkOlder = () => run(async () => {
    const keys = list.filter((r) => r.source === "older").map((r) => r.group_key);
    let linked = 0;
    const skipped: Record<string, string> = {};
    try {
      for (let i = 0; i < keys.length; i += OLDER_BATCH) {
        setNotice({ text: `Linking… ${i} of ${keys.length} groups`, kind: "ok" });
        const d = await dupesApi.linkOlder(keys.slice(i, i + OLDER_BATCH));
        linked += d.linked;
        Object.assign(skipped, d.skipped);
      }
    } catch (e) {
      setNotice({ text: `Linking stopped after ${linked}: ${msg(e)}`, kind: "error" });
      scan(true);
      return;
    }
    const left = Object.keys(skipped).length;
    setNotice({ text: `Linked ${plural(linked, "older listing")}.${left ? ` ${left} left to do by hand: ${[...new Set(Object.values(skipped))].join(" ")}` : ""}`, kind: left ? "warning" : "ok" });
    refreshCounts();
    scan(true);
  });
  const keepCollision = (c: Collision, keepId: number) => run(async () => {
    const others = c.members.filter((m) => m.product_id !== keepId).map((m) => m.product_id);
    await dupesApi.link(keepId, others, {}, false);
    setNotice({ text: `#${keepId} kept: the other product${others.length === 1 ? " is" : "s are"} now out of stock and off the shop search.`, kind: "ok" });
    scanCollisions(true);
    refreshCounts();
  });
  const toggle = (on: boolean) => run(async () => {
    const d = await dupesApi.setEnabled(on);
    setEnabled(d.enabled);
    // Switched on: the effect above scans what the chip shows.
  });

  /* ── The list ─────────────────────────────────────────────────────────── */

  // Collisions: needing review first, then by key, unless a heading says otherwise.
  const [cSort, setCSort] = useState<{ col: "key" | "n"; desc: boolean } | null>(null);
  const sortCollisionsBy = (col: "key" | "n") => setCSort((s) => (!s || s.col !== col ? { col, desc: col === "n" } : s.desc === (col === "n") ? { col, desc: !s.desc } : null));
  const collisionList = useMemo(() => {
    const l = [...(collisions ?? [])];
    const dir = cSort?.desc ? -1 : 1;
    if (cSort?.col === "n") return l.sort((a, b) => (a.members.length - b.members.length) * dir || collisionKey(a).localeCompare(collisionKey(b)));
    if (cSort?.col === "key") return l.sort((a, b) => collisionKey(a).localeCompare(collisionKey(b)) * dir);
    return l.sort((a, b) => Number(a.resolved) - Number(b.resolved) || collisionKey(a).localeCompare(collisionKey(b)));
  }, [collisions, cSort]);

  const tableRef = useRef<HTMLTableElement>(null);
  const listKeys = chip === "collisions" ? collisionList.map(collisionKey) : list.map((r) => r.group_key);
  useColumnResize(tableRef, listKeys.length > 0);
  const onKeys = useListKeys(tableRef, listKeys, open, setOpen);
  const sortBy = (col: SortCol) => setSort((s) => nextSort(s, col));
  const older = chip === "older" ? olderLinkCount(list) : 0;
  const loading = scanning > 0;

  const actions = (
    <>
      {older > 0 && <button type="button" className="button button-primary" id="mmi-wb-du-link-older" disabled={busy} onClick={() => void linkOlder()}>Link {plural(older, "older listing")}</button>}
      {enabled && (
        <button type="button" className={`button${loading ? " mmi-is-loading" : ""}`} id="mmi-wb-du-reload" disabled={loading || busy}
          title="Scan the supplier feeds and the links in the database again" onClick={() => (chip === "collisions" ? scanCollisions(true) : scan(true))}>
          {loading ? <span className="mmi-loading" aria-hidden="true" /> : <Dashicon name="update" />} {loading ? "Scanning…" : "Reload"}
        </button>
      )}
      <label className="mmi-wb-du-switch" title="Off: the supplier feeds aren't scanned for duplicates">
        <input type="checkbox" id="mmi-wb-du-enabled" checked={enabled} disabled={busy} onChange={(e) => void toggle(e.target.checked)} /> Scanning on
      </label>
    </>
  );

  return (
    <Section id="mmi-wb-duplicates" icon="randomize" title="Duplicates" actions={actions}
      desc="The same product listed more than once: matched by brand and name across the supplier feeds, by part number or title, or already linked. Pick the listing customers see and link the rest to it, or mark a group not a duplicate">
      {notice && <div className={`notice notice-${notice.kind === "error" ? "error" : notice.kind === "warning" ? "warning" : "success"} inline`} role="status" id="mmi-wb-du-notice"><p>{notice.text}</p></div>}
      {!enabled ? (
        <div className="mmi-wb-split-empty" id="mmi-wb-du-off">
          <p><strong>Scanning for duplicates is off.</strong></p>
          <p className="description">Switch it on above to scan the supplier feeds and the links in the database.</p>
        </div>
      ) : (
        <div className="mmi-wb-split mmi-wb-du-split">
          <div className="mmi-wb-split-list" id="mmi-wb-du-list">
            <div className="mmi-filter-pills mmi-wb-du-chips" role="group" aria-label="Which groups">
              {CHIPS.map((c) => {
                const n = c.value === "collisions" ? (collisions ? collisions.filter((x) => !x.resolved).length : null) : counts ? counts[c.value] : null;
                return (
                  <button key={c.value} type="button" className={`mmi-pill${chip === c.value ? " active" : ""}`} aria-pressed={chip === c.value} data-chip={c.value} title={c.hint} onClick={() => choose(c.value)}>
                    {c.label} <span className="mmi-pill-count">{n === null ? "…" : n.toLocaleString("en-US")}</span>
                  </button>
                );
              })}
            </div>
            {chip !== "collisions" && (
              <div className="mmi-wb-split-filters">
                <select id="mmi-wb-du-brand" aria-label="Brand" value={brand} onChange={(e) => setBrand(e.target.value)}>
                  <option value="">Every brand ({brands.length})</option>
                  {brands.map(([k, name]) => <option key={k} value={k}>{name}</option>)}
                </select>
                <input type="search" id="mmi-wb-du-q" aria-label="Find groups" placeholder="Name, SKU or ID" value={q} onChange={(e) => setQ(e.target.value)} />
                <span className="mmi-wb-count" id="mmi-wb-du-count">{rows ? plural(list.length, "group") : ""}</span>
              </div>
            )}
            <div className="mmi-table-scroll-wrapper mmi-wb-split-scroll">
              {chip === "collisions" ? (
                <table ref={tableRef} className="wp-list-table widefat striped mmi-wb-pr-table" id="mmi-wb-du-collisions">
                  <thead>
                    <tr>
                      <SortTh col="key" label="Tracking key" active={cSort?.col === "key"} desc={!!cSort?.desc} onSort={sortCollisionsBy} />
                      <SortTh col="n" label="Products" active={cSort?.col === "n"} desc={!!cSort?.desc} onSort={sortCollisionsBy} className="mmi-wb-split-num" />
                    </tr>
                  </thead>
                  <tbody onKeyDown={onKeys}>
                    {!collisions && <tr><td colSpan={2} className="mmi-wb-empty-cell">Looking for shared tracking keys…</td></tr>}
                    {collisions && !collisions.length && <tr><td colSpan={2} className="mmi-wb-empty-cell">No two products share a tracking key.</td></tr>}
                    {collisionList.map((c) => (
                      <tr key={collisionKey(c)} data-key={collisionKey(c)} className={`mmi-wb-split-row${open === collisionKey(c) ? " is-open" : ""}`} onClick={() => setOpen(collisionKey(c))}>
                        <td>
                          <button type="button" className="button-link mmi-wb-split-name" aria-current={open === collisionKey(c) ? "true" : undefined}><code>{c.value}</code></button>
                          <div className="mmi-wb-split-meta"><span>{c.supplier}</span><span className={`mmi-badge inline ${c.resolved ? "success" : "warning"}`}>{c.resolved ? "Resolved" : "Needs review"}</span></div>
                        </td>
                        <td className="mmi-wb-split-num">{c.members.length}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              ) : (
                <table ref={tableRef} className="wp-list-table widefat striped mmi-wb-pr-table" id="mmi-wb-du-table">
                  <thead>
                    <tr>
                      <SortTh col="name" label="Product" active={sort?.col === "name"} desc={!!sort?.desc} onSort={sortBy} />
                      <SortTh col="price" label="Price" title="MAP from the supplier feed, else the store price. Sorts by how far apart the listings' prices are" active={sort?.col === "price"} desc={!!sort?.desc} onSort={sortBy} className="mmi-wb-du-col-price" />
                      <SortTh col="members" label="#" title="Listings in the group" active={sort?.col === "members"} desc={!!sort?.desc} onSort={sortBy} className="mmi-wb-split-num" />
                    </tr>
                  </thead>
                  <tbody id="mmi-wb-du-rows" onKeyDown={onKeys}>
                    {!rows && <tr><td colSpan={3} className="mmi-wb-empty-cell">Scanning the supplier feeds and the links in the database…</td></tr>}
                    {rows && !list.length && <tr><td colSpan={3} className="mmi-wb-empty-cell">{brand || q ? "Nothing here." : chip === "new" ? "No duplicates wait on you." : "Nothing here."}</td></tr>}
                    {list.map((r) => <ListRow key={r.group_key} r={r} open={openRow?.group_key === r.group_key} onOpen={() => setOpen(r.group_key)} />)}
                  </tbody>
                </table>
              )}
            </div>
            {listKeys.length > 1 && <p className="description mmi-wb-split-keys">↑ and ↓ move between groups.</p>}
          </div>

          <div className="mmi-wb-split-work" id="mmi-wb-du-work">
            {openRow ? (openRow.source === "legacy"
              ? <LinkedGroup key={openRow.group_key} r={openRow} catalog={catalog} busy={busy}
                  onWinners={(w) => void run(async () => groupDone(openRow.group_key)(await dupesApi.applyWinners(openRow.canonical_product_id ?? 0, w)))}
                  onMakeCanonical={(id) => void run(async () => groupDone(openRow.group_key)(await dupesApi.makeCanonical(openRow.canonical_product_id ?? 0, id)))}
                  onRemove={(id) => void run(async () => groupDone(openRow.group_key)(await dupesApi.removeMember(openRow.canonical_product_id ?? 0, id)))}
                  onUnlink={() => void run(async () => groupDone(openRow.group_key)(await dupesApi.unlink(openRow.canonical_product_id ?? 0)))} />
              : <NewGroup key={openRow.group_key} r={openRow} catalog={catalog} busy={busy} onLink={(c, a, w) => void link(openRow, c, a, w)} onDismiss={() => void dismiss(openRow)} />)
              : openCollision ? <CollisionPanel key={collisionKey(openCollision)} c={openCollision} busy={busy} onKeep={(id) => void keepCollision(openCollision, id)} />
              : (
                <div className="mmi-wb-split-empty" id="mmi-wb-du-empty">
                  <p><strong>{chip === "collisions" ? "Choose a tracking key on the left." : "Choose a group on the left."}</strong></p>
                  <p className="description">{chip === "collisions"
                    ? "It opens here with the products that share it. Only one of them gets the feed's price and stock; keep that one live."
                    : "It opens here: what the prices say, each listing with its stock and price, and which listing's data the canonical keeps."}</p>
                </div>
              )}
          </div>
        </div>
      )}
    </Section>
  );
}

/* ── The list's row ───────────────────────────────────────────────────────── */

function ListRow({ r, open, onOpen }: { r: Row; open: boolean; onOpen: () => void }) {
  const price = priceCell(r);
  const stock = GROUP_AVAILABILITY[r.availability];
  const src = SOURCE_BADGES[r.source] ?? SOURCE_BADGES.candidate;
  const status = r.nothing_on_sale ? ["Nothing on sale", "warning"] : r.already_linked ? ["Linked", "success"] : null;
  return (
    <tr data-key={r.group_key} data-source={r.source} className={`mmi-wb-split-row${open ? " is-open" : ""}${r.availability === "notsold" ? " is-not-sold" : ""}`} onClick={onOpen}>
      <td>
        <button type="button" className="button-link mmi-wb-split-name" aria-current={open ? "true" : undefined}>{decode(r.name)}</button>
        <div className="mmi-wb-split-meta">
          {r.brand && <span>{r.brand}</span>}
          <span className={`mmi-badge inline ${src.cls}`} title={src.title(r)}>{src.label}</span>
          {r.alsoSources.map((s) => <span key={s} className="mmi-badge inline" title={`Also grouped by ${SOURCE_BADGES[s].label}: this row holds that group's listings too`}>+ {SOURCE_BADGES[s].label}</span>)}
          <span className={`mmi-badge inline ${stock.badge}`} title={stock.title}>{stock.label}</span>
          {status && <span className={`mmi-badge inline ${status[1]}`}>{status[0]}</span>}
        </div>
      </td>
      <td className="mmi-wb-du-col-price" title={price.title || undefined}>{price.text}{price.same && <div className="mmi-wb-du-same">Same price</div>}</td>
      <td className="mmi-wb-split-num" title={`${inStockCount(r)} of ${r.members.length} listings in stock`}>{r.members.length}</td>
    </tr>
  );
}

/* ── The open group ───────────────────────────────────────────────────────── */

/** The open group's head: name, the price check, the hints, the legend, "Compare side by side". */
function GroupHead({ r, canonicalId, catalog, hints }: { r: Row; canonicalId: number; catalog: Catalog | null; hints: string[] }) {
  const ref = referencePrice(r.members, canonicalId);
  const v = verdict(r, ref);
  const states = new Set(r.members.map((m) => priceState(m, ref)));
  const compare = compareKeys(catalog, r);
  return (
    <>
      <div className="mmi-wb-split-head">
        <div className="mmi-wb-split-title">
          <h4>{r.brand_thumbnail_url && <img src={r.brand_thumbnail_url} alt="" loading="lazy" />}{decode(r.name)}</h4>
          <p className="description">{[r.brand, plural(r.members.length, "listing")].filter(Boolean).join(" · ")}</p>
        </div>
      </div>
      <div className={`mmi-wb-du-verdict is-${v.tone}`}>
        <div className="mmi-wb-du-verdict-text">
          <span className="mmi-wb-du-verdict-label">Price check</span>
          <span className="mmi-wb-du-verdict-headline">{v.headline}</span>
          <span className="mmi-wb-du-verdict-note">{matchBasis(r)}</span>
        </div>
        <div className="mmi-wb-du-verdict-figure">
          <span className="mmi-wb-du-verdict-amount">{v.figure}</span>
          <span className="mmi-wb-du-verdict-note">{v.note}</span>
        </div>
      </div>
      <SaleAlert r={r} />
      {hints.filter(Boolean).map((h) => <p key={h} className="mmi-wb-du-hint">{h}</p>)}
      {r.price_count > 0 && (
        <div className="mmi-wb-du-legend">
          <span className="mmi-wb-du-key">Price {formatPrice(ref)}</span>
          {states.has("off") && <span className="mmi-wb-du-key is-off">Different price</span>}
          {states.has("none") && <span className="mmi-wb-du-key is-none">No price</span>}
          {states.has("stale") && <span className="mmi-wb-du-key is-stale" title="Out of stock and gone from its supplier’s feed: a leftover price nobody can pay, left out of the comparison">Old price, not compared</span>}
          <span className="mmi-wb-du-legend-note">MAP from the supplier feed, else the store price</span>
        </div>
      )}
      {catalog && compare.length >= 2 && (
        <p className="mmi-wb-du-compare">
          <a className="button button-small" target="_blank" rel="noopener noreferrer" href={catalogUrl(catalog, [["fc_compare", compare.join(",")]])}><Dashicon name="columns" /> Compare side by side</a>
          <span className="description">Opens these listings next to each other in the Distributors catalog, in a new tab: names, prices and every feed field, differences highlighted.</span>
        </p>
      )}
    </>
  );
}

/** Not sold (nothing anyone sells) replaces Nothing on sale (a linked group's canonical out of stock, its duplicates held out). */
function SaleAlert({ r }: { r: Row }) {
  if (r.availability === "notsold") {
    return (
      <div className="mmi-wb-du-alert is-not-sold" role="status">
        <Dashicon name="dismiss" /><span><strong>Nothing here can be sold.</strong> Every listing is out of stock and either gone from its supplier’s feed or held out by a stock rule, so which one is canonical changes nothing customers can buy. It can be left as it is.</span>
      </div>
    );
  }
  if (!r.nothing_on_sale) return null;
  const cand = r.members.find((m) => m.wc_product_id === r.sale_candidate_id);
  const legacy = r.source === "legacy";
  return (
    <div className="mmi-wb-du-alert" role="status">
      <Dashicon name="warning" />
      <span><strong>Nothing in this group is on sale.</strong> Canonical #{r.no_sale_canonical_id || r.canonical_product_id} is out of stock, and its duplicates are held out of stock.{" "}
        {cand ? `#${cand.wc_product_id} is still in the ${supplierName(cand)} feed: ${legacy ? "Make canonical on it" : "picking it as the canonical listing and linking"} puts the product back on sale at the next catalog run.`
          : "None of its listings is in a supplier feed now, so there is no listing to switch to."}</span>
    </div>
  );
}

/** One listing: picture, title and facts, a price bar sized against the group's highest price, the price, its controls. */
function Listing({ m, refPrice, maxPrice, lowestId, canonical, catalog, children }: {
  m: Member; refPrice: number; maxPrice: number; lowestId: number; canonical: boolean; catalog: Catalog | null; children: ReactNode;
}) {
  const resolved = !!m.wc_product_id;
  const state = priceState(m, refPrice);
  const price = Number(m.list_price) || 0;
  const avail = memberAvailability(m);
  const def = MEMBER_AVAILABILITY[avail];
  const title = m.assets?.title || m.product_name || "";
  const cost = Number(m.dealer_price) || 0;
  const store = Number(m.store_price) || 0;
  const isLowest = !!lowestId && m.wc_product_id === lowestId;
  const sub = [state === "stale" ? "Old price, not compared" : m.list_price_source === "map" ? "MAP" : m.list_price_source === "store" ? "Store price" : "No price"];
  if (m.list_price_source === "map" && store > 0 && store.toFixed(2) !== price.toFixed(2)) sub.push(`store ${formatPrice(store)}`);
  const width = state !== "stale" && price > 0 && maxPrice > 0 ? Math.max(3, Math.round((price / maxPrice) * 100)) : 0;
  const key = catalogKey(catalog, m);
  const facts = [m.supplier, m.vendor].filter(Boolean).join(" · ");
  return (
    <li className={`mmi-wb-du-listing is-price-${state} is-avail-${avail}${resolved ? "" : " is-unresolved"}${canonical ? " is-canonical" : ""}`} data-product={m.wc_product_id || undefined}>
      <div className="mmi-wb-du-thumb">{m.assets?.image_thumb_url ? <img src={m.assets.image_thumb_url} alt="" loading="lazy" /> : <Dashicon name="format-image" />}</div>
      <div className="mmi-wb-du-name">
        <span className="mmi-wb-du-name-head">
          {resolved ? <a className="mmi-wb-du-title" href={editUrl(m.wc_product_id)} target="_blank" rel="noopener noreferrer">{title || `#${m.wc_product_id}`}</a>
            : <span className="mmi-wb-du-title">{title || "Not imported yet"}</span>}
          {resolved && <span className="mmi-wb-du-id">#{m.wc_product_id}</span>}
          <span className={`mmi-badge inline ${def.badge}`} title={def.title(supplierName(m), m)}>{def.label(supplierName(m))}</span>
        </span>
        <span className="mmi-wb-du-meta">
          {facts && <span className="mmi-wb-du-meta-text">{facts}</span>}
          {m.sku && (
            <span className="mmi-wb-du-sku">{facts ? " · " : ""}SKU {m.sku}
              {catalog && key && (
                <a className="mmi-wb-du-catalog" target="_blank" rel="noopener noreferrer" title={`Open SKU ${m.sku} in the Distributors catalog (new tab)`} aria-label={`Open SKU ${m.sku} in the Distributors catalog (new tab)`}
                  href={catalogUrl(catalog, [["fc_q", m.sku], ["fc[source][]", catalog.sources[m.supplier]!], ["fc_open", key]])}><Dashicon name="products" /></a>
              )}
            </span>
          )}
          {resolved && m.visibility && m.visibility !== "visible" && <span className="mmi-wb-du-meta-text">{` · ${VISIBILITY_LABELS[m.visibility] ?? m.visibility}`}</span>}
        </span>
      </div>
      <div className="mmi-wb-du-bar" aria-hidden="true"><span className="mmi-wb-du-bar-fill" style={{ "--mmi-wb-du-bar-w": `${width}%` } as CSSProperties} /></div>
      <div className="mmi-wb-du-figure">
        <span className="mmi-wb-du-amount">{price > 0 ? formatPrice(price) : "—"}</span>
        <span className="mmi-wb-du-sub">{sub.join(" · ")}{cost > 0 && <> · <span className={`mmi-wb-du-cost${isLowest ? " is-lowest" : ""}`} title={isLowest ? "Lowest cost in this group" : undefined}>{isLowest ? "lowest cost" : "cost"} {formatPrice(cost)}</span></>}</span>
      </div>
      <div className="mmi-wb-du-controls">{children}</div>
    </li>
  );
}

/** Listing context the rows share: the reference price, the highest price, the lowest-cost listing. */
function listingContext(r: Row, canonicalId: number) {
  return {
    refPrice: referencePrice(r.members, canonicalId),
    maxPrice: Math.max(0, ...r.members.map((m) => (m.price_stale ? 0 : Number(m.list_price) || 0))),
    lowestId: lowestCostId(r.members),
  };
}

/** Per field, one card per listing that has a value, the value shown so the cards compare side by side. */
function FieldPickers({ r, winners, onPick, hint }: { r: Row; winners: Record<AssetField, number>; onPick: (f: AssetField, id: number) => void; hint: string }) {
  const fields = visibleAssetFields(r.members);
  if (!fields.length) return null;
  return (
    <section className="mmi-wb-du-fields" aria-label="Product data">
      <div className="mmi-wb-du-fields-head"><strong>Product data</strong><span className="description">{hint}</span></div>
      {fields.map((f) => {
        const { allSame, options } = fieldOptions(r.members, f.key);
        return (
          <div key={f.key} className="mmi-wb-du-field" data-field={f.key}>
            <div className="mmi-wb-du-field-label"><span>{f.label}</span>{allSame && <span className="description">Same on every listing</span>}</div>
            <div className="mmi-wb-du-picks" role="radiogroup" aria-label={f.label}>
              {options.map(({ member: m, sameAs }) => {
                const picked = winners[f.key] === m.wc_product_id;
                return (
                  <label key={m.wc_product_id} className={`mmi-wb-du-pick${picked ? " is-picked" : ""}`}>
                    <span className="mmi-wb-du-pick-head">
                      <input type="radio" name={`mmi-wb-du-w-${r.group_key}-${f.key}`} checked={picked} onChange={() => onPick(f.key, m.wc_product_id)} />
                      <strong>#{m.wc_product_id}</strong>
                      <span className="description">{assetMeta(m, f.key)}</span>
                      {sameAs > 0 && <span className="mmi-wb-du-pick-same">Same as #{sameAs}</span>}
                    </span>
                    <PickPreview m={m} f={f.key} />
                  </label>
                );
              })}
            </div>
          </div>
        );
      })}
    </section>
  );
}

function PickPreview({ m, f }: { m: Member; f: AssetField }) {
  const a = m.assets ?? {};
  if (f === "image") return a.image_thumb_url ? <img className="mmi-wb-du-pick-image" src={a.image_thumb_url} alt="" loading="lazy" /> : null;
  if (f === "gallery") {
    const thumbs = a.gallery_thumbs ?? [];
    if (!thumbs.length) return null;
    const more = (a.gallery_count ?? 0) - thumbs.length;
    return <div className="mmi-wb-du-pick-gallery">{thumbs.map((u) => <img key={u} src={u} alt="" loading="lazy" />)}{more > 0 && <span className="description">+{more}</span>}</div>;
  }
  const text = f === "content" ? a.content_preview : f === "excerpt" ? a.excerpt_preview : a.title;
  return <p className={`mmi-wb-du-pick-text${f === "title" ? " is-title" : ""}`}>{text ?? ""}</p>;
}

/** A group to decide: the canonical and the listings to link, which listing's data it keeps, Link or Not a duplicate. */
function NewGroup({ r, catalog, busy, onLink, onDismiss }: {
  r: Row; catalog: Catalog | null; busy: boolean; onLink: (canonical: number, associated: number[], winners: Record<string, number>) => void; onDismiss: () => void;
}) {
  const [canonical, setCanonical] = useState(() => preselectCanonical(r));
  const [included, setIncluded] = useState<Set<number>>(() => new Set(r.members.map((m) => m.wc_product_id).filter(Boolean)));
  const [winners, setWinners] = useState(() => winnerDefaults(r.members, preselectCanonical(r)));
  const [armed, setArmed] = useState<"link" | "dismiss" | null>(null);
  const [said, setSaid] = useState("");
  const ctx = listingContext(r, canonical);
  const associated = [...included].filter((id) => id && id !== canonical);
  const hints = [
    `Pick the canonical listing (the one customers see; ${r.source === "older" ? "the one on sale" : "the lowest cost"} is preselected) and tick every listing that is the same item.`
      + (r.members.some((m) => !m.wc_product_id) ? " Listings not imported yet can’t be linked until they are." : ""),
    r.multiple_canonicals ? "More than one is marked canonical: linking keeps the one you pick and folds the other’s links into it." : "",
    r.covers.length ? `This row replaces ${r.covers.join(" and ")}; linking here completes it.` : "",
  ];
  if (r.already_linked) hints.push("Linked: these listings are one product now.");
  const doLink = () => {
    if (!canonical) { setSaid("Pick a canonical listing first."); return; }
    if (!associated.length) { setSaid("Tick at least one other listing to link."); return; }
    if (armed !== "link") { setArmed("link"); setSaid(""); return; }
    setArmed(null);
    const w: Record<string, number> = {};
    for (const [k, v] of Object.entries(winners)) if (v) w[k] = v;
    onLink(canonical, associated, w);
  };
  const doDismiss = () => { if (armed !== "dismiss") { setArmed("dismiss"); return; } setArmed(null); onDismiss(); };
  return (
    <div className="mmi-wb-du-review" data-group={r.group_key}>
      <GroupHead r={r} canonicalId={canonical} catalog={catalog} hints={hints} />
      <ul className="mmi-wb-du-listings">
        {r.members.map((m, i) => {
          const id = m.wc_product_id;
          return (
            <Listing key={id || `feed-${i}`} m={m} {...ctx} canonical={!!id && id === canonical} catalog={catalog}>
              <label className={`mmi-wb-du-choice${id && id === canonical ? " is-on" : ""}`}>
                <input type="radio" name={`mmi-wb-du-canon-${r.group_key}`} disabled={!id || r.already_linked} checked={!!id && id === canonical}
                  onChange={() => { setCanonical(id); setArmed(null); }} /> Canonical
              </label>
              <label className={`mmi-wb-du-choice${id && included.has(id) ? " is-on" : ""}`}>
                <input type="checkbox" disabled={!id || r.already_linked} checked={!!id && included.has(id)}
                  onChange={(e) => { setIncluded((s) => { const n = new Set(s); if (e.target.checked) n.add(id); else n.delete(id); return n; }); setArmed(null); }} /> Include
              </label>
            </Listing>
          );
        })}
      </ul>
      <FieldPickers r={r} winners={winners} onPick={(f, id) => setWinners((w) => ({ ...w, [f]: id }))}
        hint="For each field, pick whose value the canonical listing keeps, whichever listing is canonical." />
      {said && <p className="mmi-wb-du-said" role="alert">{said}</p>}
      {!r.already_linked && (
        <div className="mmi-wb-du-actions">
          <button type="button" className={`button${armed === "dismiss" ? " is-armed" : ""}`} id="mmi-wb-du-dismiss" disabled={busy} onClick={doDismiss}
            title="Not the same product: hide this group, and it isn't flagged again"><Dashicon name="no-alt" /> {armed === "dismiss" ? "Click again: not a duplicate" : "Not a duplicate"}</button>
          <button type="button" className={`button button-primary${armed === "link" ? " is-armed" : ""}`} id="mmi-wb-du-link" disabled={busy} onClick={doLink}
            title="The other ticked listings go out of stock and off the shop search right away">
            <Dashicon name="yes-alt" /> {armed === "link" ? `Click again: link ${plural(associated.length, "listing")} to #${canonical}` : "Link selected"}
          </button>
        </div>
      )}
    </div>
  );
}

/** A linked group: Make canonical and Remove per listing, Apply the picked data, Unlink the whole group. */
function LinkedGroup({ r, catalog, busy, onWinners, onMakeCanonical, onRemove, onUnlink }: {
  r: Row; catalog: Catalog | null; busy: boolean; onWinners: (w: Record<string, number>) => void; onMakeCanonical: (id: number) => void; onRemove: (id: number) => void; onUnlink: () => void;
}) {
  const canonical = r.canonical_product_id ?? 0;
  const [winners, setWinners] = useState(() => winnerDefaults(r.members, canonical));
  const [armed, setArmed] = useState<string | null>(null);
  const ctx = listingContext(r, canonical);
  const arm = (key: string, go: () => void) => { if (armed !== key) { setArmed(key); return; } setArmed(null); go(); };
  const fields = visibleAssetFields(r.members);
  return (
    <div className="mmi-wb-du-review" data-group={r.group_key}>
      <GroupHead r={r} canonicalId={canonical} catalog={catalog}
        hints={["Removing a listing resets it to a plain, unlinked product (it can come back to this queue later); Make canonical moves which listing customers see."]} />
      <ul className="mmi-wb-du-listings">
        {r.members.map((m) => (
          <Listing key={m.wc_product_id} m={m} {...ctx} canonical={!!m.is_canonical} catalog={catalog}>
            {m.is_canonical ? <span className="mmi-badge success inline">✓ Canonical</span> : (
              <>
                <button type="button" className={`button button-small${m.wc_product_id === r.sale_candidate_id ? " button-primary" : ""}${armed === `c:${m.wc_product_id}` ? " is-armed" : ""}`}
                  data-make-canonical={m.wc_product_id} disabled={busy} title="Customers see this listing; the others go out of stock and off the shop search right away"
                  onClick={() => arm(`c:${m.wc_product_id}`, () => onMakeCanonical(m.wc_product_id))}>
                  {armed === `c:${m.wc_product_id}` ? `Click again: #${m.wc_product_id} canonical` : "Make canonical"}
                </button>
                <button type="button" className={`button button-small${armed === `r:${m.wc_product_id}` ? " is-armed" : ""}`} data-remove={m.wc_product_id} disabled={busy}
                  title="Reset this listing to a plain, unlinked product" onClick={() => arm(`r:${m.wc_product_id}`, () => onRemove(m.wc_product_id))}>
                  {armed === `r:${m.wc_product_id}` ? "Click again to remove" : "Remove"}
                </button>
              </>
            )}
          </Listing>
        ))}
      </ul>
      <FieldPickers r={r} winners={winners} onPick={(f, id) => setWinners((w) => ({ ...w, [f]: id }))}
        hint="Pick a value for any field, then Apply picked data copies it onto the canonical listing." />
      <div className="mmi-wb-du-actions">
        <button type="button" className={`button${armed === "unlink" ? " is-armed" : ""}`} id="mmi-wb-du-unlink" disabled={busy}
          title="Reset every listing of this group, the canonical too, to a plain, unlinked product" onClick={() => arm("unlink", onUnlink)}>
          <Dashicon name="editor-unlink" /> {armed === "unlink" ? "Click again to unlink every listing" : "Unlink group"}
        </button>
        {fields.length > 0 && (
          <button type="button" className="button button-primary" id="mmi-wb-du-apply" disabled={busy} title="Copy each picked value onto the canonical listing"
            onClick={() => { const w: Record<string, number> = {}; for (const [k, v] of Object.entries(winners)) if (v) w[k] = v; onWinners(w); }}>
            <Dashicon name="yes-alt" /> Apply picked data
          </button>
        )}
      </div>
    </div>
  );
}

/** Products sharing one supplier tracking key: keep one live, the others go out of stock and hidden. */
function CollisionPanel({ c, busy, onKeep }: { c: Collision; busy: boolean; onKeep: (id: number) => void }) {
  const [armed, setArmed] = useState<number | null>(null);
  return (
    <div className="mmi-wb-du-review" data-collision={collisionKey(c)}>
      <div className="mmi-wb-split-head">
        <div className="mmi-wb-split-title">
          <h4><code>{c.value}</code></h4>
          <p className="description">{c.supplier} tracking key · {plural(c.members.length, "product")}. Only one of them gets the feed's price and stock; the other stops updating.</p>
        </div>
      </div>
      <ul className="mmi-wb-du-collision-list">
        {c.members.map((m) => {
          const kept = c.resolved && c.canonical_id === m.product_id;
          const others = c.members.filter((x) => x.product_id !== m.product_id);
          const reverb = others.filter((x) => x.reverb_sync).length;
          return (
            <li key={m.product_id} className={`mmi-wb-du-collision${kept ? " is-canonical" : ""}`} data-product={m.product_id}>
              <div>
                <a href={m.product_url} target="_blank" rel="noopener noreferrer"><strong>{m.product_name || "(untitled)"}</strong></a> <span className="description">#{m.product_id}</span>
                <div className="description">SKU {m.sku || "—"} · ${m.regular_price || "0"} · {m.stock_status || "—"}{m.post_status !== "publish" ? ` · ${m.post_status}` : ""}</div>
                {m.reverb_sync && <span className="mmi-badge warning inline" title={`Synced to its own Reverb listing (#${m.reverb_listing_id})`}>Reverb-synced</span>}
              </div>
              <div className="mmi-wb-du-controls">
                {kept ? <span className="mmi-badge success inline">✓ Canonical</span>
                  : c.resolved ? <span className="description">Held out of stock, hidden</span>
                  : (
                    <button type="button" className={`button button-small${m.is_recommended ? " button-primary" : ""}${armed === m.product_id ? " is-armed" : ""}`} disabled={busy}
                      data-keep={m.product_id} title={m.is_recommended ? "The one every import already resolves to" : undefined}
                      onClick={() => { if (armed !== m.product_id) { setArmed(m.product_id); return; } setArmed(null); onKeep(m.product_id); }}>
                      {armed === m.product_id
                        ? `Click again: keep #${m.product_id}, hide ${others.map((x) => `#${x.product_id}`).join(", ")}${reverb ? ` (${reverb} Reverb listing${reverb === 1 ? "" : "s"} unpublished)` : ""}`
                        : `Keep this one${m.is_recommended ? " (recommended)" : ""}`}
                    </button>
                  )}
              </div>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
