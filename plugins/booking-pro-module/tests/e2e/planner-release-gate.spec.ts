import { expect, test, type Page } from "@playwright/test";

const PLANNER_URL = "http://dagjedenbosch.local/plan-je-dag/";
const COMBI_PRODUCT_URL = "http://dagjedenbosch.local/product/bierproeverij/";

type SavedPlanPayload = {
  meta?: {
    participant_count?: number;
  };
  participants?: unknown[];
  days?: Array<{
    slots?: Array<{
      people?: number;
    }>;
  }>;
};

function isoDatePlus(days: number): string {
  const value = new Date();
  value.setDate(value.getDate() + days);
  return value.toISOString().slice(0, 10);
}

function parseEuroAmount(raw: string | null | undefined): number {
  if (!raw) {
    return 0;
  }

  const amountMatch = raw.match(/-?\d{1,3}(?:\.\d{3})*(?:,\d{2})|-?\d+(?:,\d{2})?/);
  const candidate = amountMatch ? amountMatch[0] : raw;
  const normalized = candidate.replace(/[^\d,.-]/g, "").replace(/\./g, "").replace(",", ".");
  const parsed = Number.parseFloat(normalized);
  return Number.isFinite(parsed) ? parsed : 0;
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
      // Ignore bootstrap storage errors.
    }
  });
}

async function waitPlanner(page: Page) {
  await resetPlannerStorage(page);
  await page.goto(PLANNER_URL, { waitUntil: "domcontentloaded", timeout: 45000 });
  await page.locator('input[type="date"]').first().waitFor({ timeout: 30000 });
}

async function setPlannerDate(page: Page) {
  await page.locator('input[type="date"]').first().fill(isoDatePlus(7));
}

async function startPlanning(page: Page) {
  await page.getByRole("button", { name: /Start plannen|Update planning/i }).first().click({ force: true });
  await page.waitForTimeout(1500);
}

