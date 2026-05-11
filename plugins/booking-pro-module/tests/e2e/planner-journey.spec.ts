import { expect, test } from "@playwright/test";

const PRODUCT_URL = "http://dagjedenbosch.local/product/bierproeverij/";

function isoDate(daysFromNow = 1): string {
  const value = new Date();
  value.setDate(value.getDate() + daysFromNow);
  return value.toISOString().slice(0, 10);
}

test.describe("critical execution journey", () => {
  test("product detail keeps booking metadata intact from cart into checkout", async ({ page }) => {
    await page.goto(PRODUCT_URL, { waitUntil: "domcontentloaded" });

    await expect(page.locator("#sbdp-booking-form")).toBeVisible();
    await expect(page.locator('input[name="sbdp_booking_nonce"]')).toHaveCount(1);

    await page.locator("#sbdp_date").fill(isoDate());
    await page.locator(".ui-chip").first().click();
    await page.locator("#sbdp_participants").fill("10");

    await page.locator('#sbdp-booking-form button[type="submit"]').click();
    await page.waitForURL(/winkelwagen|cart/i, { timeout: 30000 });
    await expect(page).toHaveURL(/winkelwagen|cart/i);

    const orderTable = page.locator("table").first();
    await expect(orderTable).toContainText("Bierproeverij");
    await expect(page.getByText(/Deelnemers:\s*10/i)).toBeVisible();

    const checkoutLink = page.getByRole("link", { name: /Doorgaan naar afrekenen|Verder naar afrekenen/i }).first();
    if (await checkoutLink.count()) {
      await checkoutLink.click();
    } else {
      await page.goto("http://dagjedenbosch.local/checkout/", { waitUntil: "domcontentloaded" });
    }

    await page.waitForURL(/checkout|afrekenen/i, { timeout: 30000 });
    await expect(page.getByText("Bierproeverij", { exact: false }).first()).toBeVisible();
    await expect(
      page.getByText(/(personen|deelnemers)\s*:\s*10|10\s*(personen|deelnemers)/i).first()
    ).toBeVisible();
  });
});
