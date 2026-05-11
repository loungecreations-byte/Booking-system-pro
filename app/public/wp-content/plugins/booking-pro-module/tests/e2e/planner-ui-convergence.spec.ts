import { expect, test, devices, type Page } from "@playwright/test";

const BASE = "http://dagjedenbosch.local/plan-je-dag/";

function isoDatePlus(days: number): string {
  const value = new Date();
  value.setDate(value.getDate() + days);
  return value.toISOString().slice(0, 10);
}

async function resetPlannerStorage(page: Page) {
  await page.addInitScript(() => {
    try {
      window.localStorage.removeItem("sbdpPlannerDraftV1");
      window.localStorage.removeItem("sbdpPlannerFilters");
      window.sessionStorage.removeItem("sbdpPlannerPrefillQueue");
      window.sessionStorage.removeItem("sbdp_user_preferences");
      window.sessionStorage.removeItem("sbdp_home_widget_prefill");
      // @ts-expect-error runtime global
      window.SBDP_HOME_WIDGET_PREFILL = null;
    } catch {
      // Ignore storage bootstrap failures.
    }
  });
}

async function waitPlanner(page: Page) {
  await resetPlannerStorage(page);
  await page.goto(BASE, { waitUntil: "domcontentloaded", timeout: 45000 });
  await page.locator('input[type="date"]').first().waitFor({ timeout: 30000 });
}

async function setDateAndParticipantsNoBlur(page: Page, participants: number) {
  const date = isoDatePlus(7);
  await page.locator('input[type="date"]').first().fill(date);

  const mobileInput = page.locator('input[type="number"]').first();
  const desktopInput = page.locator(".sbdp-participants-stepper__value").first();
  const participantsInput = (await desktopInput.count()) > 0 ? desktopInput : mobileInput;
  await participantsInput.fill(String(participants));

  await page.evaluate(() => {
    const overlay = document.querySelector("e-page-transition");
    if (overlay) {
      overlay.remove();
    }
  });

  const startBtn = page.getByRole("button", { name: /Start plannen|Update planning/i }).first();
  if ((await startBtn.count()) > 0) {
    await startBtn.click({ force: true });
  }
  await page.waitForTimeout(1200);
}

async function addFirstActivity(page: Page) {
  const addBtn = page.getByRole("button", { name: /^Voeg toe$/i }).first();
  await expect(addBtn).toBeVisible();

  await page.evaluate(() => {
    const bar = document.querySelector(".sbdp-mobile-action-bar");
    if (bar instanceof HTMLElement) {
      bar.style.pointerEvents = "none";
      bar.style.opacity = "0";
    }
  });

  await addBtn.click({ force: true });
  await page.waitForTimeout(1000);

  const doneBtn = page.getByRole("button", { name: /^Gereed$/i }).first();
  if ((await doneBtn.count()) > 0) {
    await doneBtn.click({ force: true }).catch(() => {});
  }
  await page.waitForTimeout(1200);
}

async function ensurePlannerPanelOpen(page: Page) {
  const directCta = page.getByRole("button", { name: /Boek mijn dag|In winkelwagen/i }).first();
  if ((await directCta.count()) > 0) {
    return;
  }

  const openBtn = page.getByRole("button", { name: /Open planner|Bekijk daglijn/i }).first();
  if ((await openBtn.count()) > 0) {
    await openBtn.click({ force: true });
    await page.waitForTimeout(800);
  }
}

async function captureProductIds(page: Page) {
  const ids: number[] = [];
  page.on("response", async (response) => {
    try {
      if (!response.url().includes("/planner/v1/products")) {
        return;
      }
      const payload = await response.json();
      const nextIds = (Array.isArray(payload?.products) ? payload.products : [])
        .map((product) => Number(product?.id))
        .filter((value) => Number.isFinite(value) && value > 0);
      ids.push(...nextIds);
    } catch {
      // Ignore catalog response parsing failures.
    }
  });
  return ids;
}

async function getFirstProductId(page: Page, capturedIds: number[]) {
  const captured = capturedIds.find((value) => Number.isFinite(value) && value > 0);
  if (captured) {
    return captured;
  }

  return page.evaluate(() => {
    // @ts-expect-error runtime global
    const config = window.SBDP_DAY_PLANNER || {};
    const products = Array.isArray(config.products) ? config.products : [];
    const hit = products.find((product) => Number.isFinite(Number(product?.id)) && Number(product.id) > 0);
    return hit ? Number(hit.id) : 0;
  });
}

