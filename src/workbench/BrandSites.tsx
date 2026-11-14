/**
 * An open brand in Makers (Makers.tsx; Product Data's BrandSites): where its maker publishes its product pages, and
 * its products matched to them.
 *
 * - A header (the brand, its maker sites, Set aside), lines for what waits on the whole brand (pages that moved,
 *   bundles with no maker page, what its products wait for), then the brand's tabs in the order the work goes.
 * - Pages: decided row by row as in Maker pages, and "Find their pages", which reads every maker site's own
 *   product list and ticks the sure matches, saved together.
 * - Map: what Makers hands in (the maker site's map, SiteMaps.tsx's SitePanel).
 * - Sites: each switched to what it is (the maker's own, a partner, a reseller, a store, another brand's), a site
 *   added, the Brand website.
 */
import { useCallback, useEffect, useRef, useState, type ReactNode } from "react";
import {
  bestMatches, drawerRow, hostOf, isMaker, makerHosts, PRODUCT_GROUPS, ROLES, rolesBody, roleLabel, ticks, toDo,
  toDoText, type Brand, type Match, type ProductGroup, type ProductsReply, type SitePages, type SiteRole,
} from "./brandsApi";
import { msg, usePageRows, type Notice } from "./pageRows";
import type { PageListReply, PageRow } from "./pagesApi";
import { rest, type TitleRulesConfig } from "./titleRulesApi";
import { Dashicon, SplitTabs, type SplitTab } from "./ui";

const sleep = (ms: number) => new Promise((r) => window.setTimeout(r, ms));
const POLL = { ms: 3000, tries: 40 };

export type BrandTab = "products" | "map" | "sites";
const plural = (n: number, one: string, many = `${one}s`) => `${n.toLocaleString("en-US")} ${n === 1 ? one : many}`;

/** map: the Map tab's content and its tab's count and dot (Makers draws the maker site's map). */
export function BrandDrawer({ cfg, brand, onBrand, tab, onTab, map }: {
  cfg: TitleRulesConfig; brand: Brand; onBrand: (b: Brand) => void; tab: BrandTab; onTab: (t: BrandTab) => void;
  map: { body: ReactNode; count: string; dot: boolean };
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
  const seq = useRef(0);
  const ref = useRef<HTMLDivElement>(null);
  // The brand opens beside the list; when the page was scrolled past its top, bring its top into view.
  useEffect(() => { if (ref.current && ref.current.getBoundingClientRect().top < 0) ref.current.scrollIntoView({ block: "start" }); }, []);

  const loadProducts = useCallback(async (g: ProductGroup, p: number) => {
    const mine = ++seq.current;
    try {
      const d = await rest<ProductsReply>(cfg, "GET", `brand-sites/${brand.id}/products?group=${g}&page=${p}`);
      if (mine === seq.current) { setReply(d); setRows(d.rows.map(drawerRow)); }
    } catch (e) { if (mine === seq.current) { setRows([]); setNotice({ text: msg(e), kind: "error" }); } }
  }, [cfg, brand.id]);
  const refetch = async (id: number): Promise<boolean | null> => {
    try {
      const d = await rest<PageListReply>(cfg, "GET", `page-list?group=every&ids[]=${id}`);
      const fresh = d.rows.find((r) => r.id === id);
      if (fresh) setRows((rs) => rs.map((r) => (r.id === id ? drawerRow({ ...r, ...fresh, op_conf: null, op_wrong: "", op_wrong_note: "" }) : r)));
      return null; // the brand's lists are read again on the next load
    } catch { return null; }
  };
  const { busy, reset, cells } = usePageRows(cfg, refetch);
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
        // No dot for a site whose role isn't set: roles aren't a task (owner 2026-10-23). Only a missing maker site or moved pages.
        { key: "sites", label: "Sites", count: String(brand.sites.length), dot: !hasMaker || (brand.moves ?? []).length > 0 },
      ] satisfies SplitTab<BrandTab>[]} />

      <div className="mmi-wb-split-panel" id="mmi-wb-bs-tabpanel" role="tabpanel" aria-labelledby={`mmi-wb-bs-tab-${tab}`}>
        {tab === "map" && map.body}

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
          {found && (
            <span className="mmi-wb-pr-bulk">
              <button type="button" className="button button-small button-primary" id="mmi-wb-bs-save-found" disabled={!found.ticked.length || saving !== null} onClick={() => void saveFound()}>Save {found.ticked.length} ticked page{found.ticked.length === 1 ? "" : "s"}</button>
              <button type="button" className="button button-small" onClick={() => setFound(null)}>Clear the matches</button>
            </span>
          )}
        </div>
        <div className="mmi-table-scroll-wrapper">
          <table className="wp-list-table widefat striped mmi-wb-pr-table" id="mmi-wb-bs-products">
            <thead><tr><th className="mmi-wb-col-id">ID</th><th>Product</th><th>Maker page</th>{found && <th>Found</th>}</tr></thead>
            <tbody>
              {!reply && <tr><td colSpan={4} className="mmi-wb-empty-cell">Loading products…</td></tr>}
              {reply && !rows.length && <tr><td colSpan={4} className="mmi-wb-empty-cell">Nothing here.</td></tr>}
              {rows.map((row) => {
                const c = cells(row);
                const m = found?.matches.get(row.id);
                return (
                  <tr key={row.id} data-id={row.id}>
                    <td className="mmi-wb-col-id"><a href={row.edit_url} target="_blank" rel="noreferrer">{row.id}</a></td>
                    <td className="mmi-wb-mp-product">
                      {row.image && <img src={row.image} alt="" loading="lazy" />}
                      <div><a href={row.view_url} target="_blank" rel="noreferrer">{row.title}</a>{row.sku && <p className="description">SKU {row.sku}</p>}</div>
                    </td>
                    {/* The row's decisions sit under its page: beside the list, a column of their own ran past the panel. */}
                    <td className="mmi-wb-mp-pages">{c.page}{c.actions ? <div className="mmi-wb-bs-row-actions">{c.actions}</div> : null}</td>
                    {found && (
                      <td className="mmi-wb-bs-found">
                        {m ? (
                          <label>
                            <input type="checkbox" checked={found.ticked.includes(row.id)} aria-label={`Use the found page for ${row.title}`}
                              onChange={(e) => setFound((f) => (f ? { ...f, ticked: e.target.checked ? [...f.ticked, row.id] : f.ticked.filter((x) => x !== row.id) } : f))} />
                            <a href={m.url} target="_blank" rel="noreferrer">{m.name || m.url}</a>
                            <span className={`mmi-badge ${m.score >= 70 ? "success" : "warning"} inline`}>{m.score}%</span>
                            {m.why && <span className="description"> {m.why}</span>}
                          </label>
                        ) : <span className="description">No page names it</span>}
                      </td>
                    )}
                  </tr>
                );
              })}
            </tbody>
          </table>
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
