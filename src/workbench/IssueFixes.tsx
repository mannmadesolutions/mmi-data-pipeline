/**
 * Issues fixed where they show (owner 2026-11-12: every issue solvable from the Product Workbench, straight
 * away). IssueChips are a row's issues as buttons; one opens an IssuePanel under the row: what it means, and
 * its fixes. A fix either runs at once (a one-click action) or opens its panel in the same place:
 *   BrandFix     choose a brand (close names first) or create one: name, slug, logo from the media library,
 *                website, description; and map the supplier's value to it for its other products and imports
 *   CategoryFix  choose categories or create one under a parent; the same mapping
 *   SkuFix, DescribeFix (a phrase from the maker's or supplier's text), PictureFix, PropsFix
 * Nothing opens on top of anything else, except WordPress's own media library for a picture.
 */
import { useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { ApiError } from "./api";
import { filterTerms } from "./proposalsApi";
import { rest, type TermOption, type TitleRulesConfig } from "./titleRulesApi";
import {
  feedKey, fixContext, mapLine, mappable, runFix, slugify, sortIssues,
  type BrandContext, type CatsContext, type DescribeContext, type FixKind, type FixResult, type Issue, type NewBrand,
} from "./issuesApi";
import { BusyButton, Dashicon } from "./ui";

const msg = (e: unknown) => (e instanceof ApiError ? e.message : String(e));

/** The term lists, loaded once per page. */
const termCache = new Map<string, Promise<TermOption[]>>();
function terms(cfg: TitleRulesConfig, path: "brands" | "categories"): Promise<TermOption[]> {
  let p = termCache.get(path);
  if (!p) {
    p = rest<Record<string, TermOption[]>>(cfg, "GET", path).then((d) => d[path] ?? []);
    p.catch(() => termCache.delete(path));
    termCache.set(path, p);
  }
  return p;
}
/** A term created here is in the next list. */
const forgetTerms = (path: "brands" | "categories") => termCache.delete(path);

/* ── Chips ─────────────────────────────────────────────────────────────── */

export function IssueChips({ issues, open, onOpen }: { issues: Issue[]; open: string | null; onOpen: (key: string | null) => void }) {
  if (!issues.length) return null;
  return (
    <div className="mmi-wb-issues">
      {sortIssues(issues).map((i) => (
        <button key={i.key} type="button" className={`mmi-badge ${i.tone === "info" ? "info" : i.tone} inline mmi-wb-issue${open === i.key ? " is-open" : ""}`}
          aria-expanded={open === i.key} title={`${i.help} Click to fix it here.`} data-issue={i.key}
          onClick={() => onOpen(open === i.key ? null : i.key)}>
          {i.label} <Dashicon name={open === i.key ? "arrow-up-alt2" : "arrow-down-alt2"} />
        </button>
      ))}
    </div>
  );
}

/* ── The panel under a row ─────────────────────────────────────────────── */

export function IssuePanel({ cfg, productId, issue, cats, onTitle, onDone, onClose, extra }: {
  cfg: TitleRulesConfig;
  productId: number;
  issue: Issue;
  /** The product's categories now (CategoryFix starts from them). */
  cats: number[];
  /** Open the row's title editor (title fixes). */
  onTitle?: () => void;
  onDone: (r: FixResult) => void;
  onClose: () => void;
  /** More under the fixes (the results table's "show every product with this"). */
  extra?: ReactNode;
}) {
  const panelKinds: FixKind[] = ["brand", "cats", "sku", "describe"];
  const first = issue.fixes[0];
  // A gap with one obvious fix opens on it: no extra click to get to the form.
  const [kind, setKind] = useState<FixKind | null>(first && first.primary && panelKinds.includes(first.kind) ? first.kind : null);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState("");
  const box = useRef<HTMLDivElement>(null);
  useEffect(() => { box.current?.scrollIntoView({ block: "nearest" }); }, []);

  const act = async (action: string) => {
    setBusy(action);
    setError("");
    try { onDone(await runFix(cfg, productId, { kind: "action", action })); } catch (e) { setError(msg(e)); } finally { setBusy(null); }
  };

  return (
    <div className={`mmi-wb-issue-panel is-${issue.tone}`} ref={box} data-issue={issue.key} onKeyDown={(e) => { if (e.key === "Escape") { e.stopPropagation(); onClose(); } }}>
      <div className="mmi-wb-issue-head">
        <strong>{issue.label}</strong>
        {issue.help && <span className="description"> {issue.help}</span>}
        <button type="button" className="button-link mmi-wb-issue-close" aria-label="Close" onClick={onClose}><Dashicon name="no-alt" /></button>
      </div>
      <div className="mmi-wb-issue-fixes">
        {issue.fixes.map((f) => {
          const id = f.action ?? f.kind;
          if (f.kind === "action") {
            return <BusyButton key={id} className={`button${f.primary ? " button-primary" : ""}`} busy={busy === f.action} icon="yes" label={f.label} busyLabel="Fixing…"
              disabled={busy !== null} onClick={() => void act(f.action!)} />;
          }
          if (f.kind === "title") {
            return onTitle ? <button key={id} type="button" className={`button${f.primary ? " button-primary" : ""}`} onClick={onTitle}><Dashicon name="edit" /> {f.label}</button> : null;
          }
          return <button key={id} type="button" className={`button${kind === f.kind ? " is-active" : ""}${f.primary && kind !== f.kind ? " button-primary" : ""}`}
            aria-pressed={kind === f.kind} onClick={() => setKind(kind === f.kind ? null : f.kind)}>{f.label}</button>;
        })}
        {extra}
      </div>
      {error && <div className="notice notice-error inline"><p>{error}</p></div>}
      {kind === "brand" && <BrandFix cfg={cfg} productId={productId} onDone={onDone} />}
      {kind === "cats" && <CategoryFix cfg={cfg} productId={productId} current={cats} onDone={onDone} />}
      {kind === "sku" && <SkuFix cfg={cfg} productId={productId} onDone={onDone} />}
      {kind === "describe" && <DescribeFix cfg={cfg} productId={productId} onDone={onDone} />}
    </div>
  );
}

/* ── Mapping the supplier's value ──────────────────────────────────────── */

function RememberBoxes({ feed, target, targetId, ticked, onTick }: {
  feed: BrandContext["feed"]; target: string; targetId: number | null; ticked: Record<string, boolean>; onTick: (key: string, on: boolean) => void;
}) {
  const list = mappable(feed, targetId);
  if (!list.length) return null;
  return (
    <div className="mmi-wb-issue-remember">
      {list.map((f) => (
        <label key={feedKey(f)}>
          <input type="checkbox" checked={!!ticked[feedKey(f)]} onChange={(e) => onTick(feedKey(f), e.target.checked)} /> {mapLine(f, target)}
        </label>
      ))}
    </div>
  );
}

const rememberDefaults = (feed: BrandContext["feed"]): Record<string, boolean> =>
  Object.fromEntries(feed.filter((f) => !f.mapped).map((f) => [feedKey(f), true]));

/** What the supplier says, for the panel's first line. */
function FeedSays({ feed, noun }: { feed: BrandContext["feed"]; noun: string }) {
  if (!feed.length) return <p className="description">No supplier sends a {noun} for this product.</p>;
  return (
    <p className="mmi-wb-issue-feed">
      {feed.map((f) => (
        <span key={feedKey(f)}>{f.label} says <strong>“{f.value}”</strong>{f.mapped ? <> (mapped to {f.mapped.name})</> : <> (not mapped to any {noun})</>}. </span>
      ))}
    </p>
  );
}

/* ── Brand ─────────────────────────────────────────────────────────────── */

interface MediaFrame { on: (ev: string, fn: () => void) => void; open: () => void; state: () => { get: (k: string) => { first: () => { toJSON: () => { id: number; url: string; sizes?: Record<string, { url: string }> } } } } }
type WpMedia = (o: Record<string, unknown>) => MediaFrame;
const wpMedia = (): WpMedia | null => ((window as unknown as { wp?: { media?: WpMedia } }).wp?.media) ?? null;

/** WordPress's media library, for one image (or several), resolving with what was chosen. */
export function pickImages(title: string, button: string, multiple: boolean): Promise<{ id: number; url: string }[]> {
  const media = wpMedia();
  if (!media) return Promise.reject(new Error("The media library isn’t loaded on this page. Reload it and try again."));
  return new Promise((resolve) => {
    const frame = media({ title, button: { text: button }, library: { type: "image" }, multiple });
    frame.on("select", () => {
      const sel = frame.state().get("selection") as unknown as { toJSON: () => { id: number; url: string; sizes?: Record<string, { url: string }> }[] };
      resolve(sel.toJSON().map((a) => ({ id: a.id, url: a.sizes?.thumbnail?.url ?? a.sizes?.medium?.url ?? a.url })));
    });
    frame.open();
  });
}

export function BrandFix({ cfg, productId, startCreating = false, onDone }: { cfg: TitleRulesConfig; productId: number; startCreating?: boolean; onDone: (r: FixResult) => void }) {
  const [ctx, setCtx] = useState<BrandContext | null>(null);
  const [all, setAll] = useState<TermOption[] | null>(null);
  const [q, setQ] = useState("");
  const [chosen, setChosen] = useState<number | null>(null);
  const [creating, setCreating] = useState(startCreating);
  const [draft, setDraft] = useState<NewBrand>({ name: "", slug: "", description: "", logo_id: 0, website: "" });
  const [logo, setLogo] = useState("");
  const [ticked, setTicked] = useState<Record<string, boolean>>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    fixContext<BrandContext>(cfg, productId, "brand").then((c) => {
      setCtx(c);
      setTicked(rememberDefaults(c.feed));
      setDraft((d) => ({ ...d, name: d.name || c.suggested }));
      // Nothing close exists: creating it is the likely fix.
      if (!c.similar.length && c.suggested && c.can_create) setCreating(true);
      if (c.similar[0]?.score === 100) setChosen(c.similar[0].id);
    }).catch((e) => setError(msg(e)));
    terms(cfg, "brands").then(setAll).catch((e) => setError(msg(e)));
  }, [cfg, productId]);

  const list = useMemo(() => (all ? filterTerms(all, q, chosen ? [chosen] : [], 60) : []), [all, q, chosen]);
  const chosenName = chosen ? all?.find((t) => t.id === chosen)?.name ?? ctx?.similar.find((s) => s.id === chosen)?.name ?? "" : "";
  const target = creating ? draft.name.trim() : chosenName;

  const chooseLogo = () => {
    pickImages("Brand logo", "Use as logo", false).then((a) => { if (a[0]) { setDraft((d) => ({ ...d, logo_id: a[0]!.id })); setLogo(a[0]!.url); } }).catch((e) => setError(msg(e)));
  };

  const save = async () => {
    setBusy(true);
    setError("");
    try {
      const remember = Object.entries(ticked).filter(([, on]) => on).map(([k]) => k);
      const body = creating ? { kind: "brand", create: draft, remember } : { kind: "brand", ids: chosen ? [chosen] : [], remember };
      const r = await runFix(cfg, productId, body);
      if (creating) forgetTerms("brands");
      onDone(r);
    } catch (e) { setError(msg(e)); } finally { setBusy(false); }
  };

  if (!ctx) return <div className="mmi-wb-issue-form">{error ? <div className="notice notice-error inline"><p>{error}</p></div> : <p className="description"><span className="mmi-loading" aria-hidden="true" /> Loading what the suppliers say…</p>}</div>;

  return (
    <div className="mmi-wb-issue-form mmi-wb-brand-fix">
      <FeedSays feed={ctx.feed} noun="brand" />
      <div className="mmi-wb-issue-modes" role="tablist">
        <button type="button" role="tab" aria-selected={!creating} className={`mmi-wb-pr-chip${!creating ? " is-active" : ""}`} onClick={() => setCreating(false)}>Choose a brand</button>
        {ctx.can_create && <button type="button" role="tab" aria-selected={creating} className={`mmi-wb-pr-chip${creating ? " is-active" : ""}`} onClick={() => setCreating(true)}>Create a new brand</button>}
      </div>

      {!creating && (
        <div className="mmi-wb-issue-choose">
          {ctx.similar.length > 0 && (
            <p className="mmi-wb-issue-similar">Close to what the supplier sends:{" "}
              {ctx.similar.map((s) => (
                <button key={s.id} type="button" className={`mmi-wb-pr-chip${chosen === s.id ? " is-active" : ""}`} onClick={() => setChosen(s.id)}
                  title={`${s.count} product${s.count === 1 ? "" : "s"}`}>{s.name}</button>
              ))}
            </p>
          )}
          <label className="mmi-wb-tr-field mmi-wb-tr-grow">
            <span className="mmi-wb-label">Search every brand</span>
            <input type="search" className="mmi-wb-issue-search" placeholder="Search brands" value={q} onChange={(e) => setQ(e.target.value)} />
          </label>
          <div className="mmi-wb-pr-term-list" role="radiogroup" aria-label="Brands">
            {!all && <p className="description">Loading brands…</p>}
            {all && !list.length && <p className="description">No brand matches “{q}”.{ctx.can_create ? " Create it instead." : ""}</p>}
            {list.map((t) => (
              <label key={t.id} className="mmi-wb-pr-term">
                <input type="radio" name={`mmi-wb-brand-${productId}`} checked={chosen === t.id} onChange={() => setChosen(t.id)} /> {t.name}
              </label>
            ))}
          </div>
        </div>
      )}

      {creating && (
        <div className="mmi-wb-issue-create">
          <div className="mmi-wb-tr-fields">
            <label className="mmi-wb-tr-field mmi-wb-tr-grow">
              <span className="mmi-wb-label">Name, as the maker writes it</span>
              <input type="text" className="mmi-wb-new-brand-name" value={draft.name} maxLength={120} onChange={(e) => setDraft({ ...draft, name: e.target.value })} />
            </label>
            <label className="mmi-wb-tr-field">
              <span className="mmi-wb-label">Slug (address)</span>
              <input type="text" value={draft.slug} placeholder={slugify(draft.name)} onChange={(e) => setDraft({ ...draft, slug: e.target.value })} />
            </label>
          </div>
          <div className="mmi-wb-tr-fields">
            <div className="mmi-wb-tr-field mmi-wb-brand-logo">
              <span className="mmi-wb-label">Logo</span>
              <span className="mmi-wb-brand-logo-row">
                {logo ? <img src={logo} alt="" /> : <span className="mmi-wb-brand-logo-none" aria-hidden="true"><Dashicon name="format-image" /></span>}
                <button type="button" className="button" onClick={chooseLogo}>{logo ? "Change…" : "Choose or upload…"}</button>
                {logo && <button type="button" className="button-link" onClick={() => { setLogo(""); setDraft({ ...draft, logo_id: 0 }); }}>Remove</button>}
              </span>
            </div>
            <label className="mmi-wb-tr-field mmi-wb-tr-grow">
              <span className="mmi-wb-label">Brand website (the maker’s own site)</span>
              <input type="url" className="mmi-wb-new-brand-site" placeholder="https://" value={draft.website} onChange={(e) => setDraft({ ...draft, website: e.target.value })} />
            </label>
          </div>
          <label className="mmi-wb-tr-field mmi-wb-tr-grow">
            <span className="mmi-wb-label">Description (shown on the brand’s page)</span>
            <textarea rows={2} value={draft.description} onChange={(e) => setDraft({ ...draft, description: e.target.value })} />
          </label>
        </div>
      )}

      <RememberBoxes feed={ctx.feed} target={target} targetId={creating ? null : chosen} ticked={ticked} onTick={(k, on) => setTicked({ ...ticked, [k]: on })} />
      {error && <div className="notice notice-error inline"><p>{error}</p></div>}
      <div className="mmi-wb-tr-buttons">
        <BusyButton className="button button-primary mmi-wb-issue-save" busy={busy} icon="saved" busyLabel="Saving…"
          label={creating ? `Create ${draft.name.trim() || "the brand"} and set it` : chosenName ? `Set ${chosenName}` : "Set the brand"}
          disabled={creating ? !draft.name.trim() : !chosen} onClick={() => void save()} />
        <span className="description">The brand is locked against supplier imports; the title is recomposed now.</span>
      </div>
    </div>
  );
}