async function addFirstActivity(page: Page) {
  const addBtn = page.getByRole("button", { name: /^Voeg toe$/i }).first();
  await expect(addBtn).toBeVisible();
  await page.evaluate(() => {
    const overlay = document.querySelector("e-page-transition");
    if (overlay) {
      overlay.remove();
    }
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

async function addStableDirectActivity(page: Page) {
  await page.evaluate(() => {
    const overlay = document.querySelector("e-page-transition");
    if (overlay) {
      overlay.remove();
    }
    const bar = document.querySelector(".sbdp-mobile-action-bar");
    if (bar instanceof HTMLElement) {
      bar.style.pointerEvents = "none";
      bar.style.opacity = "0";
    }
  });

  const stableCardButton = page
    .locator("article.ui-listing-card")
    .filter({ hasNotText: /Combi arrangement|Arrangement/i })
    .locator('button:visible')
    .filter({ hasText: /^Voeg toe$/i })
    .first();

  if ((await stableCardButton.count()) > 0) {
    await expect(stableCardButton).toBeVisible();
    await stableCardButton.click({ force: true });
  } else {
    await addFirstActivity(page);
    return;
  }

  const doneBtn = page.getByRole("button", { name: /^Gereed$/i }).first();
  if ((await doneBtn.count()) > 0) {
    await doneBtn.click({ force: true }).catch(() => {});
  }
  await page.waitForTimeout(1200);
}

async function getDesktopParticipantInput(page: Page) {
  const input = page.locator(".sbdp-participants-stepper__value").first();
  await expect(input).toBeVisible();
  return input;
}

async function readPlannerDraftParticipants(page: Page): Promise<number> {
  return page.evaluate(() => {
    // @ts-expect-error runtime global
    const draft = window.SBDPPlannerDomain?.store?.readDraft?.() || null;
    const value = Number.parseInt(String(draft?.form?.participants ?? draft?.plan?.participants ?? ""), 10);
    return Number.isFinite(value) ? value : 0;
  });
}

async function clickTopLevelParticipantDelta(page: Page, direction: "plus" | "minus") {
  const buttons = page.locator(".sbdp-field-participants .sbdp-participants-stepper__btn");
  const target = direction === "plus" ? buttons.last() : buttons.first();
  await expect(target).toBeVisible();
  await target.click({ force: true });
}

async function getPrimaryBookButton(page: Page) {
  return page.locator("button:visible").filter({ hasText: /Boek mijn dag|In winkelwagen/i }).first();
}

function getPlannerCheckoutSurface(page: Page) {
  return page.locator(".sbdp-planner-checkout:visible").first();
}

async function ensurePlannerPanelOpen(page: Page) {
  const visibleCheckout = getPlannerCheckoutSurface(page);
  if ((await visibleCheckout.count()) > 0) {
    return;
  }

  const openBtn = page.getByRole("button", { name: /Open planner|Bekijk daglijn/i }).first();
  if ((await openBtn.count()) > 0) {
    await openBtn.click({ force: true });
    await expect(getPlannerCheckoutSurface(page)).toBeVisible({ timeout: 10000 });
  }
}

function getPlannerDirectButton(page: Page) {
  return page
    .locator(".sbdp-planner-checkout button:visible")
    .filter({ hasText: /Boek mijn dag|In winkelwagen/i })
    .first();
}

function getPlannerQuoteButton(page: Page) {
  return page
    .locator(".sbdp-planner-checkout button:visible")
    .filter({ hasText: /Vraag offerte/i })
    .first();
}

async function readPlannerCheckoutSnapshot(page: Page) {
  await ensurePlannerPanelOpen(page);

  const checkout = getPlannerCheckoutSurface(page);
  await expect(checkout).toBeVisible({ timeout: 10000 });

  const totalText = ((await checkout.locator(".sbdp-planner-checkout__total").textContent()) || "").trim();
  const surfaceText = (await checkout.innerText()).trim();
  const directButton = getPlannerDirectButton(page);
  const quoteButton = getPlannerQuoteButton(page);

  return {
    totalText,
    total: parseEuroAmount(totalText),
    surfaceText,
    directEnabled: (await directButton.count()) > 0 ? !(await directButton.isDisabled()) : false,
    quoteEnabled: (await quoteButton.count()) > 0 ? !(await quoteButton.isDisabled()) : false,
  };
}

async function waitForStablePlannerCheckout(page: Page, options: { requireDirectEnabled?: boolean } = {}) {
  const { requireDirectEnabled = false } = options;
  let previousSurfaceText = "";
  let stableReads = 0;

  for (let attempt = 0; attempt < 20; attempt += 1) {
    const snapshot = await readPlannerCheckoutSnapshot(page);
    stableReads = snapshot.surfaceText === previousSurfaceText ? stableReads + 1 : 0;
    previousSurfaceText = snapshot.surfaceText;

    const surfaceStillUpdating = /Planner wordt bijgewerkt/i.test(snapshot.surfaceText);

    if (
      snapshot.total > 0 &&
      !surfaceStillUpdating &&
      (!requireDirectEnabled || snapshot.directEnabled) &&
      stableReads >= 2
    ) {
      return snapshot;
    }

    await page.waitForTimeout(1000);
  }

  throw new Error("Planner checkout surface did not settle into a stable actionable state.");
}

async function queuePlannerToCheckout(page: Page) {
  await ensurePlannerPanelOpen(page);
  await waitForStablePlannerCheckout(page, { requireDirectEnabled: true });
  const bookBtn = getPlannerDirectButton(page);
  await expect(bookBtn).toBeEnabled({ timeout: 15000 });
  await Promise.allSettled([
    page.waitForURL(/cart|checkout|winkelwagen|afrekenen/i, { timeout: 20000 }),
    bookBtn.click({ force: true }),
  ]);
}

async function readParticipantsFromPage(page: Page): Promise<number> {
  const text = await page.locator("body").innerText();
  const match =
    text.match(/Deelnemers:\s*(\d+)/i) ||
    text.match(/(\d+)\s*deelnemer/i) ||
    text.match(/(\d+)\s*personen/i);
  return match ? Number.parseInt(match[1], 10) : 0;
}

async function readOrderTotal(page: Page): Promise<number> {
  const rowSelectors = [
    ".ddb-checkout-program__totals-grand",
    ".ddb-checkout-program__totals-row",
    ".cart_totals tr.order-total",
    ".woocommerce-checkout-review-order-table tr.order-total",
    "tr.order-total",
  ];

  for (const selector of rowSelectors) {
    const row = page.locator(selector).first();
    if ((await row.count()) === 0) {
      continue;
    }

    await row.waitFor({ state: "visible", timeout: 15000 }).catch(() => {});

    const raw = await row.evaluate((node) => {
      const selectors = [
        ".ddb-checkout-program__totals-grand span:last-child",
        ".ddb-checkout-program__totals-tax span:last-child",
        "span:last-child",
        "td strong .woocommerce-Price-amount.amount bdi",
        "td strong .woocommerce-Price-amount bdi",
        "td > strong .woocommerce-Price-amount.amount",
        "td > strong .woocommerce-Price-amount",
        "td > .woocommerce-Price-amount.amount bdi",
        "td > .woocommerce-Price-amount bdi",
      ];

      for (const selector of selectors) {
        const hit = node.querySelector(selector);
        const text = hit?.textContent?.trim() || "";
        if (text !== "") {
          return text;
        }
      }

      const fallbackCell = node.querySelector("td");
      return fallbackCell?.textContent?.trim() || node.textContent?.trim() || "";
    });

    const parsed = parseEuroAmount(raw);
    if (parsed > 0) {
      return parsed;
    }
  }

  const bodyText = (await page.locator("body").innerText()).trim();
  const labeledMatches = [
    /Totaal incl\. btw\s*€?\s*([\d\.,]+)/i,
    /Totaal(?:bedrag)?\s*€?\s*([\d\.,]+)/i,
    /Order total\s*€?\s*([\d\.,]+)/i,
  ];

  for (const pattern of labeledMatches) {
    const match = bodyText.match(pattern);
    if (!match) {
      continue;
    }

    const parsed = parseEuroAmount(match[1] ?? "");
    if (parsed > 0) {
      return parsed;
    }
  }

  throw new Error("Authoritative order total row not found on cart/checkout surface.");
}

async function proceedToCheckout(page: Page) {
  const currentUrl = page.url();
  if (/cart|winkelwagen/i.test(currentUrl)) {
    const checkoutLink = page
      .locator('a.checkout-button, .wc-proceed-to-checkout a, a[href*="/checkout"]')
      .filter({ hasText: /Afrekenen|Doorgaan naar afrekenen|Checkout/i })
      .first();

    if ((await checkoutLink.count()) > 0) {
      await checkoutLink.click({ force: true });
    } else {
      await page.goto("http://dagjedenbosch.local/checkout/", { waitUntil: "domcontentloaded" });
    }
  } else {
    await page.goto("http://dagjedenbosch.local/checkout/", { waitUntil: "domcontentloaded" });
  }

  await expect(page).toHaveURL(/checkout|afrekenen/i, { timeout: 30000 });
}

async function buildAndQueuePlan(page: Page, configureParticipants: () => Promise<number>) {
  const savedPlanPayloads: SavedPlanPayload[] = [];
  page.on("request", (request) => {
    if (
      request.method() === "POST" &&
      /\/planner\/v1\/plan(?:\/\d+)?(?:\?|$)/.test(request.url())
    ) {
      try {
        savedPlanPayloads.push(request.postDataJSON() as SavedPlanPayload);
      } catch {
        // Ignore malformed request bodies.
      }
    }
  });

  await waitPlanner(page);
  await setPlannerDate(page);
  const expectedParticipants = await configureParticipants();
  await expect.poll(() => readPlannerDraftParticipants(page), { timeout: 5000 }).toBe(expectedParticipants);
  await startPlanning(page);
  await addStableDirectActivity(page);
  await ensurePlannerPanelOpen(page);

  await queuePlannerToCheckout(page);

  await expect
    .poll(() => savedPlanPayloads.length, { timeout: 15000 })
    .toBeGreaterThan(0);

  return {
    expectedParticipants,
    lastSavedPayload: savedPlanPayloads[savedPlanPayloads.length - 1] || {},
  };
}

test.describe("planner release gate", () => {
  test("plus control keeps canonical participants aligned through planner, cart, and checkout", async ({ page }) => {
    const { expectedParticipants, lastSavedPayload } = await buildAndQueuePlan(page, async () => {
      const input = await getDesktopParticipantInput(page);
      const current = Number.parseInt(await input.inputValue(), 10);
      const next = Number.isFinite(current) && current > 0 ? current + 1 : 1;
      await clickTopLevelParticipantDelta(page, "plus");
      await expect.poll(() => readPlannerDraftParticipants(page), { timeout: 5000 }).toBe(next);
      return next;
    });

    expect(lastSavedPayload?.meta?.participant_count).toBe(expectedParticipants);
    expect(Array.isArray(lastSavedPayload?.participants) ? lastSavedPayload.participants.length : 0).toBe(
      expectedParticipants
    );
    expect(
      Array.isArray(lastSavedPayload?.days?.[0]?.slots) &&
        lastSavedPayload.days[0].slots.every((slot: { people?: number }) => slot.people === expectedParticipants)
    ).toBeTruthy();

    await expect.poll(() => readParticipantsFromPage(page), { timeout: 15000 }).toBe(expectedParticipants);
    await proceedToCheckout(page);
    await expect.poll(() => readParticipantsFromPage(page), { timeout: 15000 }).toBe(expectedParticipants);
  });

  test("minus control keeps canonical participants aligned through planner, cart, and checkout", async ({ page }) => {
    const { expectedParticipants, lastSavedPayload } = await buildAndQueuePlan(page, async () => {
      const input = await getDesktopParticipantInput(page);
      await input.fill("8");
      const next = 7;
      await clickTopLevelParticipantDelta(page, "minus");
      await expect.poll(() => readPlannerDraftParticipants(page), { timeout: 5000 }).toBe(next);
      return next;
    });

    expect(lastSavedPayload?.meta?.participant_count).toBe(expectedParticipants);
    expect(Array.isArray(lastSavedPayload?.participants) ? lastSavedPayload.participants.length : 0).toBe(
      expectedParticipants
    );

    await expect.poll(() => readParticipantsFromPage(page), { timeout: 15000 }).toBe(expectedParticipants);
    await proceedToCheckout(page);
    await expect.poll(() => readParticipantsFromPage(page), { timeout: 15000 }).toBe(expectedParticipants);
  });

  test("typed participant input commits without blur and reaches cart and checkout canonically", async ({ page }) => {
    const { expectedParticipants, lastSavedPayload } = await buildAndQueuePlan(page, async () => {
      const input = await getDesktopParticipantInput(page);
      await input.fill("7");
      await expect.poll(() => readPlannerDraftParticipants(page), { timeout: 5000 }).toBe(7);
      return 7;
    });

    expect(lastSavedPayload?.meta?.participant_count).toBe(expectedParticipants);
    await expect.poll(() => readParticipantsFromPage(page), { timeout: 15000 }).toBe(expectedParticipants);
    await proceedToCheckout(page);
    await expect.poll(() => readParticipantsFromPage(page), { timeout: 15000 }).toBe(expectedParticipants);
  });

  test("planner total stays advisory and matches cart and checkout authority in a natural flow", async ({ page }) => {
    await waitPlanner(page);
    await setPlannerDate(page);
    const input = await getDesktopParticipantInput(page);
    await input.fill("7");
    await startPlanning(page);
    await addStableDirectActivity(page);
    const plannerSnapshot = await waitForStablePlannerCheckout(page, { requireDirectEnabled: true });

    await expect(
      page.getByText(/Indicatieve prijs\. Winkelwagen en checkout blijven de definitieve commerciële waarheid\./i)
    ).toBeVisible();

    const plannerTotal = plannerSnapshot.total;
    expect(plannerTotal).toBeGreaterThan(0);

    await queuePlannerToCheckout(page);
    const cartTotal = await readOrderTotal(page);
    expect(cartTotal).toBeGreaterThan(0);
    expect(Math.abs(cartTotal - plannerTotal)).toBeLessThanOrEqual(0.5);

    await proceedToCheckout(page);
    const checkoutTotal = await readOrderTotal(page);
    expect(checkoutTotal).toBeGreaterThan(0);
    expect(Math.abs(checkoutTotal - cartTotal)).toBeLessThanOrEqual(0.01);
  });

  test("natural combideal flow blocks direct booking when a required child availability lookup fails", async ({ page }) => {
    await resetPlannerStorage(page);
    await page.route("**/wp-json/sbdp/v1/availability/slots?product_id=30**", async (route) => {
      await route.fulfill({
        status: 500,
        contentType: "application/json",
        body: JSON.stringify({ message: "forced child availability failure" }),
      });
    });

    await page.goto(COMBI_PRODUCT_URL, { waitUntil: "domcontentloaded" });
    await page.locator("#sbdp_date").fill(isoDatePlus(7));
    await page.locator(".ui-chip").first().click();
    await page.locator("#sbdp_participants").fill("10");
    await page.locator('label[for$="_before"]').first().click();
    await page.locator('label[for$="_after"]').last().click();
    await page.locator("#sbdp_plan_btn").click();

    await page.waitForURL(/plan-je-dag|planner/i, { timeout: 30000 });
    await expect
      .poll(
        async () => {
          await ensurePlannerPanelOpen(page);
          const checkout = getPlannerCheckoutSurface(page);
          if ((await checkout.count()) === 0) {
            return JSON.stringify({ surface: false, directDisabled: false, quoteEnabled: false, issueVisible: false });
          }

          const bookBtn = getPlannerDirectButton(page);
          const quoteBtn = getPlannerQuoteButton(page);
          const issueVisible = await page
            .getByText(/forced child availability failure|Beschikbaarheid kon niet worden bevestigd/i)
            .first()
            .isVisible()
            .catch(() => false);

          return JSON.stringify({
            surface: true,
            directDisabled: (await bookBtn.count()) > 0 ? await bookBtn.isDisabled() : false,
            quoteEnabled: (await quoteBtn.count()) > 0 ? !(await quoteBtn.isDisabled()) : false,
            issueVisible,
          });
        },
        { timeout: 20000 }
      )
      .toBe(
        JSON.stringify({
          surface: true,
          directDisabled: true,
          quoteEnabled: true,
          issueVisible: true,
        })
      );
  });
});
