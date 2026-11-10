/**
 * How Product Data writes to products, set where its proposals are reviewed
 * (moved from the old Product Data page, migration phase 8):
 *   TitleWriting    in Title proposals: write titles on or off, auto-apply,
 *                   its confidence floor and the batch size (POST /settings);
 *   EnrichWriting   in Description and picture proposals: write to products,
 *                   and which sources write without review (POST
 *                   /enrich/settings, /enrich/rules);
 *   ProductDataRuns what the background jobs did last (GET /summary).
 * Starting to write asks once, in place; stopping doesn't.
 */
import { useCallback, useEffect, useState, type ReactNode } from "react";
import { ApiError } from "./api";
import { rest, type TitleRulesConfig } from "./titleRulesApi";
import {
  autoGate, autoRules, checkNumbers, enrichToday, liveSources, runRows, sourceRows, sourcesState, titlesState,
  type EnrichWriteSummary, type TitleSummary,
} from "./writingApi";
import { Dashicon, Section } from "./ui";

const OPEN_KEY = "mmi_wb_writing_open";
const msg = (e: unknown) => (e instanceof ApiError ? e.message : String(e));

function readOpen(key: string): boolean {
  try { return !!JSON.parse(window.localStorage.getItem(OPEN_KEY) ?? "{}")[key]; } catch { return false; }
}
function saveOpen(key: string, open: boolean): void {
  try {
    const all = JSON.parse(window.localStorage.getItem(OPEN_KEY) ?? "{}");
    window.localStorage.setItem(OPEN_KEY, JSON.stringify({ ...all, [key]: open }));
  } catch { /* private browsing: the choice lasts this visit */ }
}

/** A closed-by-default settings box whose summary line says the current state. */
function SettingsBox({ id, state, children }: { id: string; state: string; children: ReactNode }) {
  const [open, setOpen] = useState(() => readOpen(id));
  return (
    <details className="mmi-wb-writing" id={`mmi-wb-settings-${id}`} open={open}
      onToggle={(e) => { const o = (e.target as HTMLDetailsElement).open; setOpen(o); saveOpen(id, o); }}>
      <summary><Dashicon name="admin-generic" /> Settings <span className="mmi-wb-writing-state">{state}</span></summary>
      <div className="mmi-wb-writing-body">{children}</div>
    </details>
  );
}

function Switch({ id, on, label, disabled, onChange }: { id: string; on: boolean; label: string; disabled?: boolean; onChange: (on: boolean) => void }) {
  return (
    <label className="mmi-wb-writing-switch" htmlFor={id}>
      <span className="mmi-toggle-switch">
        <input type="checkbox" id={id} checked={on} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />
        <span className="mmi-toggle-slider" />
      </span>{" "}{label}
    </label>
  );
}

/** "Start writing …?" asked in place, under the switch. */
function Ask({ text, yes, busy, onYes, onNo }: { text: string; yes: string; busy: boolean; onYes: () => void; onNo: () => void }) {
  return (
    <div className="mmi-wb-writing-ask" role="group">
      <span>{text}</span>{" "}
      <button type="button" className="button button-small button-primary" disabled={busy} onClick={onYes}>{yes}</button>{" "}
      <button type="button" className="button-link" disabled={busy} onClick={onNo}>Cancel</button>
    </div>
  );
}

const Said = ({ said }: { said: { text: string; ok: boolean } | null }) =>
  said ? <div className={`notice notice-${said.ok ? "success" : "error"} inline`} role="status"><p>{said.text}</p></div> : null;

/** Title proposals' writing settings. onChange: the queue's counts follow (the floor moves what would auto-apply). */
export function TitleWriting({ cfg, onChange, onShowAuto }: { cfg: TitleRulesConfig; onChange: () => void; onShowAuto: () => void }) {
  const [s, setS] = useState<TitleSummary | null>(null);
  const [batch, setBatch] = useState("");
  const [floor, setFloor] = useState("");
  const [asking, setAsking] = useState(false);
  const [busy, setBusy] = useState(false);
  const [said, setSaid] = useState<{ text: string; ok: boolean } | null>(null);

  const take = useCallback((d: TitleSummary) => {
    setS(d);
    setBatch(String(d.apply.batch_size));
    setFloor(String(d.apply.min_confidence));
  }, []);
  useEffect(() => { rest<TitleSummary>(cfg, "GET", "summary").then(take).catch((e) => setSaid({ text: msg(e), ok: false })); }, [cfg, take]);

  const save = async (patch: Partial<TitleSummary["apply"]>) => {
    if (!s) return;
    const nums = checkNumbers(batch, floor);
    if (!nums) { setSaid({ text: "Titles per batch is 1 to 50; the confidence floor is 50 to 100.", ok: false }); return; }
    setBusy(true);
    try {
      take(await rest<TitleSummary>(cfg, "POST", "settings", { enabled: s.apply.enabled, auto: s.apply.auto, ...nums, ...patch }));
      setSaid({ text: "Saved.", ok: true });
      setAsking(false);
      onChange();
    } catch (e) { setSaid({ text: msg(e), ok: false }); } finally { setBusy(false); }
  };

  if (!s) return <SettingsBox id="titles" state={said ? "" : "Loading…"}><Said said={said} /></SettingsBox>;
  const a = s.apply;
  const { may } = autoRules(s.rules);
  return (
    <SettingsBox id="titles" state={titlesState(a)}>
      <p className="description">Accepted and edited titles are written in batches two minutes apart. The URL slug never changes, the title is locked against imports, and the old title is kept so it can be put back.</p>
      <Said said={said} />
      <div className="mmi-wb-writing-row">
        <Switch id="mmi-wb-writing-titles" on={a.enabled} label="Write titles to products" disabled={busy}
          onChange={(on) => (on ? setAsking(true) : void save({ enabled: false }))} />
        <Switch id="mmi-wb-writing-auto" on={a.auto} label="Also write unreviewed proposals that pass every auto-apply gate" disabled={busy}
          onChange={(on) => void save({ auto: on })} />
      </div>
      {asking && <Ask text="Start writing titles to products?" yes="Start writing" busy={busy} onYes={() => void save({ enabled: true })} onNo={() => setAsking(false)} />}
      <div className="mmi-wb-writing-row">
        <label>Auto-apply confidence floor <input type="number" id="mmi-wb-writing-floor" min={50} max={100} value={floor} onChange={(e) => setFloor(e.target.value)} /></label>
        <label>Titles per batch <input type="number" id="mmi-wb-writing-batch" min={1} max={50} value={batch} onChange={(e) => setBatch(e.target.value)} /></label>
        <button type="button" className="button" id="mmi-wb-writing-save" disabled={busy} onClick={() => void save({})}>Save numbers</button>
      </div>
      <p className="description" id="mmi-wb-writing-gate">
        {autoGate(s.rules)}{" "}
        {may > 0 && (s.auto
          ? <button type="button" className="button-link" id="mmi-wb-writing-show-auto" onClick={onShowAuto}>Show the {s.auto} that would auto-apply now</button>
          : "None would auto-apply now.")}
        {" "}Which rules may auto-apply is set in Title rules.
      </p>
    </SettingsBox>
  );
}

