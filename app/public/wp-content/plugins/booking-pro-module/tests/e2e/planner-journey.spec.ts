import { expect, test } from "@playwright/test";

const PRODUCT_URL = "http://dagjedenbosch.local/product/bierproeverij/";

function isoDate(daysFromNow = 1): string {
  const value = new Date();
  value.setDate(value.getDate() + daysFromNow);
  return value.toISOString().slice(0, 10);
}

test.describe("critical execution journey", () => {
  test("request-only product routes booking metadata into the quote request", async ({ page }) => {
    await page.goto(PRODUCT_URL, { waitUntil: "domcontentloaded" });

    await expect(page.locator("#sbdp-booking-form")).toBeVisible();
    await expect(page.locator('input[name="sbdp_booking_nonce"]')).toHaveCount(1);
    await expect(page.locator('#sbdp-booking-form button[type="submit"]')).toHaveCount(0);

    const date = isoDate();
    await page.locator("#sbdp_date").fill(date);
    await page.locator(".ui-chip").first().click();
    const selectedTime = await page.locator("#sbdp_time").inputValue();
    await page.locator("#sbdp_participants").fill("10");

    const quoteButton = page.locator('#sbdp-booking-form [data-sbdp-action="quote"]');
    await expect(quoteButton).toBeVisible();
    await expect(quoteButton).toBeEnabled();
    await quoteButton.click();

    await page.waitForURL(/offerte/i, { timeout: 30000 });
    const quoteUrl = new URL(page.url());
    expect(quoteUrl.searchParams.get("planner_plan")).toMatch(/^\d+$/);
    expect(quoteUrl.searchParams.get("edit_token")).toMatch(/^[a-f0-9]{32,}$/i);
    expect(quoteUrl.searchParams.get("product_id")).toBeNull();
    expect(quoteUrl.searchParams.get("date")).toBeNull();
    expect(quoteUrl.searchParams.get("time")).toBeNull();
    expect(quoteUrl.searchParams.get("participants")).toBeNull();

    await expect(page.getByText(/Ongeldige aanvraaglink/i)).toHaveCount(0);
    await expect(page.locator(".sbdp-offerte-form")).toBeVisible();
    await expect(page.locator(".sbdp-offerte-summary")).toContainText("Bierproeverij");
    await expect(page.locator(".sbdp-offerte-summary")).toContainText("10 personen");
    await expect(page.locator(".sbdp-offerte-summary")).toContainText(selectedTime);
    await expect(page.locator(".sbdp-offerte-summary__meta")).toContainText("Datum");
  });
});
