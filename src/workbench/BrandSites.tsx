/**
 * An open brand in Makers (Makers.tsx; Product Data's BrandSites): where its maker publishes its product pages, and
 * its products matched to them.
 *
 * - A header (the brand, its maker sites, Set aside), lines for what waits on the whole brand (pages that moved,
 *   bundles with no maker page, what its products wait for), then the brand's tabs in the order the work goes.
 * - Pages: the products as batches (owner 2026-11-14, Makers layout A): proposals grouped by why, each batch decided
 *   once for the pages ticked (the sure ones start ticked), then pages gone or moved, no page yet, and the rest. A row
 *   is one line; More opens it as Maker pages decides it. "Find their pages" reads every maker site's own product
 *   list and lists the matches as a batch of their own, the sure ones ticked, saved together.
 * - Map and Fill: what Makers hands in (the maker site's map, and its Fill: SiteMaps.tsx's SitePanel in each focus).
 * - Sites: each switched to what it is (the maker's own, a partner, a reseller, a store, another brand's), a site
 *   added, the Brand website.
 */
import { useCallback, useEffect, useRef, useState, type ReactNode } from "react";
import {
  batchesOf, batchKey, bestMatches, drawerRow, hostOf, isMaker, makerHosts, offHosts, PRODUCT_GROUPS, ROLES, rolesBody, roleLabel, shortUrl, SURE, sureIds, ticks, toDo,
  toDoText, type Batch, type Brand, type Match, type ProductGroup, type ProductsReply, type SitePages, type SiteRole,
} from "./brandsApi";
import { bulkUseText } from "./pagesApi";
import { msg, usePageRows, type Notice } from "./pageRows";
import type { PageListReply, PageRow } from "./pagesApi";
import { rest, type TitleRulesConfig } from "./titleRulesApi";
import { Dashicon, SplitTabs, type SplitTab } from "./ui";

const sleep = (ms: number) => new Promise((r) => window.setTimeout(r, ms));
const POLL = { ms: 3000, tries: 40 };

export type BrandTab = "products" | "map" | "fill" | "sites";
const plural = (n: number, one: string, many = `${one}s`) => `${n.toLocaleString("en-US")} ${n === 1 ? one : many}`;

