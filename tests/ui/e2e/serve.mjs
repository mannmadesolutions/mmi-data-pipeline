// Serves the Workbench harness page and the site's static files (read-only) for
// the browser tests. admin-ajax.php is answered by the test's mock backend.
//   HARNESS_JSON=<render-harness.php output> SITE_ROOT=<WordPress root> node serve.mjs
import { createServer } from "node:http";
import { existsSync, readFileSync, statSync } from "node:fs";
import { extname, join, normalize } from "node:path";

const SITE = normalize(process.env.SITE_ROOT ?? "");
const PORT = Number(process.env.PORT ?? 4319);
const h = JSON.parse(readFileSync(process.env.HARNESS_JSON ?? "", "utf8"));
const PAGE = `<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Workbench harness</title>${h.styles}</head>
<body class="wp-admin wp-core-ui js mmi-page"><div id="wpwrap"><div id="wpcontent"><div id="wpbody"><div id="wpbody-content">
<div class="wrap mmi-page"><div class="mmi-header"><h1><span class="dashicons dashicons-database-import"></span> Data Pipeline</h1></div>
<div class="wp-header-end"></div>${h.markup}</div></div></div></div></div>${h.scripts}</body></html>`;
const TYPES = { ".js": "text/javascript", ".css": "text/css", ".woff": "font/woff", ".woff2": "font/woff2", ".png": "image/png", ".svg": "image/svg+xml", ".gif": "image/gif" };
const STATIC = /^\/(wp-includes|wp-admin\/css|wp-admin\/js|wp-content\/plugins)\//;

createServer((req, res) => {
  const path = decodeURIComponent(new URL(req.url, "http://x").pathname);
  if (path === "/" || path === "/harness") { res.writeHead(200, { "Content-Type": "text/html; charset=utf-8" }); return res.end(PAGE); }
  const file = normalize(join(SITE, path));
  if (STATIC.test(path) && file.startsWith(SITE) && TYPES[extname(file)] && existsSync(file) && statSync(file).isFile()) {
    res.writeHead(200, { "Content-Type": TYPES[extname(file)] });
    return res.end(readFileSync(file));
  }
  res.writeHead(404); res.end("Not found");
}).listen(PORT, "127.0.0.1", () => console.log(`harness on http://127.0.0.1:${PORT}/`));
