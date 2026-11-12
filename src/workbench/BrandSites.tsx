/**
 * Brands & sites in the Workbench (Product Data's BrandSites): where each brand's maker publishes its product
 * pages, and its products matched to them. Open a brand: (1) its sites, each switched to what it is (the maker's
 * own, a partner, a reseller, a store, another brand's), the Brand website, or the brand set aside when its maker
 * has no site any more; (2) its products, decided row by row as in Maker pages, and "Find their pages", which
 * reads every maker site's own product list and ticks the sure matches, saved together.
 */
import { useCallback, useEffect, useRef, useState } from "react";
import { landing } from "./landing";
import {
  bestMatches, BRAND_VIEWS, brandsIn, drawerRow, brandViewCount, hostOf, isMaker, makerHosts, PRODUCT_GROUPS, ROLES, rolesBody, roleLabel, ticks, toDo,
  type Brand, type BrandView, type Match, type ProductGroup, type ProductsReply, type SitePages, type SiteRole,
} from "./brandsApi";
import { msg, usePageRows, type Notice } from "./pageRows";
import type { PageListReply, PageRow } from "./pagesApi";
import { rest, type TitleRulesConfig } from "./titleRulesApi";
import { Dashicon, Section, useColumnResize } from "./ui";

const sleep = (ms: number) => new Promise((r) => window.setTimeout(r, ms));
const POLL = { ms: 3000, tries: 40 };

