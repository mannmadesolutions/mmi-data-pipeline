/**
 * A person's say over pictures in a site's Fill review (owner 2026-10-30: "some images may pass the code/logic test,
 * but upon human inspection, they fail"): each product's pictures as they would end up (the first is featured), with
 * Veto (this product, or every product of the site that has the same picture) and Make featured; and every distinct
 * picture across the reviewed products, vetoed for all of them at once. Choices are recognised by fingerprint
 * (PictureChoices), and the picture runner writes them; Undo takes a choice back.
 */
import { useCallback, useEffect, useState } from "react";
import { choiceBody, kindsText, PIC_WORDS, tilesOf, type PicItem, type ProductPlan, type SitePictures as SitePics } from "./mapsApi";
import { msg, type Notice } from "./pageRows";
import { rest, type TitleRulesConfig } from "./titleRulesApi";

const enc = encodeURIComponent;

/** POST or DELETE a choice; the caller reads the pictures again. */
async function choose(cfg: TitleRulesConfig, body: Record<string, unknown> | null, undo = 0): Promise<string> {
  if (undo) {
    await rest(cfg, "DELETE", `pictures/choices/${undo}`);
    return "Choice taken back.";
  }
  const d = await rest<{ kind: string; scope: string }>(cfg, "POST", "pictures/choices", body!);
  return d.kind === "featured" ? "Featured on this product." : d.scope === "site" ? "Vetoed on every product of this site." : "Vetoed on this product.";
}

export function ProductPictures({ cfg, host, id, title }: { cfg: TitleRulesConfig; host: string; id: number; title: string }) {
  const [plan, setPlan] = useState<ProductPlan | null>(null);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);
  const load = useCallback(async () => {
    try { setPlan(await rest<ProductPlan>(cfg, "GET", `maps/${enc(host)}/product?id=${id}&review=1`)); } catch (e) { setNotice({ text: msg(e), kind: "error" }); }
  }, [cfg, host, id]);
  useEffect(() => { void load(); }, [load]);

  const act = async (body: Record<string, unknown> | null, undo = 0) => {
    setBusy(true);
    try { setNotice({ text: await choose(cfg, body, undo), kind: "ok" }); await load(); } catch (e) { setNotice({ text: msg(e), kind: "error" }); } finally { setBusy(false); }
  };

  const merge = plan?.fields.pictures?.merge;
  const tiles = tilesOf(merge);
  const tile = (p: PicItem, i: number) => {
    const [word, tone] = PIC_WORDS[p.kind] ?? [p.kind, "same"];
    const vetoed = p.kind === "vetoed";
    const featured = i === 0 && !vetoed;
    const pic = { att: p.att, url: p.src || p.url };
    return (
      <div key={(p.key || p.url) + i} className={`mmi-wb-pc-tile is-${tone}${vetoed ? " is-vetoed" : ""}`} data-kind={p.kind}>
        <img src={p.url} alt="" loading="lazy" />
        <span className="mmi-wb-pc-word">{featured ? "Featured · " : ""}{word}</span>
        <span className="mmi-wb-pc-actions">
          {vetoed && p.choice ? <button type="button" className="button button-small" disabled={busy} onClick={() => void act(null, p.choice)}>Undo veto</button> : null}
          {!vetoed && (
            <>
              <button type="button" className="button button-small" disabled={busy} title={`Never put this picture on ${title}`} onClick={() => void act(choiceBody("veto", "product", host, id, pic))}>Veto</button>
              <button type="button" className="button button-small" disabled={busy} title={`Never put this picture on any product of ${host} that has it`} onClick={() => void act(choiceBody("veto", "site", host, id, pic))}>Veto on every product</button>
              {featured && p.choice ? <button type="button" className="button button-small" disabled={busy} onClick={() => void act(null, p.choice)}>Undo featured</button>
                : !featured ? <button type="button" className="button button-small" disabled={busy} onClick={() => void act(choiceBody("featured", "product", host, id, pic))}>Make featured</button> : null}
            </>
          )}
        </span>
      </div>
    );
  };

  return (
    <div className="mmi-wb-pc" data-pictures={id}>
      {notice && <p className={notice.kind === "error" ? "mmi-wb-mp-error" : "description"} role="status">{notice.text}</p>}
      {!plan && !notice && <p className="description" role="status"><span className="mmi-loading" aria-hidden="true" /> Reading its pictures…</p>}
      {plan && !merge && <p className="description">{plan.fields.pictures?.why || "No pictures to review for this product."}</p>}
      {merge && <p className="description">{merge.summary || "No change to its pictures."}</p>}
      {tiles.length > 0 && <div className="mmi-wb-pc-tiles">{tiles.map(tile)}</div>}
    </div>
  );
}