export interface BrandStep { body: ReactNode; count: string; dot: boolean }
/** map, fill: those tabs' content, count and dot (Makers draws the maker site's map and its Fill). */
export function BrandDrawer({ cfg, brand, onBrand, tab, onTab, map, fill }: {
  cfg: TitleRulesConfig; brand: Brand; onBrand: (b: Brand) => void; tab: BrandTab; onTab: (t: BrandTab) => void;
  map: BrandStep; fill: BrandStep;
}) {
  const [notice, setNotice] = useState<Notice | null>(null);
  const [saving, setSaving] = useState<string | null>(null);
  const [armed, setArmed] = useState<string | null>(null);
  const [addHost, setAddHost] = useState("");
  const [addRole, setAddRole] = useState<SiteRole>("official");
  const [website, setWebsite] = useState(brand.website);
  const [group, setGroup] = useState<ProductGroup>("work");
  const [page, setPage] = useState(1);
  const [reply, setReply] = useState<ProductsReply | null>(null);
  const [rows, setRows] = useState<PageRow[]>([]);
  const [finding, setFinding] = useState<string | null>(null);
  const [found, setFound] = useState<{ matches: Map<number, Match>; ticked: number[] } | null>(null);
  // Batches: where each row was when the list was read, the proposals ticked, the batches shut and the rows opened.
  const [placed, setPlaced] = useState<Record<number, string>>({});
  const [ticked, setTicked] = useState<Set<number>>(new Set());
  const [shut, setShut] = useState<Set<string>>(new Set());
  const [opened, setOpened] = useState<Set<number>>(new Set());
  const seq = useRef(0);
  const ref = useRef<HTMLDivElement>(null);
  // The brand opens beside the list; when the page was scrolled past its top, bring its top into view.
  useEffect(() => { if (ref.current && ref.current.getBoundingClientRect().top < 0) ref.current.scrollIntoView({ block: "start" }); }, []);

  const loadProducts = useCallback(async (g: ProductGroup, p: number) => {
    const mine = ++seq.current;
    try {
      const d = await rest<ProductsReply>(cfg, "GET", `brand-sites/${brand.id}/products?group=${g}&page=${p}`);
      if (mine === seq.current) {
        const fresh = d.rows.map(drawerRow);
        const off = offHosts(brand);
        const batches = batchesOf(fresh, {}, off);
        setReply(d);
        setRows(fresh);
        setPlaced(Object.fromEntries(fresh.map((r) => [r.id, batchKey(r, off)])));
        setTicked(new Set(batches.flatMap(sureIds)));
        // A long list opens on its biggest batch; a short one shows everything.
        setShut(new Set(fresh.length > 20 ? batches.slice(1).map((b) => b.key) : []));
        setOpened(new Set());
      }
    } catch (e) { if (mine === seq.current) { setRows([]); setNotice({ text: msg(e), kind: "error" }); } }
  }, [cfg, brand.id]); // eslint-disable-line react-hooks/exhaustive-deps -- the roles in use when the list is read
  const refetch = async (id: number): Promise<boolean | null> => {
    try {
      const d = await rest<PageListReply>(cfg, "GET", `page-list?group=every&ids[]=${id}`);
      const fresh = d.rows.find((r) => r.id === id);
      if (fresh) setRows((rs) => rs.map((r) => (r.id === id ? drawerRow({ ...r, ...fresh, op_conf: null, op_wrong: "", op_wrong_note: "" }) : r)));
      return null; // the brand's lists are read again on the next load
    } catch { return null; }
  };
  const { busy, reset, cells, act, state } = usePageRows(cfg, refetch);
  useEffect(() => { void loadProducts(group, page); setFound(null); reset(); }, [group, page, loadProducts]); // eslint-disable-line react-hooks/exhaustive-deps

  const save = async (key: string, body: Record<string, unknown>, done: string) => {
    setSaving(key);
    setNotice(null);
    try {
      const d = await rest<{ brand: Brand }>(cfg, "POST", `brand-sites/${brand.id}`, body);
      onBrand(d.brand);
      setNotice({ text: done, kind: "ok" });
      return true;
    } catch (e) { setNotice({ text: msg(e), kind: "error" }); return false; } finally { setSaving(null); }
  };

  /** A brand-wide change (POST /brand-sites/{id}/{path}): the brand comes back, and the products are read again. */
  const brandAction = async (key: string, path: string, body: Record<string, unknown>, done: (d: { done?: number; left?: number; marked?: number }) => string, confirm = false) => {
    if (confirm && armed !== key) { setArmed(key); return; }
    setArmed(null);
    setSaving(key);
    setNotice(null);
    try {
      const d = await rest<{ brand: Brand; done?: number; left?: number; marked?: number }>(cfg, "POST", `brand-sites/${brand.id}${path}`, body);
      onBrand(d.brand);
      setNotice({ text: done(d), kind: "ok" });
      void loadProducts(group, page);
    } catch (e) { setNotice({ text: msg(e), kind: "error" }); } finally { setSaving(null); }
  };

  const find = async () => {
    const hosts = makerHosts(brand);
    const ids = rows.map((r) => r.id);
    if (!hosts.length || !ids.length) return;
    setFound(null);
    setNotice(null);
    const replies: SitePages[] = [];
    try {
      for (const host of hosts) {
        const qs = new URLSearchParams({ host, list: "0" });
        for (const id of ids) qs.append("ids[]", String(id));
        for (let i = 0; i < POLL.tries; i++) {
          setFinding(`Reading ${host}'s product list…`);
          const d = await rest<SitePages>(cfg, "GET", `brand-sites/${brand.id}/pages?${qs.toString()}`);
          if (!d.pending) { replies.push(d); break; }
          setFinding(`Reading ${host}'s product list (page ${d.reading?.page ?? 1})…`);
          await sleep(POLL.ms);
        }
      }
      const matches = bestMatches(replies);
      const ticked = rows.filter((r) => ticks(r, matches.get(r.id))).map((r) => r.id);
      setFound({ matches, ticked });
      setNotice({ text: `Looked through ${hosts.join(", ")}: ${ticked.length} of ${rows.length} products have a sure match, ticked. Check the rest: tick a match, set the page in its row, or say the maker doesn't publish it.`, kind: "ok" });
    } catch (e) { setNotice({ text: msg(e), kind: "error" }); } finally { setFinding(null); }
  };

  const saveFound = async () => {
    if (!found?.ticked.length) return;
    setSaving("found");
    try {
      const items = found.ticked.map((id) => ({ id, url: found.matches.get(id)!.url }));
      const d = await rest<{ done: number[]; errors: Record<string, string> }>(cfg, "POST", "urls/set-many", { items });
      const errs = Object.keys(d.errors ?? {}).length;
      setNotice({ text: `${d.done.length} page${d.done.length === 1 ? "" : "s"} saved and being read.${errs ? ` ${errs} not saved: ${Object.values(d.errors)[0]}` : ""}`, kind: errs && !d.done.length ? "error" : "ok" });
      setFound(null);
      void loadProducts(group, page);
    } catch (e) { setNotice({ text: msg(e), kind: "error" }); } finally { setSaving(null); }
  };

  /** A batch's ticked proposals used or turned down together (POST /urls/decide; a turn-down asks again first). */
  const decideMany = async (b: Batch, action: "approve" | "reject") => {
    const ids = b.rows.filter((r) => ticked.has(r.id) && !state[r.id]?.gone).map((r) => r.id);
    if (!ids.length) return;
    const key = `${action}:${b.key}`;
    if (action === "reject" && armed !== key) { setArmed(key); return; }
    setArmed(null);
    setSaving(key);
    setNotice(null);
    try {
      const d = await rest<{ done: number; skipped: number[] }>(cfg, "POST", "urls/decide", { action, ids });
      setNotice({ text: action === "approve" ? bulkUseText(d) : `${d.done} page${d.done === 1 ? "" : "s"} turned down: not proposed again.`, kind: "ok" });
      void loadProducts(group, page);
    } catch (e) { setNotice({ text: msg(e), kind: "error" }); } finally { setSaving(null); }
  };
  const flip = <T,>(set: Set<T>, v: T, on: boolean): Set<T> => { const n = new Set(set); if (on) n.add(v); else n.delete(v); return n; };

  const hasMaker = brand.sites.some(isMaker) || !!brand.website;
  const pages = reply ? Math.max(1, Math.ceil(reply.total / (reply.per_page || 50))) : 1;
  const makers = makerHosts(brand);
  const waits = toDoText(brand);
  return (
    <div className="mmi-wb-split-item" id="mmi-wb-bs-drawer" ref={ref} data-brand={brand.id}>
      <div className="mmi-wb-split-head">
        <div className="mmi-wb-split-title">
          <h4>{brand.logo && <img src={brand.logo} alt="" />} {brand.name}{brand.gone && <span className="mmi-badge inline">Set aside</span>}</h4>
          <p className="description">
            {plural(brand.products, "product")} · {makers.length ? <>the maker's site{makers.length === 1 ? "" : "s"}: {makers.map((h, i) => <span key={h}>{i ? ", " : ""}<a href={`https://${h}`} target="_blank" rel="noreferrer">{h}</a></span>)}</> : "no maker site saved"}
            {brand.url && <> · <a href={brand.url} target="_blank" rel="noreferrer">its brand page ↗</a></>}
          </p>
        </div>
        <div className="mmi-wb-split-actions">
          {!hasMaker && (
            <button type="button" className="button" id="mmi-wb-bs-gone" disabled={saving !== null} title="Its products then count as not published by the maker, and it leaves the brands that need work"
              onClick={() => void save("gone", { gone: !brand.gone }, brand.gone ? "Brought back: it needs a maker site again." : "Set aside: its maker has no site.")}>
              {brand.gone ? "Bring it back" : "Set aside: the maker has no site"}
            </button>
          )}
        </div>
      </div>

      {/* What waits on the whole brand: a line each, with its fix, whichever tab is open. */}
      <div className="mmi-wb-split-lines">
        {!hasMaker && !brand.gone && <p className="mmi-wb-split-why is-blocked" id="mmi-wb-bs-why">No maker site is saved, so its products' pages can't be found. Add the maker's site under Sites, or set the brand aside when the maker has none.</p>}
        {hasMaker && waits && <p className="mmi-wb-split-why is-info" id="mmi-wb-bs-waits">{waits}: decide them under Pages, or let Find their pages match them to the maker's own list.</p>}
        {(brand.moves ?? []).map((mv) => (
          <div key={`${mv.from} ${mv.to}`} className="notice notice-warning inline mmi-wb-bs-move" data-move={mv.from}>
            <p>{mv.n} products' pages on {mv.from} now redirect to {mv.to}.{" "}
              <button type="button" className="button button-small" disabled={saving !== null} title={`Each takes its new address on ${mv.to}, approved and read; ${mv.to} is saved as the maker's site`}
                onClick={() => void brandAction(`move:${mv.from}`, "/move", { from: mv.from, to: mv.to }, (d) => `${d.done ?? 0} page${d.done === 1 ? "" : "s"} moved to ${mv.to}; reading them.`)}>Move them to {mv.to}</button>
            </p>
          </div>
        ))}
        {brand.bundles_none > 0 && (
          <div className="notice notice-info inline mmi-wb-bs-bundles">
            <p>{brand.bundles_none} bundle{brand.bundles_none === 1 ? " has" : "s have"} no page on the maker's site. A bundle sold here takes its content from its products.{" "}
              <button type="button" id="mmi-wb-bs-bundles-none" className={`button button-small mmi-wb-mp-danger${armed === "bundles" ? " is-armed" : ""}`} disabled={saving !== null}
                onClick={() => void brandAction("bundles", "/bundles-none", {}, (d) => `${d.done ?? 0} bundle${d.done === 1 ? "" : "s"} marked: the maker doesn't publish them.`, true)}>
                {armed === "bundles" ? "Click again to mark them" : "Maker doesn't publish them"}
              </button>
            </p>
          </div>
        )}
      </div>
      {notice && <div className={`notice notice-${notice.kind === "error" ? "error" : "success"} inline`} role="status" id="mmi-wb-bs-notice"><p>{notice.text}</p></div>}

      <SplitTabs id="mmi-wb-bs" label={`${brand.name}: what to look at`} active={tab} onTab={onTab} tabs={[
        { key: "products", label: "Pages", count: toDo(brand) ? `${toDo(brand)} to do` : plural(brand.products, "product"), dot: toDo(brand) > 0 },
        { key: "map", label: "Map", count: map.count, dot: map.dot },
        { key: "fill", label: "Fill", count: fill.count, dot: fill.dot },
        // No dot for a site whose role isn't set: roles aren't a task (owner 2026-10-23). Only a missing maker site or moved pages.
        { key: "sites", label: "Sites", count: String(brand.sites.length), dot: !hasMaker || (brand.moves ?? []).length > 0 },
      ] satisfies SplitTab<BrandTab>[]} />

      <div className="mmi-wb-split-panel" id="mmi-wb-bs-tabpanel" role="tabpanel" aria-labelledby={`mmi-wb-bs-tab-${tab}`}>
        {tab === "map" && map.body}
        {tab === "fill" && fill.body}

        {tab === "sites" && (<>
        <p className="description">Every site its products link to, and what each is. Product data is read only from the maker's own sites and its partners'.</p>
        <div className="mmi-table-scroll-wrapper">
        <table className="widefat striped mmi-wb-bs-sites" id="mmi-wb-bs-sites">
          <thead><tr><th>Site</th><th>Its products</th><th>What it is</th></tr></thead>
          <tbody>
            {!brand.sites.length && <tr><td colSpan={3} className="mmi-wb-empty-cell">No site yet: add the maker's site below.</td></tr>}
            {brand.sites.map((s) => (
              <tr key={s.host} data-host={s.host}>
                <td><a href={`https://${s.host}`} target="_blank" rel="noreferrer">{s.host}</a>{s.why && <p className="description">{s.why}</p>}</td>
                <td>
                  {plural(s.n, "product")} link{s.n === 1 ? "s" : ""} there{s.open ? <span className="description"> · {s.open} proposed</span> : null}
                  <div className="description">{s.read} read{s.failed ? <span className="mmi-wb-mp-warn"> · {s.failed} couldn't be read</span> : null}{s.unread ? <> · {s.unread} not yet</> : null}</div>
                </td>
                <td>
                  <select aria-label={`What ${s.host} is`} value={s.role || "unknown"} disabled={saving !== null}
                    onChange={(e) => void save(`role:${s.host}`, rolesBody(s.host, e.target.value as SiteRole), `${s.host}: ${roleLabel(e.target.value as SiteRole)}.`)}>
                    {ROLES.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
                  </select>
                  {/* The site's fixes sit under what it is, as a product's do under its page. */}
                  <div className="mmi-wb-bs-row-actions mmi-wb-bs-site-actions">
                  {!isMaker(s) && s.better > 0 && (
                    <button type="button" className="button button-small button-primary" disabled={saving !== null} data-site-action="to-own"
                      title={`The ${s.better} products here with a page proposed on the maker's own site take it, approved and read`}
                      onClick={() => void brandAction(`own:${s.host}`, "/to-own", { from: s.host }, (d) => `${d.done ?? 0} product${d.done === 1 ? "" : "s"} moved to the maker's own pages; reading them.`)}>
                      Use the maker's pages ({s.better})
                    </button>
                  )}
                  {!isMaker(s) && (s.n === 0 || s.bundles > 0) && (
                    <button type="button" className={`button button-small mmi-wb-mp-danger${armed === `rm:${s.host}` ? " is-armed" : ""}`} disabled={saving !== null} data-site-action="remove"
                      title={s.bundles ? "Its bundles are marked as having no maker page first" : "Take it off this brand's list"}
                      onClick={() => void brandAction(`rm:${s.host}`, "", { remove: s.host, bundles_none: s.bundles > 0 },
                        (d) => (d.left ? `${d.marked ?? 0} bundles marked; ${d.left} products still link to ${s.host}: give them other pages first.` : `${s.host} taken off the list.`), true)}>
                      {armed === `rm:${s.host}` ? "Click again to remove" : "Remove"}
                    </button>
                  )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        </div>
        <div className="mmi-wb-bs-forms">
          <form onSubmit={(e) => {
            e.preventDefault();
            const h = hostOf(addHost);
            if (!h) { setNotice({ text: "Type the site like example.com.", kind: "error" }); return; }
            void save("add", rolesBody(h, addRole), `${h} added: ${roleLabel(addRole)}.`).then((ok) => { if (ok) setAddHost(""); });
          }}>
            <label htmlFor="mmi-wb-bs-add">Add a site</label>
            <input id="mmi-wb-bs-add" type="text" placeholder="example.com" value={addHost} onChange={(e) => setAddHost(e.target.value)} />
            <select aria-label="What the added site is" value={addRole} onChange={(e) => setAddRole(e.target.value as SiteRole)}>
              {ROLES.filter((r) => r.value !== "unknown").map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
            </select>
            <button type="submit" className="button button-small" disabled={saving !== null}>Add</button>
          </form>
          <form onSubmit={(e) => { e.preventDefault(); void save("website", { website }, website.trim() ? "Brand website saved." : "Brand website cleared."); }}>
            <label htmlFor="mmi-wb-bs-website">Brand website</label>
            <input id="mmi-wb-bs-website" type="text" placeholder="https://" value={website} onChange={(e) => setWebsite(e.target.value)} />
            <button type="submit" className="button button-small" disabled={saving !== null || website === brand.website}>Save</button>
          </form>
        </div>
        </>)}

        {tab === "products" && (<>
        <div className="mmi-filter-pills mmi-wb-pr-views" role="group" aria-label="Products">
          {PRODUCT_GROUPS.map((g) => (
            <button key={g.value} type="button" className={`mmi-pill${group === g.value ? " active" : ""}`} aria-pressed={group === g.value} data-pgroup={g.value} title={g.hint || undefined}
              onClick={() => { setGroup(g.value); setPage(1); }}>
              {g.label}{group === g.value && reply ? <span className="mmi-pill-count">{reply.total}</span> : null}
            </button>
          ))}
        </div>
        <div className="mmi-wb-filters mmi-wb-pr-filters">
          <button type="button" className="button button-small button-primary" id="mmi-wb-bs-find" disabled={!makerHosts(brand).length || !rows.length || finding !== null || busy !== null}
            title={makerHosts(brand).length ? "Read the maker's own product list and match these products to its pages" : "Save the maker's site first"} onClick={() => void find()}>
            {finding ? <span className="mmi-loading" aria-hidden="true" /> : <Dashicon name="search" />} Find their pages
          </button>
          {finding && <span className="description" role="status">{finding}</span>}
        </div>
        <div className="mmi-wb-bt-list" id="mmi-wb-bs-products">
          {!reply && <p className="mmi-wb-empty-cell">Loading products…</p>}
          {reply && !rows.length && <p className="mmi-wb-empty-cell">Nothing here.</p>}
          {found && (
            <FoundBatch rows={rows.filter((r) => found.matches.has(r.id))} found={found} saving={saving} gone={(id) => !!state[id]?.gone}
              onTick={(id, on) => setFound((f) => (f ? { ...f, ticked: on ? [...f.ticked, id] : f.ticked.filter((x) => x !== id) } : f))}
              onSave={() => void saveFound()} onClear={() => setFound(null)} />
          )}
          {batchesOf(rows, placed).map((b) => {
            const closed = shut.has(b.key);
            const live = b.rows.filter((r) => !state[r.id]?.gone);
            const t = live.filter((r) => ticked.has(r.id)).length;
            const why = b.kind === "why";
            return (
              <section key={b.key} className={`mmi-wb-bt is-${b.kind}`} data-batch={b.key} aria-label={b.label}>
                <div className="mmi-wb-bt-head">
                  {why
                    ? <input type="checkbox" checked={t > 0 && t === live.length} ref={(el) => { if (el) el.indeterminate = t > 0 && t < live.length; }} aria-label={`Tick every page in "${b.label}"`}
                      onChange={(e) => setTicked((set) => live.reduce((n, r) => flip(n, r.id, e.target.checked), set))} />
                    : <span aria-hidden="true" />}
                  <span className="mmi-wb-bt-count">{b.rows.length}</span>
                  <div className="mmi-wb-bt-why">
                    <strong>{b.label}</strong>
                    {why && (
                      <span className="description">
                        {b.from.map((h) => h || "no page").join(", ")} → {b.hosts.join(", ")} · {b.sure === b.rows.length ? `all ${b.sure} sure (${SURE}+)` : b.sure ? `${b.sure} sure (${SURE}+), ${b.rows.length - b.sure} to look at` : `none sure: look, then tick`}
                        {b.adds.length ? ` · would add ${b.adds.join(", ")}` : ""}
                      </span>
                    )}
                  </div>
                  <span className="mmi-wb-bt-acts">
                    {why && (
                      <>
                        <button type="button" className={`button button-small mmi-wb-mp-danger${armed === `reject:${b.key}` ? " is-armed" : ""}`} data-batch-action="reject" disabled={!t || saving !== null || busy !== null}
                          title="Turn down the ticked pages: they aren't proposed again" onClick={() => void decideMany(b, "reject")}>
                          {armed === `reject:${b.key}` ? `Click again: not these ${t}` : `Not these ${t}`}
                        </button>
                        <button type="button" className="button button-small button-primary" data-batch-action="use" disabled={!t || saving !== null || busy !== null}
                          title="Make the ticked pages the products' maker pages and read them" onClick={() => void decideMany(b, "approve")}>
                          Use the {t} ticked
                        </button>
                      </>
                    )}
                    <button type="button" className="button button-small" aria-expanded={!closed} data-batch-action="toggle" onClick={() => setShut((set) => flip(set, b.key, !closed))}>
                      {closed ? `Show ${b.rows.length}` : "Hide"}
                    </button>
                  </span>
                </div>
                {!closed && (
                  <ul className="mmi-wb-bt-rows">
                    {b.rows.map((r) => {
                      const st = state[r.id];
                      const open = opened.has(r.id);
                      const c = open ? cells(r) : null;
                      return (
                        <li key={r.id} data-id={r.id} className={`mmi-wb-bt-row${st?.gone ? " is-gone" : ""}${open ? " is-open" : ""}`}>
                          <div className="mmi-wb-bt-line">
                            {why
                              ? <input type="checkbox" checked={ticked.has(r.id)} disabled={!!st?.gone} aria-label={`Use the proposed page for ${r.title}`} onChange={(e) => setTicked((set) => flip(set, r.id, e.target.checked))} />
                              : <span aria-hidden="true" />}
                            {r.image ? <img src={r.image} alt="" loading="lazy" /> : <span className="mmi-wb-bt-noimg" aria-hidden="true" />}
                            <span className="mmi-wb-bt-product">
                              <a href={r.view_url} target="_blank" rel="noreferrer" title={r.title}>{r.title}</a>
                              <span className="description"><a href={r.edit_url} target="_blank" rel="noreferrer">{r.id}</a>{r.sku ? ` · ${r.sku}` : ""}</span>
                            </span>
                            <span className="mmi-wb-bt-move" title={why ? `${r.current || "No page"} → ${r.proposed}` : r.current || undefined}>
                              {why
                                ? <>→ <a href={r.proposed} target="_blank" rel="noreferrer">{shortUrl(r.proposed)}</a></>
                                : r.current ? <a href={r.current} target="_blank" rel="noreferrer">{shortUrl(r.current)}</a>
                                : <span className="description">{r.fetch_status === "no_page" ? "The maker doesn't publish it" : "No maker page yet"}</span>}
                            </span>
                            <span className="mmi-wb-bt-what">
                              {why
                                ? <><span className={`mmi-badge ${r.score >= SURE ? "success" : "warning"} inline`} title="How sure the finder is that this page is the product's">{r.score}</span> <span className="mmi-wb-bt-title" title={r.page_title}>{r.page_title}</span></>
                                : r.current ? <span className={`mmi-badge ${r.result_badge} inline`}>{r.result_label}</span> : null}
                            </span>
                            <span className="mmi-wb-bt-acts">
                              {why && !st?.gone && (
                                <>
                                  <button type="button" className="button button-small button-primary" data-action="use" disabled={busy !== null} title="Make the proposed page its maker page and read it now" onClick={() => void act(r, "use")}>Use</button>
                                  <button type="button" className="button button-small" data-action="reject" disabled={busy !== null} title="Keep the product as it is; this page is never proposed again" onClick={() => void act(r, "reject")}>Not this</button>
                                </>
                              )}
                              <button type="button" className="button button-small mmi-wb-bt-more" aria-expanded={open} title="Its pages in full, with every decision it takes" onClick={() => setOpened((set) => flip(set, r.id, !open))}>{open ? "Less" : "More"}</button>
                            </span>
                          </div>
                          {!open && st?.notice && <div className={`notice notice-${st.notice.kind === "error" ? "error" : "success"} inline`} role="status"><p>{st.notice.text}{st.gone ? " It leaves this list." : ""}</p></div>}
                          {c && <div className="mmi-wb-bt-detail mmi-wb-mp-pages">{c.page}{c.actions ? <div className="mmi-wb-bs-row-actions">{c.actions}</div> : null}</div>}
                        </li>
                      );
                    })}
                  </ul>
                )}
              </section>
            );
          })}
        </div>
        {pages > 1 && (
          <div className="mmi-wb-pr-pager" id="mmi-wb-bs-pager">
            <button type="button" className="button button-small" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>‹ Previous</button>
            <span>Page {page} of {pages}</span>
            <button type="button" className="button button-small" disabled={page >= pages} onClick={() => setPage((p) => p + 1)}>Next ›</button>
          </div>
        )}
        </>)}
      </div>
    </div>
  );
}

/** "Find their pages": the matches on the maker sites' own lists, as a batch of their own, the sure ones ticked. */
function FoundBatch({ rows, found, saving, gone, onTick, onSave, onClear }: {
  rows: PageRow[]; found: { matches: Map<number, Match>; ticked: number[] }; saving: string | null; gone: (id: number) => boolean;
  onTick: (id: number, on: boolean) => void; onSave: () => void; onClear: () => void;
}) {
  const t = found.ticked.length;
  return (
    <section className="mmi-wb-bt is-found" data-batch="found" aria-label="Found on the maker's own list">
      <div className="mmi-wb-bt-head">
        <span aria-hidden="true" />
        <span className="mmi-wb-bt-count">{rows.length}</span>
        <div className="mmi-wb-bt-why"><strong>Found on the maker's own product list</strong><span className="description">{t} ticked: the sure matches ({SURE}+) that would change the page</span></div>
        <span className="mmi-wb-bt-acts">
          <button type="button" className="button button-small" onClick={onClear}>Clear the matches</button>
          <button type="button" className="button button-small button-primary" id="mmi-wb-bs-save-found" disabled={!t || saving !== null} onClick={onSave}>Save {t} ticked page{t === 1 ? "" : "s"}</button>
        </span>
      </div>
      <ul className="mmi-wb-bt-rows">
        {rows.map((r) => {
          const m = found.matches.get(r.id)!;
          return (
            <li key={r.id} data-id={r.id} className={`mmi-wb-bt-row mmi-wb-bs-found${gone(r.id) ? " is-gone" : ""}`}>
              <div className="mmi-wb-bt-line">
                <input type="checkbox" checked={found.ticked.includes(r.id)} aria-label={`Use the found page for ${r.title}`} onChange={(e) => onTick(r.id, e.target.checked)} />
                {r.image ? <img src={r.image} alt="" loading="lazy" /> : <span className="mmi-wb-bt-noimg" aria-hidden="true" />}
                <span className="mmi-wb-bt-product"><a href={r.view_url} target="_blank" rel="noreferrer" title={r.title}>{r.title}</a><span className="description">{r.id}{r.sku ? ` · ${r.sku}` : ""}</span></span>
                <span className="mmi-wb-bt-move" title={m.url}><a href={m.url} target="_blank" rel="noreferrer">{m.name || shortUrl(m.url)}</a></span>
                <span className="mmi-wb-bt-what"><span className={`mmi-badge ${m.score >= SURE ? "success" : "warning"} inline`}>{m.score}</span>{m.why ? <span className="mmi-wb-bt-title description"> {m.why}</span> : null}</span>
                <span className="mmi-wb-bt-acts" />
              </div>
            </li>
          );
        })}
      </ul>
    </section>
  );
}
