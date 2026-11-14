/**
 * The MMI Product Data Chrome extension, as the Workbench page reaches it: window messages with its bridge
 * (browser-extension/bridge.js, on the store's admin pages). The bridge says "here" as it loads and when pinged, with
 * whether this browser is connected and what the page may ask of it (`can`, extension 1.1.2+).
 *
 * overview (owner 2026-11-14): a maker site's ↗ in Makers opens the site in a new tab with the panel on its site
 * overview (its pages, layouts and the work left). Without the extension, or a copy that can't, the link opens the
 * site as any link does.
 *
 * pick (owner 2026-11-14, a site's map steps): a step done on one of the maker's pages opens that page in a new tab with
 * the panel there and the mapper on the step's part, saving for the whole site. Without the extension, the page opens.
 */
const TAG = "mmiExt";

interface Here { paired: boolean; can: string[] }
let here: Here | null = null;

function listen(e: MessageEvent) {
  const d = e.data as Record<string, unknown> | null;
  if (e.source !== window || e.origin !== window.location.origin || !d || d[TAG] !== true || d.from !== "extension") return;
  if (d.op === "here") here = { paired: !!d.paired, can: Array.isArray(d.can) ? d.can.map(String) : [] };
}

const post = (op: string, data: Record<string, unknown> = {}) =>
  window.postMessage({ [TAG]: true, from: "page", op, ...data }, window.location.origin);

let started = false;
/** Hear the extension's bridge from now on, and ask it to say it is here. */
export function watchExtension(): void {
  if (started) return;
  started = true;
  window.addEventListener("message", listen);
  post("ping");
}

/** The extension is connected in this browser and can open a site's overview. */
export const canOverview = (): boolean => !!here?.paired && here.can.includes("overview");

/** Have the extension open $host with the panel on its site overview (bridge.js answers "overviewing"). */
export const openOverview = (host: string): void => post("overview", { host });

/** $host's site overview in the extension; its home page in a plain new tab where the extension can't. */
export function openSite(host: string): void {
  if (canOverview()) openOverview(host);
  else window.open(`https://${host}/`, "_blank", "noopener");
}

/** The extension is connected in this browser and can open a page with the mapper on a part. */
export const canPick = (): boolean => !!here?.paired && here.can.includes("pick");

/** $url with the mapper on $part, saving for the whole site; in a plain new tab where the extension can't. */
export function openPick(url: string, part: string): void {
  if (canPick()) post("pick", { url, part, scope: "site" });
  else window.open(url, "_blank", "noopener");
}
