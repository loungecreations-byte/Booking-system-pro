import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: 'tests/e2e',
  testMatch: /plannerBundles\.spec\.(ts|js)/,
  reporter: [['list']],
  fullyParallel: false,
  timeout: 60000,
});
