import { defineConfig } from '@playwright/test';

const httpUsername = process.env.E2E_HTTP_USERNAME;
const httpPassword = process.env.E2E_HTTP_PASSWORD;

if ((httpUsername && !httpPassword) || (!httpUsername && httpPassword)) {
  throw new Error('Set both E2E_HTTP_USERNAME and E2E_HTTP_PASSWORD, or neither.');
}

export default defineConfig({
  testDir: 'tests/e2e',
  testMatch: /planner-.*\.spec\.(ts|js)/,
  reporter: [['list']],
  fullyParallel: false,
  workers: 1,
  timeout: 60000,
  use: httpUsername && httpPassword
    ? {
        httpCredentials: {
          username: httpUsername,
          password: httpPassword,
        },
      }
    : undefined,
});

