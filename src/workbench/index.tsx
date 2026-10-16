/**
 * Product Workbench entry: mounts the React app into #mmi-wb-app once the
 * shared jQuery components have initialised (their ready handlers run first).
 */
import { createRoot } from "react-dom/client";
import { App } from "./App";
import { bridgeReady } from "./bridge";
import { NONCE_ACTION, page } from "./globals";

const ROOT_ID = "mmi-wb-app";

function mount(): void {
  const el = document.getElementById(ROOT_ID);
  const w = page();
  if (!el || !w.mmiWorkbench) return;
  if (!bridgeReady()) {
    el.textContent = "Product Workbench could not start: a shared component did not load. Reload the page; if it persists, check the browser console.";
    return;
  }
  // Keep the shared nonce fresh on long-open tabs, for this app and for the hosted jQuery components.
  if (w.MMINonces) {
    if (w.mmiRuleBuilder) w.MMINonces.watch(NONCE_ACTION, w.mmiRuleBuilder);
    if (w.mmiConditionBuilder) w.MMINonces.watch(NONCE_ACTION, w.mmiConditionBuilder);
  }
  createRoot(el).render(<App cfg={w.mmiWorkbench} />);
}

const $ = page().jQuery;
if ($) $(mount);
else document.addEventListener("DOMContentLoaded", mount); // mount() reports the missing component