/** Description and picture proposals' writing settings. */
export function EnrichWriting({ cfg, onChange }: { cfg: TitleRulesConfig; onChange: () => void }) {
  const [e, setE] = useState<EnrichWriteSummary | null>(null);
  const [asking, setAsking] = useState(false);
  const [busy, setBusy] = useState(false);
  const [said, setSaid] = useState<{ text: string; ok: boolean } | null>(null);

  useEffect(() => { rest<EnrichWriteSummary>(cfg, "GET", "enrich/summary").then(setE).catch((x) => setSaid({ text: msg(x), ok: false })); }, [cfg]);

  const save = async (path: string, body: Record<string, unknown>) => {
    setBusy(true);
    try {
      setE(await rest<EnrichWriteSummary>(cfg, "POST", path, body));
      setSaid({ text: "Saved.", ok: true });
      setAsking(false);
      onChange();
    } catch (x) { setSaid({ text: msg(x), ok: false }); } finally { setBusy(false); }
  };

  if (!e) return <SettingsBox id="enrich" state={said ? "" : "Loading…"}><Said said={said} /></SettingsBox>;
  const on = !!e.settings?.enabled;
  return (
    <SettingsBox id="enrich" state={sourcesState(e)}>
      <p className="description">Products with under 200 characters of description get the maker’s own text, word for word. A source switched on writes without review while “Write to products” is on, and every written description can be put back. Picture picks are offered only where a person’s choice helps.</p>
      <Said said={said} />
      <div className="mmi-wb-writing-list">
        <Switch id="mmi-wb-writing-enrich" on={on} label="Write to products" disabled={busy}
          onChange={(yes) => (yes ? setAsking(true) : void save("enrich/settings", { enabled: false }))} />
        {asking && <Ask text="Start writing accepted rows (and rows from sources switched on) to products?" yes="Start writing" busy={busy}
          onYes={() => void save("enrich/settings", { enabled: true })} onNo={() => setAsking(false)} />}
        {liveSources(e).map(([id, r]) => (
          <Switch key={id} id={`mmi-wb-writing-src-${id.replace(/[^a-z0-9]+/gi, "-")}`} on={r.approved} disabled={busy}
            label={`Write without review: ${r.label} (${sourceRows(e, id).toLocaleString("en-US")} rows)`}
            onChange={(yes) => void save("enrich/rules", { rule: id, approved: yes })} />
        ))}
      </div>
      <p className="description" id="mmi-wb-writing-today">{enrichToday(e)}</p>
    </SettingsBox>
  );
}

/** What Product Data's background jobs did last. */
export function ProductDataRuns({ cfg }: { cfg: TitleRulesConfig }) {
  const [s, setS] = useState<TitleSummary | null>(null);
  const [err, setErr] = useState("");
  const load = useCallback(() => { setErr(""); rest<TitleSummary>(cfg, "GET", "summary").then(setS).catch((e) => setErr(msg(e))); }, [cfg]);
  useEffect(() => { load(); }, [load]);
  return (
    <Section id="mmi-wb-pd-runs" icon="clock" title="Product Data runs"
      desc="What the background jobs did last: titles composed and written, maker pages read, descriptions, links found and picks"
      actions={<button type="button" className="button" id="mmi-wb-pd-runs-refresh" onClick={load}><Dashicon name="update" /> Refresh</button>}>
      {err && <div className="notice notice-error inline" role="status"><p>{err}</p></div>}
      {!s && !err && <p className="description">Loading…</p>}
      {s && (
        <table className="widefat striped mmi-wb-runs" id="mmi-wb-pd-runs-table">
          <tbody>{runRows(s).map(([k, v]) => <tr key={k}><th scope="row">{k}</th><td>{v}</td></tr>)}</tbody>
        </table>
      )}
    </Section>
  );
}
