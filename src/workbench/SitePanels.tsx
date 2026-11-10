/**
 * A store's own panels in the Product Workbench (filter
 * mmi_pipeline_product_data_panels): a page section each, whose body the
 * store's script draws. The script registers itself as
 *   window.mmiWorkbenchPanels[id] = { mount(element) { … } }
 * and, if it loads after the Workbench, dispatches the window event
 * PANEL_READY_EVENT with the id. Each panel is mounted once; a panel whose
 * script never arrives says so instead of staying blank.
 */
import { useEffect, useRef, useState } from "react";
import type { PanelConfig } from "./types";
import { Section } from "./ui";

export const PANEL_READY_EVENT = "mmi-wb:panel-ready";

interface PanelApi { mount: (el: HTMLElement) => void }
type Registry = Record<string, PanelApi | undefined>;

const registry = (): Registry => ((window as unknown as { mmiWorkbenchPanels?: Registry }).mmiWorkbenchPanels ?? {});

function SitePanel({ panel }: { panel: PanelConfig }) {
  const body = useRef<HTMLDivElement>(null);
  const done = useRef(false);
  const [state, setState] = useState<"waiting" | "mounted" | "missing" | "failed">("waiting");

  useEffect(() => {
    const tryMount = (): boolean => {
      const api = registry()[panel.id];
      if (done.current || !api || !body.current) return done.current;
      done.current = true;
      try { api.mount(body.current); setState("mounted"); } catch (e) { setState("failed"); console.error(`Panel ${panel.id}:`, e); }
      return true;
    };
    if (tryMount()) return;
    const onReady = (e: Event) => { if ((e as CustomEvent<string>).detail === panel.id) tryMount(); };
    window.addEventListener(PANEL_READY_EVENT, onReady);
    const t = window.setTimeout(() => { if (!done.current) setState("missing"); }, 15000);
    return () => { window.removeEventListener(PANEL_READY_EVENT, onReady); window.clearTimeout(t); };
  }, [panel.id]);

  return (
    <Section id={`mmi-wb-panel-${panel.id}`} icon={panel.icon || "admin-generic"} title={panel.title} desc={panel.desc}>
      {state === "waiting" && <p className="description mmi-wb-panel-wait">Loading…</p>}
      {state === "missing" && <div className="notice notice-warning inline"><p>This panel’s script didn’t load, so there is nothing to show. Reload the page; if it stays, the plugin that adds it needs a look.</p></div>}
      {state === "failed" && <div className="notice notice-error inline"><p>This panel stopped with an error; the browser console has the details.</p></div>}
      <div ref={body} className="mmi-wb-panel-body" data-panel={panel.id} />
    </Section>
  );
}

export function SitePanels({ panels }: { panels: PanelConfig[] }) {
  return <>{panels.map((p) => <SitePanel key={p.id} panel={p} />)}</>;
}
