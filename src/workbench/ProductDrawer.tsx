/**
 * The product drawer: everything about one product beside the list, decided in place (Needs a decision).
 *
 * Parts, each shown when it has something: the header (‹ › step through the list, close), what waits, the
 * issues with their fixes (IssueFixes.tsx, as in Title proposals' rows), the title proposal (Accept, Reject,
 * Edit), and the categories and brand with what the supplier sends. After any decision the drawer reads the
 * product again (GET products/{id}/workbench) and hands the reply to the list, which redraws that row only.
 *
 * Not a modal: nothing opens on top of it but WordPress's media library. Escape closes it (an open fix panel
 * takes Escape first).
 */
import { useCallback, useEffect, useRef, useState, type ReactNode } from "react";
import { ApiError } from "./api";
import { BrandFix, CategoryFix, IssueChips, IssuePanel } from "./IssueFixes";
import type { FixResult } from "./issuesApi";
import { refreshCounts } from "./nav";
import { STATUS_LABEL, confKind, target, wordDiff, type DiffPart } from "./proposalsApi";
import { rest, type TitleRulesConfig } from "./titleRulesApi";
import { BusyButton, Dashicon } from "./ui";
import { announceChanged, OPEN_TITLE, POST_STATUS_LABEL, STOCK_LABEL, type DrawerProduct } from "./waitingApi";

interface Note { text: string; kind: "ok" | "error" }

const msg = (e: unknown) => (e instanceof ApiError ? e.message : e instanceof Error ? e.message : String(e));

const Diff = ({ parts }: { parts: DiffPart[] }) => (
  <>{parts.map((p, i) => (p.kind === "same" ? <span key={i}>{p.text}</span> : <span key={i} className={p.kind === "del" ? "mmi-wb-before" : "mmi-wb-after"}>{p.text}</span>))}</>
);

function Part({ name, title, tone, aside, children }: { name: string; title: string; tone?: string; aside?: ReactNode; children: ReactNode }) {
  return (
    <section className={`mmi-wb-dr-part${tone ? ` is-${tone}` : ""}`} data-part={name} aria-label={title}>
      <div className="mmi-wb-dr-part-head"><h3>{title}</h3>{aside}</div>
      {children}
    </section>
  );
}

