import { expect, test, type Page } from "@playwright/test";

const PRODUCT_URL = "http://dagjedenbosch.local/product/bierproeverij/";

function isoDate(daysFromNow = 1): string {
  const value = new Date();
  value.setDate(value.getDate() + daysFromNow);
  return value.toISOString().slice(0, 10);
}

async function clearPlannerStorage(page: Page) {
  await page.goto("http://dagjedenbosch.local/", { waitUntil: "domcontentloaded" });
  await page.evaluate(() => {
    try {
      window.localStorage.removeItem("sbdpPlannerDraftV1");
      window.sessionStorage.removeItem("sbdpPlannerPrefillQueue");
      window.sessionStorage.removeItem("sbdpPlannerFreshPrefillBootstrapV1");
    } catch (error) {
      // Ignore browser storage errors in test bootstrap.
    }
  });
}

async function prepareCombiDeal(page: Page) {
  await clearPlannerStorage(page);
  await page.goto(PRODUCT_URL, { waitUntil: "domcontentloaded" });

  await expect(page.locator("#sbdp-booking-form")).toBeVisible();

  await page.locator("#sbdp_date").fill(isoDate());
  await page.locator(".ui-chip").first().click();

  const participants = page.locator("#sbdp_participants");
  await participants.fill("10");
  await expect(participants).toHaveValue("10");

  await page.locator('label[for$="_before"]').first().click();
  await page.locator('label[for$="_after"]').last().click();

  await expect(page.locator("#sbdp_active_combis")).not.toHaveValue("");
}

async function ensurePlannerPanelOpen(page: Page) {
  await expect
    .poll(
      () =>
        page.evaluate(() => {
          // @ts-expect-error runtime global
          const draft = window.SBDPPlannerDomain?.store?.readDraft?.() || null;
          return Array.isArray(draft?.plan?.items) ? draft.plan.items.length : 0;
        }),
      { timeout: 25000 }
    )
    .toBeGreaterThan(0);

  const plannerPrimary = page.locator('[data-planner-primary-surface="active"]');
  if ((await plannerPrimary.count()) > 0 && (await plannerPrimary.first().isVisible().catch(() => false))) {
    return;
  }

  const openBtn = page.getByRole("button", { name: /Open planner|Bekijk daglijn/i }).first();
  if ((await openBtn.count()) > 0) {
    await openBtn.click({ force: true });
  }

  await expect(page.locator('[data-planner-primary-surface="active"]').first()).toBeVisible({ timeout: 10000 });
}

async function waitForStableArrangementSurface(page: Page) {
  const plannerPrimary = page.locator('[data-planner-primary-surface="active"]').first();
  let previousPairs = "";
  let stableReads = 0;

  for (let attempt = 0; attempt < 20; attempt += 1) {
    await expect(plannerPrimary).toBeVisible({ timeout: 10000 });
    const updatingMessage = page.getByText(/Planner wordt bijgewerkt/i).first();
    const isUpdatingVisible = (await updatingMessage.count()) > 0 && (await updatingMessage.isVisible().catch(() => false));
    const pairs = await plannerPrimary
      .locator('[data-planner-entry-kind="arrangement"]:visible .sbdp-calendar-event__segment')
      .evaluateAll((nodes) => {
        const values = nodes
          .map((node) => {
            const role = node.querySelector(".sbdp-calendar-event__segment-role")?.textContent?.trim() || "";
            const title =
              node.querySelector(".sbdp-calendar-event__segment-body strong")?.textContent?.trim() || "";
            return role && title ? `${role}|${title}` : "";
          })
          .filter(Boolean);

        return Array.from(new Set(values)).join("||");
      });

    stableReads = pairs !== "" && pairs === previousPairs ? stableReads + 1 : 0;
    previousPairs = pairs;

    if (!isUpdatingVisible && stableReads >= 1) {
      return pairs.split("||").filter(Boolean);
    }

    await page.waitForTimeout(750);
  }

  throw new Error("Planner arrangement surface did not settle into one stable visible state.");
}

async function readCanonicalPlannerItems(page: Page) {
  return page.evaluate(() => {
    // @ts-expect-error runtime global
    const draft = window.SBDPPlannerDomain?.store?.readDraft?.() || null;
    const items = Array.isArray(draft?.plan?.items) ? draft.plan.items : [];
    return items.map((item) => ({
      id: item?.id ?? null,
      title: item?.title ?? "",
      productId: item?.productId ?? item?.product_id ?? null,
      role: item?.role ?? null,
      groupId: item?.groupId ?? null,
      source: item?.source ?? null,
      startTime: item?.startTime ?? null,
    }));
  });
}

