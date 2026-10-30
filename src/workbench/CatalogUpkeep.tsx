/**
 * Catalog upkeep (was Catalog Maintenance's fixed operations): the steps
 * every catalog run takes, in order, with the switches, the schedule, the
 * last run and its health, and Run upkeep now.
 *
 * A run is driven one phase per request through the same endpoints the
 * scheduled run's lock and state use (CatalogUpdateController.php). Leaving
 * the page mid-run tells the server, so the lock is released at once.
 */
import { useCallback, useEffect, useRef, useState } from "react";
import { ApiError, api } from "./api";
import { page } from "./globals";
import { MESSAGES, fill, num, plural } from "./messages";
import type { PhaseResult, RunPhase, Upkeep } from "./types";
import { BusyButton, Section } from "./ui";

type StepState = { state: "pending" | "running" | "done" | "skipped" | "error"; detail: string };

/** Minute choices for an hourly schedule ("00:MM", as the Schedules panel stores them). */
const MINUTES = Array.from({ length: 12 }, (_, i) => `00:${String(i * 5).padStart(2, "0")}`);

function phaseDetail(r: PhaseResult): string {
  if (r.skipped) return r.message || "skipped";
  const secs = r.duration_ms != null ? `${(r.duration_ms / 1000).toFixed(1)} s` : "";
  return [r.message, r.items_processed != null && !r.message ? `${num(r.items_processed)} items` : "", secs].filter(Boolean).join(" · ");
}

