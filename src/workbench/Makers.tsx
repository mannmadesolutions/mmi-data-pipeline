/**
 * Makers in the Workbench: Brands & sites and Site maps as one entry (owner 2026-11-14, the "Makers workspace"
 * mockup, layout D). A maker is a brand and the sites it publishes on; the work runs in one direction, so it is one
 * place: a product's maker page is decided (Pages), the page's parts are mapped (Map), the map fills the product.
 *
 * - The list on the left stays in view (ui.tsx's split view); ↑ and ↓ move between rows. By brand, each brand shows
 *   its maker sites under it with their map's state. By site lists the sites instead: a site several brands share
 *   (ilio.com), and the maps no brand claims as its maker's site.
 * - An open brand (BrandSites.tsx's BrandDrawer) has Pages, Map, Fill and Sites, in the order the work goes. Map is its
 *   maker site's map and Fill that site's Fill (SiteMaps.tsx's SitePanel, focused on each), with a pill per site when it
 *   has several. Both say which other brands a shared site serves (a Fill writes their products too), and offer to
 *   switch a map off once next to none of the brand's products link to that site and no other brand uses it.
 * - An open site is its map, with the brands it is the maker's site of; a click opens that brand on the site's map.
 * - The address keeps the place: wb_brand (and wb_site on its Map tab), or wb_site alone when listed by site.
 */
import { useCallback, useEffect, useRef, useState } from "react";
import { BrandDrawer, type BrandStep, type BrandTab } from "./BrandSites";
import {
  BRAND_VIEWS, brandsIn, brandViewCount, isMaker, makerHosts, sortBrands, toDo, toDoText,
  type Brand, type BrandSort, type BrandView,
} from "./brandsApi";
import { landing } from "./landing";
import {
  gainOf, gainProducts, proposalHosts, SITE_VIEWS, siteViewCount, sitesIn, sortSites, STATUS,
  type MapsReply, type Proposal, type RailSort, type SiteRow, type SiteView,
} from "./mapsApi";
import { useSectionVisible } from "./nav";
import { msg, type Notice } from "./pageRows";
import { nextSort, SitePanel, type SiteTab, type Sort } from "./SiteMaps";
import { rest, type TitleRulesConfig } from "./titleRulesApi";
import { Dashicon, Section, SortTh, useAddressParam, useColumnResize, useListKeys } from "./ui";

type Mode = "brand" | "site";
const plural = (n: number, one: string, many = `${one}s`) => `${n.toLocaleString("en-US")} ${n === 1 ? one : many}`;
/** The brand lists the pills show; the rest are in the menu beside the search. */
const WORK_VIEWS: BrandView[] = ["work", "nomaker"];
const NO_RUNNER: MapsReply["runner"] = { running: false, waiting: 0, note: "", finished_at: "" };
/** A map's state in words and badge color; a site with no map row reads "No map". */
const mapState = (status: string): [string, string] => STATUS[status] ?? ["No map", ""];
/** A maker site this few of the brand's products link to (and no other brand's) gets "switch its map off". */
const IDLE_LINKS = 2;

