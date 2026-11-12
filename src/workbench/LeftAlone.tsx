/**
 * Left alone by Product Data: the brands and products that get no title
 * proposals, maker-page reads or description and picture proposals
 * (GET/POST /exclusions). Leaving a brand alone asks first, since its open
 * proposals are removed; taking one back is a second click on the same button.
 */
import { useCallback, useEffect, useRef, useState } from "react";
import { ApiError } from "./api";
import { brandChoices, leftRows, leftState, saveBody, savedText, type LeftAloneSaved, type LeftAloneSummary, type LeftRow } from "./leftAloneApi";
import { rest, type TitleRulesConfig } from "./titleRulesApi";
import { BusyButton, Dashicon, Section, useColumnResize } from "./ui";

const msg = (e: unknown) => (e instanceof ApiError ? e.message : String(e));

export function LeftAlone({ cfg }: { cfg: TitleRulesConfig }) {
  const [s, setS] = useState<LeftAloneSummary | null>(null);
  const [brand, setBrand] = useState("");
  const [product, setProduct] = useState("");
  const [asking, setAsking] = useState(false);
  const [armed, setArmed] = useState("");
  const [busy, setBusy] = useState(false);
  const [said, setSaid] = useState<{ text: string; ok: boolean } | null>(null);

  const load = useCallback(() => { rest<LeftAloneSummary>(cfg, "GET", "exclusions").then(setS).catch((e) => setSaid({ text: msg(e), ok: false })); }, [cfg]);
  useEffect(() => { load(); }, [load]);

  const save = async (change: Parameters<typeof saveBody>[1]) => {
    if (!s) return;
    setBusy(true);
    setSaid(null);
    try {
      const r = await rest<LeftAloneSaved>(cfg, "POST", "exclusions", saveBody(s, change));
      setS(r.summary);
      setSaid({ text: savedText(r), ok: !r.unknown.length });
      setAsking(false);
      setArmed("");
      if (change.addBrand) setBrand("");
      if (change.addProduct && !r.unknown.length) setProduct("");
    } catch (e) { setSaid({ text: msg(e), ok: false }); } finally { setBusy(false); }
  };

  const remove = (r: LeftRow) => {
    if (armed !== r.key) { setArmed(r.key); return; }
    void save(r.kind === "brand" ? { removeBrand: r.id } : { removeProduct: r.id });
  };

  const brandName = s?.all_brands.find((b) => String(b.id) === brand)?.name ?? "";
  const rows = s ? leftRows(s) : [];
  const tableRef = useRef<HTMLTableElement>(null);
  useColumnResize(tableRef, rows.length > 0);
  return (
    <Section id="mmi-wb-left-alone" icon="hidden" title="Left alone by Product Data"
      desc="Brands and products that get no title proposals, no maker-page reads and no description or picture proposals, and are never written to. Taking one back makes its products eligible again">
      {said && <div className={`notice notice-${said.ok ? "success" : "error"} inline`} role="status" id="mmi-wb-la-notice"><p>{said.text}</p></div>}
      <div className="mmi-wb-filters mmi-wb-la-add">
        <select id="mmi-wb-la-brand" aria-label="Brand" value={brand} disabled={busy || !s} onChange={(e) => { setBrand(e.target.value); setAsking(false); }}>
          <option value="">Choose a brand…</option>
          {s && brandChoices(s).map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
        </select>
        <button type="button" className="button" id="mmi-wb-la-brand-add" disabled={busy || !brand} onClick={() => setAsking(true)}>Leave brand alone</button>
        <input type="search" id="mmi-wb-la-product" aria-label="Product ID or SKU" placeholder="Product ID or SKU" value={product} disabled={busy || !s}
          onChange={(e) => setProduct(e.target.value)} onKeyDown={(e) => { if (e.key === "Enter" && product.trim()) void save({ addProduct: product }); }} />
        <BusyButton id="mmi-wb-la-product-add" className="button" busy={busy && !!product} icon="hidden" label="Leave product alone" busyLabel="Saving…"
          disabled={!product.trim() || !s} onClick={() => void save({ addProduct: product })} />
      </div>
      {asking && brand && (
        <div className="mmi-wb-writing-ask" role="group" id="mmi-wb-la-ask">
          <span>Leave every {brandName} product alone? Their open proposals are removed.</span>{" "}
          <button type="button" className="button button-small button-primary" disabled={busy} onClick={() => void save({ addBrand: Number(brand) })}>Leave it alone</button>{" "}
          <button type="button" className="button-link" disabled={busy} onClick={() => setAsking(false)}>Cancel</button>
        </div>
      )}
      <p className="description" id="mmi-wb-la-state">{s ? leftState(s) : "Loading…"}</p>
      <div className="mmi-table-scroll-wrapper">
        <table ref={tableRef} className="wp-list-table widefat striped" id="mmi-wb-la-table">
          <thead><tr><th data-resize-col="name">Left alone</th><th data-resize-col="type">Type</th><th data-resize-col="products">Products</th><th data-resize-col="actions"><span className="screen-reader-text">Actions</span></th></tr></thead>
          <tbody>
            {s && !rows.length && <tr><td colSpan={4} className="mmi-wb-empty-cell">Nothing is left alone.</td></tr>}
            {rows.map((r) => (
              <tr key={r.key} data-key={r.key}>
                <td>{r.kind === "product" ? <a href={`post.php?post=${r.id}&action=edit`} target="_blank" rel="noreferrer">{r.label}</a> : r.label}</td>
                <td>{r.kind === "brand" ? "Brand" : "Product"}</td>
                <td>{r.products}</td>
                <td className="mmi-wb-job-actions">
                  {r.fixed
                    ? <span className="description">Always: the store’s own products</span>
                    : <button type="button" className="button button-small" disabled={busy} title="Its products get proposals again" onClick={() => remove(r)}>
                        <Dashicon name="visibility" /> {armed === r.key ? "Click again to take it back" : "Stop leaving it alone"}
                      </button>}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Section>
  );
}