export function SitePicturesPanel({ cfg, host, ids }: { cfg: TitleRulesConfig; host: string; ids: number[] }) {
  const [data, setData] = useState<SitePics | null>(null);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);
  const load = useCallback(async () => {
    try {
      // Read in slices: `next` is the first product not read yet.
      let from = 0;
      let all = null as SitePics | null;
      for (let i = 0; i < 20; i++) {
        const d = await rest<SitePics>(cfg, "GET", `maps/${enc(host)}/pictures?ids=${ids.join(",")}&from=${from}&review=1`);
        all = all ? { ...d, groups: mergeGroups(all.groups, d.groups), products: { ...all.products, ...d.products } } : d;
        setData(all);
        if (d.next === null || d.next === undefined) break;
        from = d.next;
      }
    } catch (e) { setNotice({ text: msg(e), kind: "error" }); }
  }, [cfg, host, ids]);
  useEffect(() => { void load(); }, [load]);

  const act = async (body: Record<string, unknown> | null, undo = 0) => {
    setBusy(true);
    try { setNotice({ text: await choose(cfg, body, undo), kind: "ok" }); await load(); } catch (e) { setNotice({ text: msg(e), kind: "error" }); } finally { setBusy(false); }
  };

  const groups = [...(data?.groups ?? [])].sort((a, b) => b.products.length - a.products.length);
  return (
    <div className="mmi-wb-pc-site" id="mmi-wb-sm-site-pictures">
      <p className="description">Every distinct picture across these products. One that appears on several is often a logo or a banner: veto it once for all of them.</p>
      {notice && <p className={notice.kind === "error" ? "mmi-wb-mp-error" : "description"} role="status">{notice.text}</p>}
      {!data && !notice && <p className="description" role="status"><span className="mmi-loading" aria-hidden="true" /> Reading the pictures…</p>}
      <div className="mmi-wb-pc-tiles">
        {groups.map((g) => (
          <div key={g.key} className={`mmi-wb-pc-tile${g.veto ? " is-vetoed" : ""}`} data-group={g.key}>
            <img src={g.thumb} alt="" loading="lazy" />
            <span className="mmi-wb-pc-word">{g.veto ? "Vetoed · " : ""}{g.products.length} product{g.products.length === 1 ? "" : "s"}</span>
            <span className="description mmi-wb-pc-kinds">{kindsText(g.kinds)}</span>
            <span className="mmi-wb-pc-actions">
              {g.veto ? <button type="button" className="button button-small" disabled={busy} onClick={() => void act(null, g.veto)}>Undo veto</button>
                : <button type="button" className="button button-small" disabled={busy} onClick={() => void act(choiceBody("veto", "site", host, g.products[0]!, { att: g.att || undefined, url: g.url || g.thumb }))}>Veto on every product</button>}
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}

/** Slices read one after another: a picture seen again adds its products and kinds to the group already listed. */
function mergeGroups(a: SitePics["groups"], b: SitePics["groups"]): SitePics["groups"] {
  const by = new Map(a.map((g) => [g.key, { ...g, products: [...g.products], kinds: { ...g.kinds } }]));
  for (const g of b) {
    const had = by.get(g.key);
    if (!had) { by.set(g.key, g); continue; }
    had.products = [...new Set([...had.products, ...g.products])];
    for (const [k, n] of Object.entries(g.kinds)) had.kinds[k] = (had.kinds[k] ?? 0) + n;
    had.veto = had.veto || g.veto;
    had.featured = had.featured || g.featured;
  }
  return [...by.values()];
}
