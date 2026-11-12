import { defineConfig } from "@playwright/test";
import path from "node:path";
import { testingEnvironment } from "./tests/e2e/testing-environment";

export default defineConfig({
  testDir: "./tests/e2e",
  fullyParallel: false,
  workers: 1,
  timeout: 120_000,
  expect: { timeout: 30_000 },
  globalSetup: "./tests/e2e/global-setup.ts",
  globalTeardown: "./tests/e2e/global-teardown.ts",
  outputDir: path.resolve("storage/framework/testing/playwright-results"),
  reporter: "list",
  use: {
    baseURL: "http://127.0.0.1:8000",
    trace: "retain-on-failure",
  },
  webServer: [
    {
      command: "node tests/e2e/server-process.mjs laravel",
      env: testingEnvironment,
      url: "http://127.0.0.1:8000/up",
      reuseExistingServer: false,
      timeout: 120_000,
    },
  ],
});
