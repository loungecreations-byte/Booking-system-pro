import { expect, test } from "@playwright/test";

const HOME_URL = "http://dagjedenbosch.local/";
const PRODUCT_URL = "http://dagjedenbosch.local/product/bierproeverij/";

function isoDate(daysFromNow = 1): string {
  const value = new Date();
  value.setDate(value.getDate() + daysFromNow);
  return value.toISOString().slice(0, 10);
}

test("homepage onboarding publishes runtime when mounted", async ({ page }) => {
  await page.goto(HOME_URL, { waitUntil: "domcontentloaded" });

  const onboarding = page.locator('[data-component="sbdp-home-onboarding"]').first();
  test.skip((await onboarding.count()) === 0, "Homepage onboarding surface is not mounted in this environment.");

  await expect(onboarding).toHaveAttribute("data-sbdp-runtime-ready", "1");

  const runtime = await page.evaluate(() => {
    // @ts-expect-error runtime global
    return window.SBDP_HomeOnboardingRuntime || null;
  });

  expect(runtime).toBeTruthy();
  expect(["checkout", "quote", "blocked"]).toContain(runtime.route_intent);

  const openButton = onboarding.getByRole("button", { name: /Plan je dag/i }).first();
  await expect(openButton).toBeEnabled();
});

test("cart shell keeps summary shell and woo totals visible", async ({ page }) => {
  await page.goto(PRODUCT_URL, { waitUntil: "domcontentloaded" });

  await page.locator("#sbdp_date").fill(isoDate());
  await page.locator(".ui-chip").first().click();
  await page.locator("#sbdp_participants").fill("4");
  await page.locator('#sbdp-booking-form button[type="submit"]').click();

  await page.waitForURL(/winkelwagen|cart/i, { timeout: 30000 });

  await expect(page.locator(".ddb-cart-shell").first()).toBeVisible();
  await expect(page.locator(".ddb-cart-shell .ui-summary").first()).toBeVisible();
  await expect(page.locator(".woocommerce-cart-form__contents").first()).toBeVisible();
  await expect(page.locator(".cart_totals, .shop_table").filter({ hasText: /Totaal|Total/i }).first()).toBeVisible();
});
