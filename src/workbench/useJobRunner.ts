/**
 * Drives an apply or undo job one batch per request until it finishes.
 *
 * The server holds all progress (Product_Workbench::run_batch()/undo_batch()),
 * so no request runs long, a closed tab leaves a resumable job instead of a
 * half-known state, and Stop simply stops asking and records where it stopped.
 */
import { useCallback, useEffect, useRef, useState } from "react";
import { ApiError, api } from "./api";
import { MESSAGES, fill, num } from "./messages";
import { finishMessage, progressOf, runFinished, type RunKind } from "./logic";
import type { BatchResponse, Job } from "./types";

/** Wait before asking again when the server says another batch holds the job's lock. */
export const BUSY_RETRY_MS = 1500;

export interface RunProgress { kind: RunKind; pct: number; text: string }
export interface RunResult { text: string; ok: boolean }

const sleep = (ms: number) => new Promise((r) => window.setTimeout(r, ms));

/** A placeholder job for Undo/Resume clicks: the first reply fills in the real numbers. */
export const jobStub = (id: string): Job => ({
  id, created_at: "", user: "", action: "", summary: "", filter_label: "", total: 0, cursor: 0, changed: 0,
  unchanged: 0, failed: 0, skipped: 0, skip_locked: false, add_lock: false, status: "running", undo_cursor: 0, undone: 0, conflicts: 0,
});

export function useJobRunner(onFinish: (r: RunResult) => void) {
  const [running, setRunning] = useState<string | null>(null);
  const [progress, setProgress] = useState<RunProgress | null>(null);
  const stopRequested = useRef(false);
  const alive = useRef(true);
  const onFinishRef = useRef(onFinish);
  onFinishRef.current = onFinish;

  useEffect(() => () => { alive.current = false; }, []);

  const run = useCallback(async (job: Job, kind: RunKind, resume = false) => {
    stopRequested.current = false;
    setRunning(job.id);
    setProgress({ kind, ...progressOf(job, kind) });
    const end = (r: RunResult) => {
      if (!alive.current) return;
      setRunning(null);
      setProgress(null);
      onFinishRef.current(r);
    };

    let first = true;
    for (;;) {
      if (!alive.current) return; // the page went away; the job waits on the server, resumable
      if (stopRequested.current) {
        const stopped = await api.stop(job.id).then((d) => d.job).catch(() => job);
        end({ text: fill(MESSAGES.STOPPED, { cursor: num(stopped.cursor), total: num(stopped.total) }), ok: true });
        return;
      }
      let reply: BatchResponse;
      try {
        reply = kind === "undo" ? await api.undo(job.id) : await api.run(job.id, resume && first);
      } catch (e) {
        const err = e as ApiError;
        end({ text: err.kind === "server" ? err.message : fill(MESSAGES.RUN_SAVED, { message: err.message }), ok: false });
        return;
      }
      first = false;
      job = reply.job;
      if (alive.current) setProgress({ kind, ...progressOf(job, kind) });
      if (runFinished(job, kind)) { end(finishMessage(job, kind)); return; }
      await sleep(reply.busy ? BUSY_RETRY_MS : 0);
    }
  }, []);

  const stop = useCallback(() => { stopRequested.current = true; }, []);

  return { running, progress, run, stop };
}