/* ── Categories ────────────────────────────────────────────────────────── */

export function CategoryFix({ cfg, productId, current, onDone }: { cfg: TitleRulesConfig; productId: number; current: number[]; onDone: (r: FixResult) => void }) {
  const [ctx, setCtx] = useState<CatsContext | null>(null);
  const [all, setAll] = useState<TermOption[] | null>(null);
  const [q, setQ] = useState("");
  const [picked, setPicked] = useState<number[]>(current);
  const [creating, setCreating] = useState(false);
  const [draft, setDraft] = useState({ name: "", parent: 0 });
  const [ticked, setTicked] = useState<Record<string, boolean>>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    fixContext<CatsContext>(cfg, productId, "cats").then((c) => { setCtx(c); setTicked(rememberDefaults(c.feed)); }).catch((e) => setError(msg(e)));
    terms(cfg, "categories").then(setAll).catch((e) => setError(msg(e)));
  }, [cfg, productId]);

  const name = (id: number) => all?.find((t) => t.id === id)?.path ?? `#${id}`;
  const target = creating && draft.name.trim() ? draft.name.trim() : picked.length ? name(picked[0]!) : "";

  const save = async () => {
    setBusy(true);
    setError("");
    try {
      const remember = Object.entries(ticked).filter(([, on]) => on).map(([k]) => k);
      const r = await runFix(cfg, productId, { kind: "cats", ids: picked, remember, ...(creating && draft.name.trim() ? { create: draft } : {}) });
      if (creating) forgetTerms("categories");
      onDone(r);
    } catch (e) { setError(msg(e)); } finally { setBusy(false); }
  };

  if (!ctx) return <div className="mmi-wb-issue-form">{error ? <div className="notice notice-error inline"><p>{error}</p></div> : <p className="description"><span className="mmi-loading" aria-hidden="true" /> Loading what the suppliers say…</p>}</div>;

  return (
    <div className="mmi-wb-issue-form mmi-wb-cats-fix">
      <FeedSays feed={ctx.feed} noun="category" />
      <div className="mmi-wb-pr-chips">{picked.map((id) => (
        <button key={id} type="button" className="mmi-wb-pr-chip is-active" aria-label={`Remove ${name(id)}`} onClick={() => setPicked((p) => p.filter((x) => x !== id))}>{name(id)} ×</button>
      ))}</div>
      <label className="mmi-wb-tr-field mmi-wb-tr-grow">
        <span className="mmi-wb-label">Categories</span>
        <input type="search" className="mmi-wb-issue-search" placeholder="Search categories" value={q} onChange={(e) => setQ(e.target.value)} />
      </label>
      <div className="mmi-wb-pr-term-list" role="group" aria-label="Categories">
        {!all && <p className="description">Loading categories…</p>}
        {all && filterTerms(all, q, picked).map((t) => (
          <label key={t.id} className="mmi-wb-pr-term" style={{ paddingLeft: `${t.depth * 14}px` }} title={t.path}>
            <input type="checkbox" checked={picked.includes(t.id)} onChange={(e) => setPicked((p) => (e.target.checked ? [...p, t.id] : p.filter((x) => x !== t.id)))} /> {q ? t.path : t.name}
          </label>
        ))}
      </div>
      {ctx.can_create && (
        <div className="mmi-wb-issue-create-cat">
          <label><input type="checkbox" checked={creating} onChange={(e) => setCreating(e.target.checked)} /> Create a new category too</label>
          {creating && (
            <div className="mmi-wb-tr-fields">
              <label className="mmi-wb-tr-field mmi-wb-tr-grow">
                <span className="mmi-wb-label">New category’s name</span>
                <input type="text" value={draft.name} maxLength={120} onChange={(e) => setDraft({ ...draft, name: e.target.value })} />
              </label>
              <label className="mmi-wb-tr-field">
                <span className="mmi-wb-label">Inside</span>
                <select value={draft.parent} onChange={(e) => setDraft({ ...draft, parent: Number(e.target.value) })}>
                  <option value={0}>(top level)</option>
                  {all?.map((t) => <option key={t.id} value={t.id}>{"— ".repeat(t.depth)}{t.name}</option>)}
                </select>
              </label>
            </div>
          )}
        </div>
      )}
      <RememberBoxes feed={ctx.feed} target={target} targetId={creating ? null : picked[0] ?? null} ticked={ticked} onTick={(k, on) => setTicked({ ...ticked, [k]: on })} />
      {error && <div className="notice notice-error inline"><p>{error}</p></div>}
      <div className="mmi-wb-tr-buttons">
        <BusyButton className="button button-primary mmi-wb-issue-save" busy={busy} icon="saved" label="Save categories" busyLabel="Saving…"
          disabled={!picked.length && !(creating && draft.name.trim())} onClick={() => void save()} />
        <span className="description">“Uncategorized” goes once a real category is chosen. Locked against supplier imports.</span>
      </div>
    </div>
  );
}