async function getCurrentPlannerProductIds(page: Page) {
  return page.evaluate(() => {
    const draft = window.SBDPPlannerDomain?.store?.readDraft?.() || null;
    const items = Array.isArray(draft?.plan?.items) ? draft.plan.items : [];
    return items
      .map((item) => Number.parseInt(item?.productId ?? item?.product_id, 10))
      .filter((value) => Number.isFinite(value) && value > 0);
  });
}

async function injectPrefillItem(page: Page, detail: Record<string, unknown>) {
  await page.evaluate((payload) => {
    window.dispatchEvent(new CustomEvent("sbdp:planner/prefill", { detail: payload }));
  }, detail);
  await page.waitForTimeout(2000);
}

async function getPrimaryBookingButton(page: Page) {
  await ensurePlannerPanelOpen(page);
  const plannerButton = page.locator(".sbdp-planner-checkout button:visible").filter({ hasText: /Boek mijn dag/i }).first();
  if ((await plannerButton.count()) > 0) {
    return plannerButton;
  }
  return page.locator("button:visible").filter({ hasText: /Boek mijn dag|In winkelwagen/i }).first();
}

async function getQuoteButton(page: Page) {
  await ensurePlannerPanelOpen(page);
  const plannerButton = page.locator(".sbdp-planner-checkout button:visible").filter({ hasText: /Vraag offerte aan/i }).first();
  if ((await plannerButton.count()) > 0) {
    return plannerButton;
  }
  return page.locator("button:visible").filter({ hasText: /Vraag offerte aan/i }).first();
}

async function expectQuoteButtonEnabled(page: Page) {
  const quoteBtn = await getQuoteButton(page);
  await expect
    .poll(async () => !(await quoteBtn.isDisabled()), { timeout: 5000 })
    .toBe(true);
  return quoteBtn;
}

async function waitForStablePlannerCheckout(page: Page, options: { requireQuoteEnabled?: boolean } = {}) {
  const { requireQuoteEnabled = false } = options;
  let previousText = "";
  let stableReads = 0;

  for (let attempt = 0; attempt < 20; attempt += 1) {
    await ensurePlannerPanelOpen(page);
    const checkout = page.locator(".sbdp-planner-checkout:visible").first();
    if ((await checkout.count()) === 0) {
      await page.waitForTimeout(1000);
      continue;
    }

    const totalText = ((await checkout.locator(".sbdp-planner-checkout__total").textContent()) || "").trim();
    const directBtn = await getPrimaryBookingButton(page);
    const quoteBtn = await getQuoteButton(page);
    const snapshot = `${(await checkout.innerText()).trim()}|${totalText}|${await directBtn.isDisabled()}|${await quoteBtn.isDisabled()}`;

    stableReads = snapshot === previousText ? stableReads + 1 : 0;
    previousText = snapshot;

    if (
      stableReads >= 2 &&
      (!requireQuoteEnabled || !(await quoteBtn.isDisabled()))
    ) {
      return;
    }

    await page.waitForTimeout(1000);
  }

  throw new Error("Planner checkout surface did not settle into a stable request/direct state.");
}

async function expectRequestOnlyPlannerState(page: Page) {
  await ensurePlannerPanelOpen(page);
  await expect(page.locator(".sbdp-planner-checkout").first()).toBeVisible({ timeout: 10000 });
  const bookBtn = await getPrimaryBookingButton(page);
  const quoteBtn = await getQuoteButton(page);
  await expect
    .poll(
      async () =>
        JSON.stringify({
          directDisabled: await bookBtn.isDisabled(),
          quoteEnabled: !(await quoteBtn.isDisabled()),
        }),
      { timeout: 15000 }
    )
    .toBe(JSON.stringify({ directDisabled: true, quoteEnabled: true }));

  return { bookBtn, quoteBtn };
}

async function seedRequestOnlyPlan(page: Page, mixed: boolean) {
  const productIds = await captureProductIds(page);
  await waitPlanner(page);
  await setDateAndParticipantsNoBlur(page, 4);

  if (mixed) {
    await addFirstActivity(page);
  }

  const activeProductIds = mixed ? await getCurrentPlannerProductIds(page) : [];
  const candidateIds = productIds.filter((value) => !activeProductIds.includes(value));
  const productId = await getFirstProductId(page, candidateIds.length > 0 ? candidateIds : productIds);
  expect(productId).toBeGreaterThan(0);
  const requestedStartTime = mixed ? "16:00" : "10:00";
  const requestedEndTime = mixed ? "17:00" : "11:00";

  await injectPrefillItem(page, {
    product_id: productId,
    date: isoDatePlus(7),
    time: requestedStartTime,
    append: true,
    traceId: mixed ? "desktop-mixed-request-only" : "request-only-plan",
    planItem: {
      id: `prefill-${mixed ? "mixed" : "request"}-${productId}`,
      productId,
      product_id: productId,
      participants: 4,
      startTime: requestedStartTime,
      endTime: requestedEndTime,
      bookingCapability: "REQUEST_ONLY",
      booking_capability: "REQUEST_ONLY",
      routeIntent: "quote",
      route_intent: "quote",
      bookingResolution: {
        status: "needs_choice",
        route_intent: "quote",
      },
    },
  });
}

