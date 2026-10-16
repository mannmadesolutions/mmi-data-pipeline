import { defineConfig } from "vitest/config";

export default defineConfig({
  esbuild: { jsx: "automatic" },
  test: { include: ["tests/ui/**/*.test.{ts,tsx}"], environment: "jsdom" },
});