/* ── SKU ───────────────────────────────────────────────────────────────── */

export function SkuFix({ cfg, productId, onDone }: { cfg: TitleRulesConfig; productId: number; onDone: (r: FixResult) => void }) {
  const [sku, setSku] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const save = async () => {
    setBusy(true);
    setError("");
    try { onDone(await runFix(cfg, productId, { kind: "sku", sku })); } catch (e) { setError(msg(e)); } finally { setBusy(false); }
  };
  return (
    <div className="mmi-wb-issue-form">
      <div className="mmi-wb-tr-fields">
        <label className="mmi-wb-tr-field">
          <span className="mmi-wb-label">SKU</span>
          <input type="text" className="mmi-wb-issue-sku" value={sku} maxLength={100} autoFocus onChange={(e) => setSku(e.target.value)} onKeyDown={(e) => { if (e.key === "Enter") void save(); }} />
        </label>
        <BusyButton className="button button-primary mmi-wb-issue-save" busy={busy} icon="saved" label="Save SKU" busyLabel="Saving…" disabled={!sku.trim()} onClick={() => void save()} />
      </div>
      {error && <div className="notice notice-error inline"><p>{error}</p></div>}
    </div>
  );
}

/* ── Description phrase ────────────────────────────────────────────────── */

export function DescribeFix({ cfg, productId, onDone }: { cfg: TitleRulesConfig; productId: number; onDone: (r: FixResult) => void }) {
  const [ctx, setCtx] = useState<DescribeContext | null>(null);
  const [block, setBlock] = useState(0);
  const [phrase, setPhrase] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  useEffect(() => { fixContext<DescribeContext>(cfg, productId, "describe").then(setCtx).catch((e) => setError(msg(e))); }, [cfg, productId]);

  // Words selected inside a text become the phrase, and that text its source.
  const take = (index: number) => {
    const s = window.getSelection()?.toString().trim() ?? "";
    if (s) { setPhrase(s); setBlock(index); }
  };
  const save = async () => {
    setBusy(true);
    setError("");
    try { onDone(await runFix(cfg, productId, { kind: "describe", phrase, block })); } catch (e) { setError(msg(e)); } finally { setBusy(false); }
  };

  if (!ctx) return <div className="mmi-wb-issue-form">{error ? <div className="notice notice-error inline"><p>{error}</p></div> : <p className="description"><span className="mmi-loading" aria-hidden="true" /> Loading the product’s texts…</p>}</div>;
  if (!ctx.blocks.length) return <div className="mmi-wb-issue-form"><p className="description">No maker or supplier text is stored for this product yet, so there is nothing to pick a description from. Give it a maker page (Maker pages), or edit the title.</p></div>;

  return (
    <div className="mmi-wb-issue-form mmi-wb-describe-fix">
      <p className="description">Select the words that say what it is (“multiband dynamics plugin”) in one of these texts. They must be its words, word for word.</p>
      {ctx.blocks.map((b) => (
        <div key={b.index} className={`mmi-wb-copy-block${block === b.index && phrase ? " is-source" : ""}`} onMouseUp={() => take(b.index)} onKeyUp={() => take(b.index)}>
          <div className="mmi-wb-label">{b.label}{b.url && <> · <a href={b.url} target="_blank" rel="noreferrer">page</a></>}</div>
          <div className="mmi-wb-copy-text" tabIndex={0}>{b.text}</div>
        </div>
      ))}
      <div className="mmi-wb-tr-fields">
        <label className="mmi-wb-tr-field mmi-wb-tr-grow">
          <span className="mmi-wb-label">Description for the title</span>
          <input type="text" className="mmi-wb-issue-phrase" value={phrase} maxLength={120} onChange={(e) => setPhrase(e.target.value)} />
        </label>
        {ctx.blocks.length > 1 && (
          <label className="mmi-wb-tr-field">
            <span className="mmi-wb-label">From</span>
            <select value={block} onChange={(e) => setBlock(Number(e.target.value))}>{ctx.blocks.map((b) => <option key={b.index} value={b.index}>{b.label}</option>)}</select>
          </label>
        )}
        <BusyButton className="button button-primary mmi-wb-issue-save" busy={busy} icon="saved" label="Use it" busyLabel="Saving…" disabled={!phrase.trim()} onClick={() => void save()} />
      </div>
      {error && <div className="notice notice-error inline"><p>{error}</p></div>}
    </div>
  );
}