export function BrandSites({ cfg }: { cfg: TitleRulesConfig }) {
  const [brands, setBrands] = useState<Brand[] | null>(null);
  const [view, setView] = useState<BrandView>("work");
  const [q, setQ] = useState("");
  const [open, setOpen] = useState<number | null>(() => landing().brand || null); // a link can open a brand (wb_brand)
  const [notice, setNotice] = useState<Notice | null>(null);
  useEffect(() => {
    rest<{ brands: Brand[] }>(cfg, "GET", "brand-sites").then((d) => setBrands(d.brands)).catch((e) => { setBrands([]); setNotice({ text: msg(e), kind: "error" }); });
  }, [cfg]);
  const list = brands ? brandsIn(brands, view, q) : [];
  const tableRef = useRef<HTMLTableElement>(null);
  useColumnResize(tableRef, list.length > 0);
  const brand = brands?.find((b) => b.id === open) ?? null;
  const replace = (b: Brand) => setBrands((bs) => (bs ? bs.map((x) => (x.id === b.id ? b : x)) : bs));

  return (
    <Section id="mmi-wb-brand-sites" icon="store" title="Brands & sites"
      desc="Where each brand's maker publishes its product pages. Open a brand to say which of its sites are the maker's, and to match its products to the maker's own pages">
      {notice && <div className={`notice notice-${notice.kind === "error" ? "error" : "success"} inline`} role="status"><p>{notice.text}</p></div>}
      <div className="mmi-filter-pills mmi-wb-pr-views" role="group" aria-label="Brands">
        {BRAND_VIEWS.map((v) => (
          <button key={v.value} type="button" className={`mmi-pill${view === v.value ? " active" : ""}`} aria-pressed={view === v.value} data-bview={v.value} title={v.hint || undefined} onClick={() => setView(v.value)}>
            {v.label} <span className="mmi-pill-count">{brands ? brandViewCount(brands, v.value) : "…"}</span>
          </button>
        ))}
      </div>
      <div className="mmi-wb-filters mmi-wb-pr-filters">
        <input type="search" id="mmi-wb-bs-q" aria-label="Find brands" placeholder="Brand or site" value={q} onChange={(e) => setQ(e.target.value)} />
        <span className="mmi-wb-count" id="mmi-wb-bs-count">{brands ? `${list.length} brand${list.length === 1 ? "" : "s"}` : ""}</span>
      </div>
      <div className="mmi-table-scroll-wrapper mmi-wb-bs-list">
        <table ref={tableRef} className="wp-list-table widefat striped mmi-wb-pr-table" id="mmi-wb-bs-table">
          <thead><tr><th data-resize-col="brand">Brand</th><th data-resize-col="sites">Maker sites</th><th data-resize-col="products">Products</th><th data-resize-col="todo">To do</th></tr></thead>
          <tbody id="mmi-wb-bs-rows">
            {!brands && <tr><td colSpan={4} className="mmi-wb-empty-cell">Loading brands…</td></tr>}
            {brands && !list.length && <tr><td colSpan={4} className="mmi-wb-empty-cell">Nothing here.</td></tr>}
            {list.map((b) => {
              const makers = b.sites.filter(isMaker);
              return (
                <tr key={b.id} data-brand={b.id} className={open === b.id ? "is-open" : undefined}>
                  <td className="mmi-wb-bs-brand">
                    {b.logo && <img src={b.logo} alt="" loading="lazy" />}
                    <button type="button" className="button-link" aria-expanded={open === b.id} onClick={() => setOpen(open === b.id ? null : b.id)}>{b.name}</button>
                    {b.gone && <span className="mmi-badge inline">set aside</span>}
                  </td>
                  <td>{makers.length ? makers.map((s) => <span key={s.host} className="mmi-wb-bs-host">{s.host}</span>) : <span className="description">{b.gone ? "The maker has no site" : "None saved"}</span>}</td>
                  <td>{b.products}</td>
                  <td>{toDo(b) ? (
                    <span className="mmi-wb-bs-todo">
                      {b.open > 0 && <span className="mmi-badge info inline">{b.open} proposed</span>}
                      {b.failed_open > 0 && <span className="mmi-badge warning inline">{b.failed_open} gone or moved</span>}
                      {b.no_url > 0 && <span className="mmi-badge inline">{b.no_url} no page</span>}
                    </span>
                  ) : <span className="description">Nothing</span>}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      {brand && <BrandDrawer key={brand.id} cfg={cfg} brand={brand} onBrand={replace} onClose={() => setOpen(null)} />}
    </Section>
  );
}

function BrandDrawer({ cfg, brand, onBrand, onClose }: { cfg: TitleRulesConfig; brand: Brand; onBrand: (b: Brand) => void; onClose: () => void }) {
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
  useEffect(() => { ref.current?.scrollIntoView({ block: "start", behavior: "smooth" }); }, []);

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
  return (
    <div className="mmi-wb-bs-drawer" id="mmi-wb-bs-drawer" ref={ref} data-brand={brand.id}>
      <div className="mmi-wb-bs-drawer-head">
        <h4>{brand.logo && <img src={brand.logo} alt="" />} {brand.name}</h4>
        <button type="button" className="button button-small" onClick={onClose}><Dashicon name="no-alt" /> Close</button>
      </div>
      {notice && <div className={`notice notice-${notice.kind === "error" ? "error" : "success"} inline`} role="status" id="mmi-wb-bs-notice"><p>{notice.text}</p></div>}

      <h5>1. Where the maker publishes</h5>
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
      <table className="widefat striped mmi-wb-bs-sites" id="mmi-wb-bs-sites">
        <thead><tr><th>Site</th><th>Products linking there</th><th>Pages read</th><th>What it is</th><th><span className="screen-reader-text">Actions</span></th></tr></thead>
        <tbody>
          {!brand.sites.length && <tr><td colSpan={5} className="mmi-wb-empty-cell">No site yet: add the maker's site below.</td></tr>}
          {brand.sites.map((s) => (
            <tr key={s.host} data-host={s.host}>
              <td><a href={`https://${s.host}`} target="_blank" rel="noreferrer">{s.host}</a>{s.why && <p className="description">{s.why}</p>}</td>
              <td>{s.n}{s.open ? <span className="description"> · {s.open} proposed</span> : null}</td>
              <td>{s.read} read{s.failed ? <span className="mmi-wb-mp-warn"> · {s.failed} couldn't be read</span> : null}{s.unread ? <span className="description"> · {s.unread} not yet</span> : null}</td>
              <td>
                <select aria-label={`What ${s.host} is`} value={s.role || "unknown"} disabled={saving !== null}
                  onChange={(e) => void save(`role:${s.host}`, rolesBody(s.host, e.target.value as SiteRole), `${s.host}: ${roleLabel(e.target.value as SiteRole)}.`)}>
                  {ROLES.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
                </select>
              </td>
              <td className="mmi-wb-bs-site-actions">
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
              </td>
            </tr>
          ))}
        </tbody>
      </table>
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
        {!hasMaker && (
          <button type="button" className="button button-small" id="mmi-wb-bs-gone" disabled={saving !== null} title="Its products then count as not published by the maker, and it leaves the brands that need work"
            onClick={() => void save("gone", { gone: !brand.gone }, brand.gone ? "Brought back: it needs a maker site again." : "Set aside: its maker has no site.")}>
            {brand.gone ? "Bring it back" : "Set aside: the maker has no site"}
          </button>
        )}
      </div>

      <h5>2. Its products</h5>
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
          <thead><tr><th className="mmi-wb-col-id">ID</th><th>Product</th><th>Maker page</th>{found && <th>Found</th>}<th><span className="screen-reader-text">Actions</span></th></tr></thead>
          <tbody>
            {!reply && <tr><td colSpan={5} className="mmi-wb-empty-cell">Loading products…</td></tr>}
            {reply && !rows.length && <tr><td colSpan={5} className="mmi-wb-empty-cell">Nothing here.</td></tr>}
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
                  <td className="mmi-wb-mp-pages">{c.page}</td>
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
                  <td className="mmi-wb-job-actions mmi-wb-mp-actions">{c.actions}</td>
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
    </div>
  );
}
