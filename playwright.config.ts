import { defineConfig, devices } from "@playwright/test";

// Browser tests for the React admin UI against the harness page (scripts/ui.sh e2e).
// WebKit first: the owner uses Safari.
export default defineConfig({
  testDir: "tests/ui/e2e",
  timeout: 30_000,
  // Two browsers at a time: this runs on the live site's server. With four, WebKit was starved of CPU while the
  // site was busy (load 9-12 on 8 cores, 2026-11-09): each click took 2.5-5 s and the longest tests ran out of time.
  // `mmi-git check` sets PW_WORKERS=1, so a check run from a chat holds one browser, not two (2026-11-13).
  workers: Number(process.env.PW_WORKERS) || 2,
  reporter: [["list"]],
  use: { baseURL: "http://127.0.0.1:4319/" },
  webServer: { command: "node tests/ui/e2e/serve.mjs", url: "http://127.0.0.1:4319/", reuseExistingServer: false },
  projects: [
    { name: "webkit", use: { ...devices["Desktop Safari"], viewport: { width: 1440, height: 900 } } },
    { name: "chromium", use: { ...devices["Desktop Chrome"], viewport: { width: 1440, height: 900 } } },
  ],
});