export function CatalogUpkeep({ onRunFinished }: { onRunFinished: () => void }) {
  const [up, setUp] = useState<Upkeep | null>(null);
  const [message, setMessage] = useState<{ text: string; kind: "" | "ok" | "error" }>({ text: "", kind: "" });
  const [saving, setSaving] = useState(false);
  const [run, setRun] = useState<{ phases: RunPhase[]; steps: StepState[]; title: string } | null>(null);
  const runId = useRef<string | null>(null);

  const load = useCallback(() => api.upkeep().then(setUp).catch((e: ApiError) => setMessage({ text: e.message, kind: "error" })), []);
  useEffect(() => { void load(); }, [load]);

  // Leaving mid-run: release the shared lock now instead of after its timeout.
  useEffect(() => {
    const abort = () => {
      const id = runId.current;
      const w = page();
      if (!id || !navigator.sendBeacon) return;
      const data = new FormData();
      data.append("action", "mmi_catalog_finish_run");
      data.append("run_id", id);
      data.append("aborted", "1");
      data.append("nonce", w.mmiRuleBuilder?.nonce ?? "");
      navigator.sendBeacon(w.mmiRuleBuilder?.ajaxUrl ?? "/wp-admin/admin-ajax.php", data);
    };
    window.addEventListener("pagehide", abort);
    return () => window.removeEventListener("pagehide", abort);
  }, []);

  const fail = (e: unknown) => setMessage({ text: (e as ApiError).message, kind: "error" });

  // Switches move at once; load() afterwards shows what was saved (or puts them back).
  const setEnabled = (on: boolean) => {
    setUp((u) => u && { ...u, enabled: on });
    setSaving(true);
    api.upkeepEnable(on).then(load).catch((e) => { fail(e); void load(); }).finally(() => setSaving(false));
  };

  const setSwitch = (key: string, on: boolean) => {
    if (!up) return;
    const switches = Object.fromEntries(up.steps.filter((s) => s.switch).map((s) => [s.switch, s.switch === key ? on : !!s.on]));
    setUp({ ...up, steps: up.steps.map((s) => (s.switch === key ? { ...s, on } : s)) });
    setSaving(true);
    api.upkeepSwitches(switches).then(load).catch((e) => { fail(e); void load(); }).finally(() => setSaving(false));
  };

  const setSchedule = (frequency: string, time: string) => {
    setUp((u) => u && { ...u, schedule: { ...u.schedule, frequency, time } });
    setSaving(true);
    api.upkeepSchedule(frequency, time)
      .then(() => api.upkeep())
      .then((u) => { setUp(u); setMessage({ text: fill(MESSAGES.SCHEDULE_SAVED, { next: u.schedule.next }), kind: "ok" }); })
      .catch((e) => { fail(e); void load(); }).finally(() => setSaving(false));
  };

  const runNow = async () => {
    if (!up || run || !window.confirm(MESSAGES.UPKEEP_CONFIRM)) return;
    setMessage({ text: "", kind: "" });
    let start: { run_id: string; phases: RunPhase[] };
    try {
      start = await api.catalogStart();
    } catch (e) {
      fail(e);
      return;
    }
    runId.current = start.run_id;
    const phases = start.phases;
    const steps: StepState[] = phases.map(() => ({ state: "pending", detail: "" }));
    const show = (title: string) => setRun({ phases, steps: [...steps], title });
    for (let i = 0; i < phases.length; i++) {
      steps[i] = { state: "running", detail: "" };
      show(fill(MESSAGES.UPKEEP_RUNNING, { done: i, total: phases.length }));
      let r: PhaseResult;
      try {
        r = await api.catalogPhase(start.run_id, i);
      } catch (e) {
        steps[i] = { state: "error", detail: (e as ApiError).message };
        show(fill(MESSAGES.UPKEEP_STOPPED, { label: phases[i]!.label, message: (e as ApiError).message }));
        await api.catalogFinish(start.run_id, true).catch(() => undefined);
        runId.current = null;
        setMessage({ text: fill(MESSAGES.UPKEEP_STOPPED, { label: phases[i]!.label, message: (e as ApiError).message }), kind: "error" });
        setRun(null);
        void load();
        return;
      }
      steps[i] = { state: r.skipped ? "skipped" : r.error ? "error" : "done", detail: r.error ? r.error : phaseDetail(r) };
    }
    show(MESSAGES.UPKEEP_FINALIZING);
    const done = await api.catalogFinish(start.run_id).catch(fail) as { total_processed?: number } | undefined;
    runId.current = null;
    setRun(null);
    if (done) {
      const n = done.total_processed ?? 0;
      setMessage({ text: fill(MESSAGES.UPKEEP_DONE, { count: num(n), s: plural(n) }), kind: "ok" });
    }
    void load();
    onRunFinished();
  };

  const freq = up?.schedule.frequency ?? "";
  const busyElsewhere = up?.running && !run;

  return (
    <Section id="mmi-wb-upkeep" icon="admin-tools" title="Catalog upkeep"
      desc="The steps every catalog run takes, in this order, on its schedule and 5 minutes after each data fetch. Feed sync steps come from the data sources (Import tab); Apply standing rules runs the rules above"
      actions={up && <>
        <label className="mmi-wb-upkeep-switch" title="Off: the schedule and Run upkeep now both leave the catalog alone">
          <span className="mmi-toggle-switch">
            <input type="checkbox" id="mmi-wb-upkeep-enabled" checked={up.enabled} disabled={saving || !!run} onChange={(e) => setEnabled(e.target.checked)} />
            <span className="mmi-toggle-slider" />
          </span>
          <span>Enabled</span>
        </label>
        <BusyButton id="mmi-wb-upkeep-run" className="button button-primary" busy={!!run} icon="update" label="Run upkeep now" busyLabel="Running…"
          disabled={!up.enabled || !!busyElsewhere || saving} onClick={() => void runNow()} />
      </>}>
      {!up && <p className="mmi-wb-empty">{MESSAGES.UPKEEP_LOADING}</p>}
      {up && (
        <>
          <div className="mmi-toolbar mmi-wb-upkeep-schedule">
            <label>Runs{" "}
              <select id="mmi-wb-upkeep-frequency" value={freq} disabled={saving} onChange={(e) => setSchedule(e.target.value, up.schedule.time)}>
                {Object.entries(up.schedule.options).map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
            </label>
            {freq === "hourly" && (
              <label>at{" "}
                <select id="mmi-wb-upkeep-time" value={MINUTES.includes(up.schedule.time) ? up.schedule.time : "00:00"} disabled={saving}
                  title="Minute past each hour" onChange={(e) => setSchedule(freq, e.target.value)}>
                  {MINUTES.map((m) => <option key={m} value={m}>:{m.slice(3)}</option>)}
                </select>
              </label>
            )}
            {freq !== "hourly" && freq !== "disabled" && (
              <label>at{" "}
                <input type="time" step={300} id="mmi-wb-upkeep-time" defaultValue={up.schedule.time} disabled={saving}
                  title="Time of day (site timezone)" onBlur={(e) => { if (e.target.value && e.target.value !== up.schedule.time) setSchedule(freq, e.target.value); }} />
              </label>
            )}
            <span className="mmi-wb-upkeep-next">Next run: {up.schedule.next}</span>
            <span className="mmi-wb-upkeep-last">
              {up.last_run
                ? fill(MESSAGES.UPKEEP_LAST, { ago: up.last_run.ago, duration: up.last_run.duration ? ` (took ${up.last_run.duration})` : "", count: num(up.last_run.processed), s: plural(up.last_run.processed) })
                : MESSAGES.UPKEEP_NEVER}
            </span>
          </div>
          {!up.enabled && <div className="notice notice-warning inline"><p>{MESSAGES.UPKEEP_OFF}</p></div>}
          {up.health.level !== "ok" && up.health.level !== "disabled" && (
            <div className={`notice notice-${up.health.level === "error" ? "error" : "warning"} inline`} id="mmi-wb-upkeep-health">
              <p><strong>{up.health.text}.</strong> {up.health.detail}</p>
            </div>
          )}
          {busyElsewhere && <div className="notice notice-info inline"><p>{fill(MESSAGES.UPKEEP_BUSY, { when: up.running!.started_at })}</p></div>}
          <div className="mmi-table-scroll-wrapper">
            <table className="wp-list-table widefat striped mmi-wb-upkeep-table" id="mmi-wb-upkeep-steps">
              <thead><tr><th>Step</th><th>Kind</th><th>{run ? "This run" : "Status"}</th></tr></thead>
              <tbody>
                {(run ? run.phases.map((p, i) => ({ p, s: run.steps[i]! })) : up.steps.map((p) => ({ p, s: null }))).map(({ p, s }, i) => {
                  const step = up.steps.find((u) => u.label === p.label);
                  return (
                    <tr key={`${p.phase}-${i}`} className={s ? `mmi-wb-step-${s.state}` : ""}>
                      <td><strong>{p.label}</strong>{step?.about && <span className="mmi-wb-step-about">{step.about}</span>}</td>
                      <td>{step?.kind ?? ""}</td>
                      <td>
                        {s && (
                          <span className="mmi-wb-step-state">
                            {s.state === "running" ? <span className="mmi-loading" aria-hidden="true" /> : null}
                            {s.state === "pending" ? "Waiting" : s.state === "running" ? "Running…" : s.detail || (s.state === "done" ? "Done" : s.state)}
                          </span>
                        )}
                        {!s && step?.switch && (
                          <label className="mmi-toggle-switch" title={step.on ? "On" : "Off"}>
                            <input type="checkbox" checked={!!step.on} disabled={saving} aria-label={`${step.label} on`}
                              onChange={(e) => setSwitch(step.switch, e.target.checked)} />
                            <span className="mmi-toggle-slider" />
                          </label>
                        )}
                        {!s && step && !step.switch && (
                          <span className={step.skipped ? "mmi-wb-none" : "mmi-wb-step-active"}>
                            {step.skipped ? fill(MESSAGES.STEP_SKIPPED, { reason: step.skip_reason }) : MESSAGES.STEP_ACTIVE}
                          </span>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          {run && <p className="mmi-wb-upkeep-progress" role="status">{run.title}</p>}
        </>
      )}
      <div className={`mmi-wb-message${message.kind === "error" ? " is-error" : message.kind === "ok" ? " is-success" : ""}`} id="mmi-wb-upkeep-message" role="status">
        {message.text}
      </div>
    </Section>
  );
}
