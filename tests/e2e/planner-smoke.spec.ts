import { test, expect } from '@playwright/test';

const BASE_URL = process.env.PLANNER_BASE_URL || 'https://staging.example.com';
const PLANNER_PATH = process.env.PLANNER_PATH || '/planner';

const plannerUrl = `${BASE_URL}${PLANNER_PATH}`;

test.describe('Planner smoke', () => {
  test('compose booking request flow', async ({ page }) => {
    await page.goto(plannerUrl, { waitUntil: 'networkidle' });

    await expect(page.locator('#sbdp-date')).toBeVisible();
    await page.fill('#sbdp-date', new Date().toISOString().split('T')[0]);
    await page.fill('#sbdp-participants', '2');

    // Wait for services list to render
    await expect(page.locator('#sbdp-services [role="listitem"]').first()).toBeVisible({ timeout: 10000 });

    // Click first service add button as fallback for drag
    const addButtons = page.locator('#sbdp-services button[data-action="add"]');
    if (await addButtons.count()) {
      await addButtons.first().click();
    }

    await expect(page.locator('#sbdp-summary-list [role="listitem"]').first()).toBeVisible({ timeout: 10000 });

    // Trigger compose booking request
    await page.click('#sbdp-btn-request');
    await expect(page.locator('#sbdp-message-area')).toContainText(/aanvraag|verzonden|success/i);
  });
});