test.describe("planner combi flow", () => {
  test("plan in dag keeps only the chosen arrangement in the planner", async ({ page }) => {
    await prepareCombiDeal(page);

    await page.locator("#sbdp_plan_btn").click();

    await page.waitForURL(/plan-je-dag|planner/i, { timeout: 30000 });
    await ensurePlannerPanelOpen(page);

    const plannerPrimary = page.locator('[data-planner-primary-surface="active"]');
    await expect(plannerPrimary).toBeVisible();
    await expect(plannerPrimary.getByRole("heading", { name: "Jouw planning" })).toBeVisible();

    await expect(plannerPrimary.getByText("Bierproeverij", { exact: false }).first()).toBeVisible();
    await expect(plannerPrimary.getByText("Bossche Bol", { exact: false }).first()).toBeVisible();
    await expect(plannerPrimary.getByText("3 Gangen", { exact: false }).first()).toBeVisible();
    const uniqueRoleTitlePairs = await waitForStableArrangementSurface(page);
    const canonicalItems = await readCanonicalPlannerItems(page);

    expect(uniqueRoleTitlePairs).toEqual([
      "Vooraf|3 Gangen diner",
      "Hoofdactiviteit|Bierproeverij",
      "Achteraf|Bossche Bol met koffie",
    ]);

    expect(canonicalItems).toHaveLength(3);
    expect(canonicalItems.map((item) => item.productId)).toEqual([352, 30, 350]);
    expect(new Set(canonicalItems.map((item) => item.groupId)).size).toBe(1);
    expect(new Set(canonicalItems.map((item) => item.source))).toEqual(new Set(["product-prefill"]));

    await expect(page.getByText(/2x in planning/i)).toHaveCount(0);
    await expect(plannerPrimary.locator(".sbdp-planner-checkout")).toContainText("3 gekozen");

    await page.reload({ waitUntil: "domcontentloaded" });
    await ensurePlannerPanelOpen(page);
    await expect(page.locator('[data-planner-primary-surface="active"]').first()).toBeVisible();

    const postReloadRoleTitlePairs = await waitForStableArrangementSurface(page);
    const postReloadItems = await readCanonicalPlannerItems(page);

    expect(postReloadRoleTitlePairs).toEqual([
      "Vooraf|3 Gangen diner",
      "Hoofdactiviteit|Bierproeverij",
      "Achteraf|Bossche Bol met koffie",
    ]);
    expect(postReloadItems).toHaveLength(3);
    expect(postReloadItems.map((item) => item.productId)).toEqual([352, 30, 350]);
    expect(new Set(postReloadItems.map((item) => item.groupId)).size).toBe(1);
    await expect(page.getByText(/2x in planning/i)).toHaveCount(0);
    await expect(page.locator('[data-planner-primary-surface="active"]').first().locator(".sbdp-planner-checkout")).toContainText("3 gekozen");
  });

  test("request-only product keeps the selected combi names before quote handoff", async ({ page }) => {
    await prepareCombiDeal(page);

    await expect(page.locator('#sbdp-booking-form button[type="submit"]')).toHaveCount(0);
    const quoteButton = page.locator('#sbdp-booking-form [data-sbdp-action="quote"]');
    await expect(quoteButton).toBeVisible();
    await expect(quoteButton).toBeEnabled();

    const selectedCombis = await page.locator("#sbdp_active_combis").evaluate((input) => {
      const value = input instanceof HTMLInputElement ? input.value : "";
      return JSON.parse(value) as Array<{ label?: string; timing?: string }>;
    });
    expect(selectedCombis.map((item) => item.label)).toEqual(["3 Gangen diner", "Bossche Bol met koffie"]);
    expect(selectedCombis.map((item) => item.timing)).toEqual(["before", "after"]);
    await expect(page.locator(".sbdp-summary-card")).toContainText("Bierproeverij");
    await expect(page.locator(".sbdp-summary-card")).toContainText("3 Gangen diner");
    await expect(page.locator(".sbdp-summary-card")).toContainText("Bossche Bol met koffie");

    await quoteButton.click();
    await page.waitForURL(/offerte/i, { timeout: 30000 });
    const quoteUrl = new URL(page.url());
    expect(quoteUrl.searchParams.get("planner_plan")).toMatch(/^\d+$/);
    expect(quoteUrl.searchParams.get("edit_token")).toMatch(/^[a-f0-9]{32,}$/i);
    expect(quoteUrl.searchParams.get("product_id")).toBeNull();
    expect(quoteUrl.searchParams.get("date")).toBeNull();
    expect(quoteUrl.searchParams.get("participants")).toBeNull();

    await expect(page.getByText(/Ongeldige aanvraaglink/i)).toHaveCount(0);
    await expect(page.locator(".sbdp-offerte-form")).toBeVisible();
    await expect(page.locator(".sbdp-offerte-summary")).toContainText("Bierproeverij");
    await expect(page.locator(".sbdp-offerte-summary")).toContainText("10 personen");
    await expect(page.locator(".sbdp-offerte-summary")).toContainText("3 Gangen diner");
    await expect(page.locator(".sbdp-offerte-summary")).toContainText("Bossche Bol met koffie");
  });
});
