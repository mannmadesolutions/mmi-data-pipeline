import { defineConfig, devices } from "@playwright/test";

// Browser tests for the React admin UI against the harness page (scripts/ui.sh e2e).
// WebKit first: the owner uses Safari.
export default defineConfig({
  testDir: "tests/ui/e2e",
  timeout: 30_000,
  reporter: [["list"]],
  use: { baseURL: "http://127.0.0.1:4319/" },
  webServer: { command: "node tests/ui/e2e/serve.mjs", url: "http://127.0.0.1:4319/", reuseExistingServer: false },
  projects: [
    { name: "webkit", use: { ...devices["Desktop Safari"], viewport: { width: 1440, height: 900 } } },
    { name: "chromium", use: { ...devices["Desktop Chrome"], viewport: { width: 1440, height: 900 } } },
  ],
});