test.describe("planner ui convergence - desktop", () => {
  test("direct-capable no-blur flow fires a real booking handoff", async ({ page }) => {
    const bookingRequests: string[] = [];
    page.on("request", (request) => {
      if (request.method() === "POST" && /\/planner\/v1\/plan\/\d+\/book/.test(request.url())) {
        bookingRequests.push(request.url());
      }
    });

    await waitPlanner(page);
    await setDateAndParticipantsNoBlur(page, 7);
    await addFirstActivity(page);
    await waitForStablePlannerCheckout(page);

    const bookBtn = await getPrimaryBookingButton(page);
    await expect(bookBtn).toBeEnabled();

    await Promise.allSettled([
      page.waitForURL(/cart|checkout|winkelwagen|afrekenen/i, { timeout: 15000 }),
      bookBtn.click({ force: true }),
    ]);

    await expect
      .poll(() => bookingRequests.length, { timeout: 15000 })
      .toBeGreaterThan(0);
  });

  test("request-only plan blocks direct booking and leaves quote enabled", async ({ page }) => {
    await seedRequestOnlyPlan(page, false);

    const { bookBtn, quoteBtn } = await expectRequestOnlyPlannerState(page);
    await expect(bookBtn).toBeDisabled();
    await expect(quoteBtn).toBeEnabled();
  });

  test("mixed direct/request plan blocks direct booking and leaves quote enabled", async ({ page }) => {
    await seedRequestOnlyPlan(page, true);

    const { bookBtn, quoteBtn } = await expectRequestOnlyPlannerState(page);
    await expect(bookBtn).toBeDisabled();
    await expect(quoteBtn).toBeEnabled();
  });

  test("availability lookup failure blocks direct booking and shows a visible issue", async ({ page }) => {
    await page.route("**/sbdp/v1/availability/slots**", async (route) => {
      await route.fulfill({
        status: 500,
        contentType: "application/json",
        body: JSON.stringify({ message: "forced availability failure" }),
      });
    });

    await waitPlanner(page);
    await setDateAndParticipantsNoBlur(page, 3);
    await addFirstActivity(page);

    const bookBtn = await getPrimaryBookingButton(page);
    await expect(bookBtn).toBeDisabled();
    await expect(
      page.getByText(/forced availability failure|beschikbaarheid|tijdslot|beschikbaar/i).first()
    ).toBeVisible();
  });
});

test.describe("planner ui convergence - mobile", () => {
  test("request-only plan blocks direct booking and leaves quote enabled", async ({ browser }) => {
    const context = await browser.newContext({ ...devices["iPhone 13"] });
    const page = await context.newPage();
    await seedRequestOnlyPlan(page, false);

    const { bookBtn, quoteBtn } = await expectRequestOnlyPlannerState(page);
    await expect(bookBtn).toBeDisabled();
    await expect(quoteBtn).toBeEnabled();
    await context.close();
  });

  test("mixed direct/request plan blocks direct booking and leaves quote enabled", async ({ browser }) => {
    const context = await browser.newContext({ ...devices["iPhone 13"] });
    const page = await context.newPage();
    await seedRequestOnlyPlan(page, true);

    const { bookBtn, quoteBtn } = await expectRequestOnlyPlannerState(page);
    await expect(bookBtn).toBeDisabled();
    await expect(quoteBtn).toBeEnabled();
    await context.close();
  });

  test("availability lookup failure blocks direct booking and shows a visible issue", async ({ browser }) => {
    const context = await browser.newContext({ ...devices["iPhone 13"] });
    const page = await context.newPage();
    await page.route("**/sbdp/v1/availability/slots**", async (route) => {
      await route.fulfill({
        status: 500,
        contentType: "application/json",
        body: JSON.stringify({ message: "forced availability failure" }),
      });
    });

    await waitPlanner(page);
    await setDateAndParticipantsNoBlur(page, 3);
    await addFirstActivity(page);

    const bookBtn = await getPrimaryBookingButton(page);
    await expect(bookBtn).toBeDisabled();
    await expect(
      page.getByText(/forced availability failure|beschikbaarheid|tijdslot|beschikbaar/i).first()
    ).toBeVisible();
    await context.close();
  });
});