/* ── Pictures, weight and size, GTIN (the results table's health checks) ── */

export function PictureFix({ cfg, productId, gallery, onDone }: { cfg: TitleRulesConfig; productId: number; gallery: boolean; onDone: (r: FixResult) => void }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const choose = () => {
    setError("");
    pickImages(gallery ? "Gallery pictures" : "Featured picture", gallery ? "Add to the gallery" : "Use as the featured picture", gallery)
      .then(async (a) => {
        if (!a.length) return;
        setBusy(true);
        try { onDone(await runFix(cfg, productId, { kind: gallery ? "gallery" : "image", attachments: a.map((x) => x.id) })); } catch (e) { setError(msg(e)); } finally { setBusy(false); }
      })
      .catch((e) => setError(msg(e)));
  };
  return (
    <div className="mmi-wb-issue-form">
      <BusyButton className="button button-primary" busy={busy} icon="format-image" label={gallery ? "Choose gallery pictures…" : "Choose the featured picture…"} busyLabel="Saving…" onClick={choose} />
      {error && <div className="notice notice-error inline"><p>{error}</p></div>}
    </div>
  );
}

export function PropsFix({ cfg, productId, fields, onDone }: { cfg: TitleRulesConfig; productId: number; fields: ("weight" | "length" | "width" | "height" | "gtin")[]; onDone: (r: FixResult) => void }) {
  const [v, setV] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const label: Record<string, string> = { weight: "Weight", length: "Length", width: "Width", height: "Height", gtin: "GTIN (UPC, EAN or ISBN)" };
  const save = async () => {
    setBusy(true);
    setError("");
    try { onDone(await runFix(cfg, productId, { kind: "props", props: v })); } catch (e) { setError(msg(e)); } finally { setBusy(false); }
  };
  return (
    <div className="mmi-wb-issue-form">
      <div className="mmi-wb-tr-fields">
        {fields.map((f) => (
          <label key={f} className="mmi-wb-tr-field">
            <span className="mmi-wb-label">{label[f]}</span>
            <input type="text" inputMode={f === "gtin" ? "numeric" : "decimal"} value={v[f] ?? ""} onChange={(e) => setV({ ...v, [f]: e.target.value })} />
          </label>
        ))}
        <BusyButton className="button button-primary mmi-wb-issue-save" busy={busy} icon="saved" label="Save" busyLabel="Saving…" disabled={!Object.values(v).some((x) => x.trim())} onClick={() => void save()} />
      </div>
      {error && <div className="notice notice-error inline"><p>{error}</p></div>}
    </div>
  );
}
