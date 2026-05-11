import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: 'tests/e2e',
  testMatch: /planner-.*\.spec\.(ts|js)/,
  reporter: [['list']],
  fullyParallel: false,
  workers: 1,
  timeout: 60000,
});