export function Makers({ cfg }: { cfg: TitleRulesConfig }) {
  const l = landing();
  const [mode, setMode] = useState<Mode>(l.bySite ? "site" : "brand");
  const [brands, setBrands] = useState<Brand[] | null>(null);
  const [maps, setMaps] = useState<MapsReply | null>(null);
  const [proposals, setProposals] = useState<Proposal[]>([]);
  const [notice, setNotice] = useState<Notice | null>(null);
  // By brand: the open brand, its tab (kept while moving between brands) and the site its Map tab shows.
  const [brandId, setBrandId] = useState<number | null>(l.brand || null);
  const [bTab, setBTab] = useState<BrandTab>(l.brand && l.site ? "map" : "products");
  const [mapHost, setMapHost] = useState<string | null>(l.brand ? l.site || null : null);
  // By site: the open site. Both modes share the site's own tab.
  const [siteHost, setSiteHost] = useState<string | null>(l.bySite ? l.site || null : null);
  const [sTab, setSTab] = useState<SiteTab>("parts");
  const [bView, setBView] = useState<BrandView>("work");
  const [sView, setSView] = useState<SiteView | "proposals">("gain");
  const [q, setQ] = useState("");
  const [bSort, setBSort] = useState<Sort<BrandSort>>(null);
  const [sSort, setSSort] = useState<Sort<RailSort>>(null);
  const visible = useSectionVisible();

  useEffect(() => {
    rest<{ brands: Brand[] }>(cfg, "GET", "brand-sites").then((d) => setBrands(d.brands)).catch((e) => { setBrands([]); setNotice({ text: msg(e), kind: "error" }); });
  }, [cfg]);
  const loadMaps = useCallback(() => {
    rest<MapsReply>(cfg, "GET", "maps").then(setMaps).catch((e) => { setMaps({ rows: [], runner: NO_RUNNER }); setNotice({ text: msg(e), kind: "error" }); });
  }, [cfg]);
  const loadProposals = useCallback(() => { rest<{ rows: Proposal[] }>(cfg, "GET", "map-proposals").then((d) => setProposals(d.rows)).catch(() => {}); }, [cfg]);
  useEffect(() => { loadMaps(); loadProposals(); }, [loadMaps, loadProposals]);
  const mapsChanged = () => { loadMaps(); loadProposals(); };

  const waitingOn = (host: string) => proposals.filter((p) => p.host === host && p.status === "proposed").length;
  const siteRow = (host: string): SiteRow | null => maps?.rows.find((r) => r.host === host) ?? null;
  const replaceBrand = (b: Brand) => setBrands((bs) => (bs ? bs.map((x) => (x.id === b.id ? b : x)) : bs));
  /** Open a brand (from the list, a site's "maker's site of" line, or a site under a brand), on a tab and a site's map. */
  const openBrand = (id: number, tab?: BrandTab, host?: string) => {
    setMode("brand");
    setBrandId(id);
    if (tab) setBTab(tab);
    if (host) setMapHost(host);
  };

  // By brand
  const brandList = sortBrands(brands ? brandsIn(brands, bView, q) : [], bSort);
  const brandRef = useRef<HTMLTableElement>(null);
  useColumnResize(brandRef, mode === "brand" && brandList.length > 0);
  const brand = brands?.find((b) => b.id === brandId) ?? null;
  const onBrandKey = useListKeys(brandRef, brandList.map((b) => String(b.id)), brandId ? String(brandId) : null, (k) => setBrandId(Number(k)));
  const sortBrandsBy = (col: BrandSort) => setBSort((s) => nextSort(s, col, "todo"));

  // By site
  const withProposals = proposalHosts(proposals);
  const siteList = sortSites(maps ? (sView === "proposals" ? sitesIn(maps.rows, "all", q).filter((r) => withProposals.has(r.host)) : sitesIn(maps.rows, sView, q)) : [], sSort);
  const siteRef = useRef<HTMLTableElement>(null);
  useColumnResize(siteRef, mode === "site" && siteList.length > 0);
  const site = siteHost ? siteRow(siteHost) : null;
  const onSiteKey = useListKeys(siteRef, siteList.map((r) => r.host), siteHost, setSiteHost);
  const statusValue = sView !== "gain" && sView !== "proposals" ? sView : "";

  // The brand's Map tab: which of its maker sites it shows.
  const hosts = brand ? makerHosts(brand) : [];
  const shownHost = mapHost && hosts.includes(mapHost) ? mapHost : hosts[0] ?? null;
  useAddressParam("wb_brand", mode === "brand" && brandId ? String(brandId) : null, visible);
  useAddressParam("wb_site", mode === "site" ? siteHost : (bTab === "map" || bTab === "fill") && brand ? shownHost : null, visible);

  const mapWaiting = hosts.reduce((n, h) => n + waitingOn(h), 0);
  const firstMap = shownHost ? siteRow(shownHost) : null;
  const gaps = hosts.reduce((n, h) => { const r = siteRow(h); return n + (r ? gainOf(r) : 0); }, 0);
  const step = (focus: "map" | "fill") => brand && (
    <BrandSiteStep cfg={cfg} focus={focus} brand={brand} brands={brands ?? []} hosts={hosts} host={shownHost} row={firstMap} loaded={maps !== null}
      waitingOn={waitingOn} siteRow={siteRow} onHost={setMapHost} proposals={proposals} tab={sTab} onTab={setSTab} onChanged={mapsChanged} />
  );
  const brandMap: BrandStep | null = brand && {
    count: mapWaiting ? `${mapWaiting} to approve` : hosts.length > 1 ? `${hosts.length} sites` : firstMap ? mapState(firstMap.status)[0] : hosts.length ? "No map" : "",
    dot: mapWaiting > 0,
    body: step("map"),
  };
  // Fill: the gaps its sites' pages would fill, as last measured (the site list's numbers).
  const brandFill: BrandStep | null = brand && { count: gaps ? plural(gaps, "gap") : "", dot: false, body: step("fill") };
  const owners = site && brands ? brands.filter((b) => b.sites.some((s) => s.host === site.host && isMaker(s))) : [];

  return (
    <Section id="mmi-wb-makers" icon="store" title="Makers"
      desc="Each maker's sites, its products matched to the maker's own pages, and each site's map and Fill. List by brand or by site; a site's parts are picked on its live page in the Chrome extension"
      actions={cfg.extension ? <a className="button" id="mmi-wb-sm-extension" href={cfg.extension}><Dashicon name="admin-plugins" /> Chrome extension</a> : undefined}>
      {notice && <div className="notice notice-error inline" role="status"><p>{notice.text}</p></div>}
      <div className="mmi-wb-split">
        <div className="mmi-wb-split-list" id={mode === "brand" ? "mmi-wb-bs-list" : "mmi-wb-sm-rail"}>
          <div className="mmi-wb-mk-mode" role="group" aria-label="List the makers">
            <button type="button" id="mmi-wb-mk-by-brand" className={`button button-small${mode === "brand" ? " is-active" : ""}`} aria-pressed={mode === "brand"} onClick={() => setMode("brand")}>By brand</button>
            <button type="button" id="mmi-wb-mk-by-site" className={`button button-small${mode === "site" ? " is-active" : ""}`} aria-pressed={mode === "site"} onClick={() => setMode("site")}>By site</button>
          </div>

          {mode === "brand" ? (<>
            <div className="mmi-filter-pills mmi-wb-pr-views" role="group" aria-label="Brands that need work">
              {BRAND_VIEWS.filter((v) => WORK_VIEWS.includes(v.value)).map((v) => (
                <button key={v.value} type="button" className={`mmi-pill${bView === v.value ? " active" : ""}`} aria-pressed={bView === v.value} data-bview={v.value} title={v.hint || undefined} onClick={() => setBView(v.value)}>
                  {v.label} <span className="mmi-pill-count">{brands ? brandViewCount(brands, v.value) : "…"}</span>
                </button>
              ))}
            </div>
            <div className="mmi-wb-split-filters">
              <select id="mmi-wb-bs-view" aria-label="Other brand lists" value={WORK_VIEWS.includes(bView) ? "" : bView} onChange={(e) => setBView((e.target.value || "work") as BrandView)}>
                <option value="">More lists…</option>
                {BRAND_VIEWS.filter((v) => !WORK_VIEWS.includes(v.value)).map((v) => <option key={v.value} value={v.value} title={v.hint || undefined}>{v.label} ({brands ? brandViewCount(brands, v.value) : "…"})</option>)}
              </select>
              <input type="search" id="mmi-wb-bs-q" aria-label="Find brands" placeholder="Brand or site" value={q} onChange={(e) => setQ(e.target.value)} />
              <span className="mmi-wb-count" id="mmi-wb-bs-count">{brands ? plural(brandList.length, "brand") : ""}</span>
            </div>
            <div className="mmi-table-scroll-wrapper mmi-wb-split-scroll">
              <table ref={brandRef} className="wp-list-table widefat striped mmi-wb-pr-table" id="mmi-wb-bs-table">
                <thead>
                  <tr>
                    <SortTh col="brand" label="Brand" active={bSort?.col === "brand"} desc={!!bSort?.desc} onSort={sortBrandsBy} />
                    <SortTh col="todo" label="To do" title="Products waiting for a maker page" active={bSort?.col === "todo"} desc={!!bSort?.desc} onSort={sortBrandsBy} className="mmi-wb-split-num" />
                  </tr>
                </thead>
                <tbody id="mmi-wb-bs-rows" onKeyDown={onBrandKey}>
                  {!brands && <tr><td colSpan={2} className="mmi-wb-empty-cell">Loading brands…</td></tr>}
                  {brands && !brandList.length && <tr><td colSpan={2} className="mmi-wb-empty-cell">Nothing here.</td></tr>}
                  {brandList.map((b) => {
                    const makers = b.sites.filter(isMaker);
                    return (
                      <tr key={b.id} data-brand={b.id} data-key={b.id} className={brandId === b.id ? "is-open" : undefined}>
                        <td>
                          <div className="mmi-wb-bs-brand">
                            {b.logo && <img src={b.logo} alt="" loading="lazy" />}
                            <button type="button" className="button-link mmi-wb-split-name" aria-current={brandId === b.id ? "true" : undefined} onClick={() => setBrandId(b.id)}>{b.name}</button>
                          </div>
                          <div className="mmi-wb-split-meta">
                            {b.gone ? <span className="mmi-badge inline">set aside</span> : !makers.length ? <span className="mmi-wb-mp-warn">None saved</span> : null}
                            <span>{plural(b.products, "product")}</span>
                          </div>
                          {/* Its maker sites under it, each with its map's state: a click opens the brand on that site's map. */}
                          {!b.gone && makers.length > 0 && (
                            <ul className="mmi-wb-mk-sites">
                              {makers.map((s) => {
                                const w = waitingOn(s.host);
                                const [label, badge] = mapState(siteRow(s.host)?.status ?? s.map);
                                return (
                                  <li key={s.host} data-site={s.host}>
                                    <button type="button" className="button-link" tabIndex={-1} title={`Open ${b.name} on ${s.host}'s map`} onClick={() => openBrand(b.id, "map", s.host)}>{s.host}</button>
                                    {w ? <span className="mmi-badge warning inline">{plural(w, "part")}</span> : <span className={`mmi-badge ${badge} inline`}>{label}</span>}
                                  </li>
                                );
                              })}
                            </ul>
                          )}
                        </td>
                        <td className="mmi-wb-split-num" title={toDoText(b) || "Nothing waits"}>{toDo(b) ? toDo(b).toLocaleString("en-US") : <span className="description">–</span>}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
            {brandList.length > 1 && <p className="description mmi-wb-split-keys">↑ and ↓ move between brands.</p>}
          </>) : (<>
            <div className="mmi-filter-pills mmi-wb-pr-views" role="group" aria-label="Work waiting">
              <button type="button" className={`mmi-pill${sView === "gain" ? " active" : ""}`} aria-pressed={sView === "gain"} data-sview="gain" title={SITE_VIEWS[0]!.hint} onClick={() => setSView("gain")}>
                Gaps to fill <span className="mmi-pill-count">{maps ? siteViewCount(maps.rows, "gain") : "…"}</span>
              </button>
              <button type="button" className={`mmi-pill${sView === "proposals" ? " active" : ""}`} aria-pressed={sView === "proposals"} data-sview="proposals" title="Sites with parts found on their pages, waiting for a person's approval" onClick={() => setSView("proposals")}>
                Parts to approve <span className="mmi-pill-count">{withProposals.size}</span>
              </button>
            </div>
            <div className="mmi-wb-split-filters">
              <select id="mmi-wb-sm-status" aria-label="Map status" value={statusValue} onChange={(e) => setSView((e.target.value || "gain") as SiteView)}>
                <option value="">By map status…</option>
                {SITE_VIEWS.filter((v) => v.value !== "gain").map((v) => <option key={v.value} value={v.value} title={v.hint || undefined}>{v.label} ({maps ? siteViewCount(maps.rows, v.value) : "…"})</option>)}
              </select>
              <input type="search" id="mmi-wb-sm-q" aria-label="Find sites" placeholder="Site" value={q} onChange={(e) => setQ(e.target.value)} />
              <span className="mmi-wb-count" id="mmi-wb-sm-count">{maps ? plural(siteList.length, "site") : ""}</span>
            </div>
            {maps?.runner.running && <p className="description" role="status"><span className="mmi-loading" aria-hidden="true" /> Checking maps{maps.runner.note ? `: ${maps.runner.note}` : ""}</p>}
            <div className="mmi-table-scroll-wrapper mmi-wb-split-scroll">
              <table ref={siteRef} className="wp-list-table widefat striped mmi-wb-pr-table" id="mmi-wb-sm-table">
                <thead>
                  <tr>
                    <SortTh col="site" label="Site" active={sSort?.col === "site"} desc={!!sSort?.desc} onSort={(c) => setSSort((s) => nextSort(s, c, "gaps"))} />
                    <SortTh col="gaps" label="Gaps" title="Gaps its pages would fill, as last measured" active={sSort?.col === "gaps"} desc={!!sSort?.desc} onSort={(c) => setSSort((s) => nextSort(s, c, "gaps"))} className="mmi-wb-split-num" />
                  </tr>
                </thead>
                <tbody id="mmi-wb-sm-rows" onKeyDown={onSiteKey}>
                  {!maps && <tr><td colSpan={2} className="mmi-wb-empty-cell">Loading sites…</td></tr>}
                  {maps && !siteList.length && <tr><td colSpan={2} className="mmi-wb-empty-cell">Nothing here.</td></tr>}
                  {siteList.map((r) => {
                    const [label, badge] = mapState(r.status);
                    const waiting = waitingOn(r.host);
                    return (
                      <tr key={r.host} data-host={r.host} data-key={r.host} className={siteHost === r.host ? "is-open" : undefined}>
                        <td>
                          <button type="button" className="button-link mmi-wb-split-name" aria-current={siteHost === r.host ? "true" : undefined} onClick={() => setSiteHost(r.host)}>{r.host}</button>
                          <div className="mmi-wb-split-meta">
                            <span className={`mmi-badge ${badge} inline`}>{label}</span>
                            {waiting ? <span className="mmi-wb-mp-warn">{plural(waiting, "part")} to approve</span>
                              : <span>{r.stats.read} of {r.stats.pages} read{r.stats.refused ? <span className="mmi-wb-mp-warn"> · {r.stats.refused} refused</span> : null}</span>}
                          </div>
                        </td>
                        <td className="mmi-wb-split-num" title={gainOf(r) ? `${plural(gainOf(r), "gap")} on ${plural(gainProducts(r), "product")}` : "Nothing its pages would add"}>
                          {gainOf(r) ? gainOf(r).toLocaleString("en-US") : <span className="description">–</span>}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
            {siteList.length > 1 && <p className="description mmi-wb-split-keys">↑ and ↓ move between sites.</p>}
          </>)}
        </div>

        <div className="mmi-wb-split-work">
          {mode === "brand" && (brand && brandMap && brandFill
            ? <BrandDrawer key={brand.id} cfg={cfg} brand={brand} onBrand={replaceBrand} tab={bTab} onTab={setBTab} map={brandMap} fill={brandFill} />
            : (
              <div className="mmi-wb-split-empty" id="mmi-wb-bs-empty">
                <p><strong>Choose a brand on the left.</strong></p>
                <p className="description">It opens here: its products matched to the maker's own pages, its maker site's map, and the sites it links to.</p>
              </div>
            ))}
          {mode === "site" && (site
            ? (<>
              <p className="description mmi-wb-mk-owners" id="mmi-wb-mk-owners">
                {owners.length
                  ? <>The maker's site of {owners.map((b, i) => <span key={b.id}>{i ? ", " : ""}<button type="button" className="button-link" data-owner={b.id} title={`Open ${b.name} on this site's map`} onClick={() => openBrand(b.id, "map", site.host)}>{b.name}</button></span>)}.</>
                  : "No brand has this saved as its maker's site."}
              </p>
              <SitePanel key={site.host} cfg={cfg} site={site} proposals={proposals.filter((p) => p.host === site.host)} tab={sTab} onTab={setSTab} onChanged={mapsChanged} />
            </>)
            : (
              <div className="mmi-wb-split-empty" id="mmi-wb-sm-empty">
                <p><strong>Choose a site on the left.</strong></p>
                <p className="description">It opens here, with its parts, what its pages would add to its products, and the Fill that writes it.</p>
              </div>
            ))}
        </div>
      </div>
    </Section>
  );
}

/**
 * An open brand's Map or Fill tab: a pill per maker site when it has several, who else a shared site serves, the offer
 * to switch off a map the brand no longer needs, then the site's panel focused on the step.
 */
function BrandSiteStep({ cfg, focus, brand, brands, hosts, host, row, loaded, waitingOn, siteRow, onHost, proposals, tab, onTab, onChanged }: {
  cfg: TitleRulesConfig; focus: "map" | "fill"; brand: Brand; brands: Brand[]; hosts: string[]; host: string | null; row: SiteRow | null; loaded: boolean;
  waitingOn: (h: string) => number; siteRow: (h: string) => SiteRow | null; onHost: (h: string) => void;
  proposals: Proposal[]; tab: SiteTab; onTab: (t: SiteTab) => void; onChanged: () => void;
}) {
  const [armed, setArmed] = useState<string | null>(null);
  const [said, setSaid] = useState<Notice | null>(null);
  if (!host) return <p className="mmi-wb-split-why is-blocked" id="mmi-wb-mk-nomap">No maker site is saved, so there is no map yet. Add the maker's site under Sites.</p>;
  const others = brands.filter((b) => b.id !== brand.id && b.sites.some((s) => s.host === host && isMaker(s))).map((b) => b.name);
  // A map only this brand uses, on a site next to none of its products link to now (its pages moved): offer to switch it off.
  const idle = brand.sites.filter((s) => isMaker(s) && s.shared <= 1 && s.n <= IDLE_LINKS && (() => { const r = siteRow(s.host); return !!r && !["off", "auto", "refused"].includes(r.status); })());
  const switchOff = async (h: string) => {
    if (armed !== h) { setArmed(h); return; }
    setArmed(null);
    try {
      await rest(cfg, "POST", `maps/${encodeURIComponent(h)}/off`, {});
      setSaid({ text: `${h}'s map switched off: the site is read without it. Turn it back on from its map if it's needed again.`, kind: "ok" });
      onChanged();
    } catch (e) { setSaid({ text: msg(e), kind: "error" }); }
  };
  return (
    <>
      {hosts.length > 1 && (
        <div className="mmi-filter-pills mmi-wb-pr-views" role="group" aria-label={`${brand.name}'s maker sites`} id="mmi-wb-mk-hosts">
          {hosts.map((h) => {
            const w = waitingOn(h);
            const r = siteRow(h);
            const g = r ? gainOf(r) : 0;
            return (
              <button key={h} type="button" data-mhost={h} className={`mmi-pill${h === host ? " active" : ""}`} aria-pressed={h === host} onClick={() => onHost(h)}>
                {h} <span className="mmi-pill-count">{focus === "fill" ? (g ? plural(g, "gap") : r ? "nothing to fill" : "No map") : w ? `${w} to approve` : r ? mapState(r.status)[0] : "No map"}</span>
              </button>
            );
          })}
        </div>
      )}
      {others.length > 0 && (
        <p className="mmi-wb-split-why is-info" id="mmi-wb-mk-shared">
          {focus === "fill"
            ? <>{host} is also the maker's site of {others.join(", ")}. A Fill on it writes their products too.</>
            : <>{host} is also the maker's site of {others.join(", ")}. Its parts and its Fill cover their products too.</>}
        </p>
      )}
      {idle.map((s) => (
        <div key={s.host} className="notice notice-info inline mmi-wb-mk-idle" data-idle={s.host}>
          <p>{s.n ? `Only ${plural(s.n, "product")} of ${brand.name}'s link${s.n === 1 ? "s" : ""}` : `None of ${brand.name}'s products link`} to {s.host} now, and no other brand uses it, so its map does next to nothing.{" "}
            <button type="button" className={`button button-small${armed === s.host ? " is-armed" : ""}`} data-idle-action="off" onClick={() => void switchOff(s.host)}>
              {armed === s.host ? "Click again to switch it off" : "Switch its map off"}
            </button>
          </p>
        </div>
      ))}
      {said && <div className={`notice notice-${said.kind === "error" ? "error" : "success"} inline`} role="status" id="mmi-wb-mk-said"><p>{said.text}</p></div>}
      {!loaded ? <p className="mmi-wb-empty-cell">Loading the map…</p>
        : row ? <SitePanel key={`${focus}:${host}`} cfg={cfg} focus={focus} site={row} proposals={proposals.filter((p) => p.host === host)} tab={tab} onTab={onTab} onChanged={onChanged} />
        : (
          <div className="mmi-wb-split-empty" id="mmi-wb-mk-nomap">
            <p><strong>{host} has no map yet.</strong></p>
            <p className="description">{focus === "fill" ? "There is nothing to fill from it until it has one: its pages are read without a map." : "Its pages are read without one. Pick its parts on its live page in the Chrome extension."}</p>
          </div>
        )}
    </>
  );
}