export function ProductDrawer({ cfg, id, prev, next, onStep, onClose, onChanged }: {
  cfg: TitleRulesConfig;
  id: number;
  /** The products before and after it in the list (null at an end). */
  prev: number | null;
  next: number | null;
  onStep: (id: number) => void;
  onClose: () => void;
  /** The product as it is after a decision: the list redraws its row (or drops it when nothing waits). */
  onChanged: (p: DrawerProduct) => void;
}) {
  const [p, setP] = useState<DrawerProduct | null>(null);
  const [failed, setFailed] = useState("");
  const [note, setNote] = useState<Note | null>(null);
  const [issue, setIssue] = useState<string | null>(null);
  const [terms, setTerms] = useState<"cats" | "brands" | null>(null);
  const [editing, setEditing] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const box = useRef<HTMLElement>(null);
  const seq = useRef(0);

  const load = useCallback(async (after?: Note) => {
    const mine = ++seq.current;
    try {
      const d = await rest<DrawerProduct>(cfg, "GET", `products/${id}/workbench`);
      if (mine !== seq.current) return null;
      setP(d);
      setFailed("");
      if (after) setNote(after);
      return d;
    } catch (e) {
      if (mine === seq.current) setFailed(msg(e));
      return null;
    }
  }, [cfg, id]);

  // A new product: start clean.
  useEffect(() => {
    setP(null); setNote(null); setIssue(null); setTerms(null); setEditing(null); setFailed("");
    void load();
    box.current?.focus({ preventScroll: true });
  }, [load]);

  /** After a decision: read the product again, say what happened, and tell the list and the menu. */
  const changed = async (after: Note) => {
    setIssue(null); setTerms(null); setEditing(null);
    const d = await load(after);
    if (d) onChanged(d);
    announceChanged(id);
    refreshCounts();
  };
  const fixed = (r: FixResult) => void changed({ text: r.message, kind: "ok" });

  const decide = async (action: "accept" | "reject") => {
    setBusy(action);
    try {
      await rest(cfg, "POST", "decide", { action, ids: [id] });
      await changed({ text: action === "accept" ? "Title accepted: it is written in the next batch." : "Title rejected: the product keeps its title.", kind: "ok" });
    } catch (e) { setNote({ text: msg(e), kind: "error" }); } finally { setBusy(null); }
  };
  const saveTitle = async () => {
    if (editing === null || !editing.trim()) return;
    setBusy("edit");
    try {
      await rest(cfg, "POST", `proposals/${id}/edit`, { title: editing.trim(), rules: [] });
      await changed({ text: "Your title is saved and goes out in the next batch.", kind: "ok" });
    } catch (e) { setNote({ text: msg(e), kind: "error" }); } finally { setBusy(null); }
  };

  // ‹ › and J / K step through the list; Escape closes. Not while typing.
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const t = e.target as HTMLElement | null;
      if (t && (t.closest("input, textarea, select, [contenteditable=true]") || t.isContentEditable)) return;
      if (e.metaKey || e.ctrlKey || e.altKey) return;
      if (!box.current?.isConnected || box.current.closest("[hidden]")) return; // its section isn't on screen
      if (e.key === "Escape") { e.preventDefault(); onClose(); }
      else if ((e.key === "j" || e.key === "J") && next !== null) { e.preventDefault(); onStep(next); }
      else if ((e.key === "k" || e.key === "K") && prev !== null) { e.preventDefault(); onStep(prev); }
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [next, prev, onClose, onStep]);

  const prop = p?.proposal ?? null;
  const open = !!prop && OPEN_TITLE.includes(prop.status);
  const openIssue = p?.issues.find((i) => i.key === issue) ?? null;
  const diff = prop ? wordDiff(prop.current, target(prop)) : null;
  const [st, sk] = prop ? STATUS_LABEL[prop.status] ?? [prop.status, ""] : ["", ""];

  return (
    <aside className="mmi-wb-drawer" id="mmi-wb-drawer" data-id={id} aria-label={p ? `Product ${id}: ${p.title}` : `Product ${id}`} tabIndex={-1} ref={box}>
      <div className="mmi-wb-dr-head">
        <div className="mmi-wb-dr-title">
          <div className="description">
            #{id}
            {p && <>{p.sku && <> · SKU {p.sku}</>} · {POST_STATUS_LABEL[p.status] ?? p.status}{p.stock && <> · {STOCK_LABEL[p.stock] ?? p.stock}</>}</>}
          </div>
          <h2>{p ? p.title : "Loading…"}</h2>
          {p && <div className="mmi-wb-dr-links"><a href={p.edit_url} target="_blank" rel="noreferrer">Edit product</a> · <a href={p.view_url} target="_blank" rel="noreferrer">View</a></div>}
        </div>
        <div className="mmi-wb-dr-nav">
          <button type="button" className="button button-small" aria-label="Previous product (K)" title="Previous product (K)" disabled={prev === null} onClick={() => prev !== null && onStep(prev)}>‹</button>
          <button type="button" className="button button-small" aria-label="Next product (J)" title="Next product (J)" disabled={next === null} onClick={() => next !== null && onStep(next)}>›</button>
          <button type="button" className="button-link mmi-wb-dr-close" aria-label="Close (Escape)" title="Close (Escape)" onClick={onClose}><Dashicon name="no-alt" /></button>
        </div>
      </div>

      {failed && <div className="notice notice-error inline"><p>{failed}</p></div>}
      {note && <div className={`notice notice-${note.kind === "error" ? "error" : "success"} inline mmi-wb-dr-note`} role="status"><p>{note.text}</p></div>}
      {!p && !failed && <p className="mmi-wb-dr-loading"><span className="mmi-loading" aria-hidden="true" /> Loading the product…</p>}

      {p && (
        <>
          <div className="mmi-wb-dr-waiting" aria-label="Waiting on you">
            {p.waiting.length
              ? p.waiting.map((w) => <span key={w.key} className={`mmi-badge ${w.tone}`}>{w.label}</span>)
              : <span className="description">Nothing waits on this product now.</span>}
          </div>

          {p.issues.length > 0 && (
            <Part name="issues" title="Issues" tone={p.issues.some((i) => i.tone === "error") ? "error" : "warning"}>
              <IssueChips issues={p.issues} open={issue} onOpen={(k) => { setIssue(k); setTerms(null); }} />
              {openIssue && (
                <IssuePanel cfg={cfg} productId={id} issue={openIssue} cats={p.cats.map((c) => c.id)}
                  onTitle={prop ? () => { setIssue(null); setEditing(target(prop)); } : undefined}
                  onDone={fixed} onClose={() => setIssue(null)} />
              )}
            </Part>
          )}

          {prop && (
            <Part name="title" title="Title" aside={<span className={`mmi-badge ${sk}`}>{st}</span>}>
              <div className="mmi-wb-dr-now"><span className="description">Now:</span> {diff ? <Diff parts={diff.before} /> : prop.current}</div>
              {target(prop) !== prop.current && (
                <div className="mmi-wb-dr-proposed"><span className="description">{prop.status === "edited" ? "Yours:" : "Proposed:"}</span> <strong>{diff ? <Diff parts={diff.after} /> : target(prop)}</strong></div>
              )}
              <div className="mmi-wb-dr-meta">
                <span className={`mmi-badge ${confKind(prop.confidence)}`} title="How sure the rules are">{prop.confidence}% sure</span>
                {prop.renamed && <span className="description"> The supplier renamed it: “{prop.renamed.from}” → “{prop.renamed.to}”.</span>}
                {prop.error && <span className="description"> {prop.error}</span>}
              </div>
              {editing !== null ? (
                <div className="mmi-wb-dr-edit">
                  <label className="screen-reader-text" htmlFor="mmi-wb-dr-title-input">Your title</label>
                  <input type="text" id="mmi-wb-dr-title-input" value={editing} autoFocus onChange={(e) => setEditing(e.target.value)}
                    onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); void saveTitle(); } if (e.key === "Escape") { e.stopPropagation(); setEditing(null); } }} />
                  <BusyButton className="button button-primary" busy={busy === "edit"} icon="yes" label="Save title" busyLabel="Saving…" disabled={busy !== null || !editing.trim()} onClick={() => void saveTitle()} />
                  <button type="button" className="button-link" onClick={() => setEditing(null)}>Cancel</button>
                </div>
              ) : open ? (
                <div className="mmi-wb-dr-actions">
                  <BusyButton className="button button-primary" busy={busy === "accept"} icon="yes" label="Accept" busyLabel="Accepting…" disabled={busy !== null} onClick={() => void decide("accept")} />
                  <button type="button" className="button" disabled={busy !== null} onClick={() => setEditing(target(prop))}><Dashicon name="edit" /> Edit</button>
                  <BusyButton busy={busy === "reject"} icon="no-alt" label="Reject" busyLabel="Rejecting…" disabled={busy !== null} onClick={() => void decide("reject")} />
                </div>
              ) : null}
            </Part>
          )}

          <Part name="terms" title="Categories & brand">
            <div className="mmi-wb-dr-term">
              <span className="mmi-wb-dr-term-name">Categories</span>
              <span>{p.cats.map((c) => c.name).join(", ") || <span className="description">None</span>}{p.locks.cats && <span title="Locked against supplier imports"> <Dashicon name="lock" /></span>}</span>
              <button type="button" className="button-link" aria-expanded={terms === "cats"} onClick={() => { setTerms(terms === "cats" ? null : "cats"); setIssue(null); }}>Change</button>
            </div>
            {p.feed.cats.map((f) => <div key={`${f.supplier}|${f.field}`} className="description mmi-wb-dr-feed">{f.label} sends “{f.value}”{f.mapped ? <>, mapped to {f.mapped.name}</> : ", not mapped"}</div>)}
            {terms === "cats" && <CategoryFix cfg={cfg} productId={id} current={p.cats.map((c) => c.id)} onDone={fixed} />}
            <div className="mmi-wb-dr-term">
              <span className="mmi-wb-dr-term-name">Brand</span>
              <span>{p.brands.map((b) => b.name).join(", ") || <span className="description">None</span>}{p.locks.brands && <span title="Locked against supplier imports"> <Dashicon name="lock" /></span>}</span>
              <button type="button" className="button-link" aria-expanded={terms === "brands"} onClick={() => { setTerms(terms === "brands" ? null : "brands"); setIssue(null); }}>Change</button>
            </div>
            {p.feed.brands.map((f) => <div key={`${f.supplier}|${f.field}`} className="description mmi-wb-dr-feed">{f.label} sends “{f.value}”{f.mapped ? <>, mapped to {f.mapped.name}</> : ", not mapped"}</div>)}
            {terms === "brands" && <BrandFix cfg={cfg} productId={id} onDone={fixed} />}
          </Part>
        </>
      )}
    </aside>
  );
}
