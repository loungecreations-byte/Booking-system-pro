import { test, expect } from '@playwright/test';

const baseUrl = process.env.E2E_PLANNER_URL;

test.describe('Planner bundles', () => {
  test.skip(!baseUrl, 'Set E2E_PLANNER_URL to run planner bundle spec');

  test.beforeEach(async ({ page }) => {
    await page.goto(baseUrl!);
    await page.waitForLoadState('networkidle');
  });

  test('renders bundle card and applies compose payload', async ({ page }) => {
    const bundlesCard = page.locator('#sbdp-bundles-card');
    await expect(bundlesCard).toBeVisible();

    const bundleRow = page.locator('[data-bundle-id="BND-1"]');
    await expect(bundleRow).toBeVisible();
    await expect(bundleRow.locator('h3')).toHaveText(/Morning Bundle/i);

    await page.evaluate(() => {
      type AugmentedWindow = typeof window & {
        __bndInterceptedPayload?: unknown;
        __bndOriginalFetch?: typeof fetch;
      };
      const scopedWindow = window as AugmentedWindow;
      scopedWindow.__bndInterceptedPayload = null;

      if (!scopedWindow.__bndOriginalFetch && window.fetch) {
        scopedWindow.__bndOriginalFetch = window.fetch.bind(window);
      }

      const originalFetch = scopedWindow.__bndOriginalFetch;
      if (!originalFetch) {
        return;
      }

      window.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
        if (typeof input === 'string' && input.includes('/sbdp/v1/compose_booking')) {
          try {
            const body = init?.body ? JSON.parse(init.body as string) : null;
            scopedWindow.__bndInterceptedPayload = body;
          } catch (error) {
            console.error('Failed to parse compose payload', error);
          }
        }
        return originalFetch(input, init);
      };
    });

    await bundleRow.getByRole('button', { name: /gebruik arrangement/i }).click();

    await page.waitForTimeout(1000);

    const intercepted = await page.evaluate(() => (window as typeof window & { __bndInterceptedPayload?: unknown }).__bndInterceptedPayload);
    expect(intercepted).toBeTruthy();

    const payload = intercepted as Record<string, any>;
    expect(payload.mode).toBe('pay');
    expect(payload.bundle_id).toBe('BND-1');
    expect(payload.participants).toBe(4);
    expect(payload.meta.bundle_label).toBe('Morning Bundle');
    expect(payload.meta.note).toBe('Prefer early slot');
  });
});
