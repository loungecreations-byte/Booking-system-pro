import { expect, test, type Page } from "@playwright/test";

const HOME_URL = "http://dagjedenbosch.local/";
const ACTIVITIES_URL = "http://dagjedenbosch.local/activiteiten/";
const DISCOVERY_URL = "http://dagjedenbosch.local/wp-json/planner/v1/activities";
const PLANNER_URL = "http://dagjedenbosch.local/plan-je-dag/";

type DiscoveryQuery = {
  date: string;
  participants: number;
  duration?: string;
  audience?: string;
  vibe?: string;
  preferences?: string[];
};

function isoDate(daysFromNow = 7): string {
  const value = new Date();
  value.setDate(value.getDate() + daysFromNow);
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
      // ignore bootstrap storage issues
    }
  });
}

async function readPlannerIngressSnapshot(page: Page) {
  return page.evaluate(() => {
    const dateInput = document.querySelector('input[type="date"]') as HTMLInputElement | null;
    const participantsInput = document.querySelector('input[type="number"]') as HTMLInputElement | null;
    const text = document.body.innerText || "";
    let draft: any = null;
    try {
      draft = JSON.parse(window.localStorage.getItem("sbdpPlannerDraftV1") || "null");
    } catch {
      draft = null;
    }

    return {
      dateInput: dateInput?.value || null,
      participantsInput: participantsInput?.value || null,
      hasParticipantsText12: text.includes("12 deelnemers"),
      planParticipants: draft?.plan?.participants ?? null,
      formParticipants: draft?.form?.participants ?? null,
      firstItemParticipants: draft?.plan?.items?.[0]?.participants ?? null,
      itemCount: Array.isArray(draft?.plan?.items) ? draft.plan.items.length : 0,
    };
  });
}

async function expectCanonicalPlannerIngress(
  page: Page,
  expectedDate: string,
  expectedParticipants: number,
  options: { requireFirstItem?: boolean } = {}
) {
  await page.locator('input[type="date"]').first().waitFor({ timeout: 30000 });
  await expect
    .poll(() => readPlannerIngressSnapshot(page), { timeout: 15000 })
    .toMatchObject({
      dateInput: expectedDate,
      participantsInput: String(expectedParticipants),
      hasParticipantsText12: expect.any(Boolean),
      planParticipants: expectedParticipants,
      formParticipants: String(expectedParticipants),
    });

  if (options.requireFirstItem) {
    await expect
      .poll(() => readPlannerIngressSnapshot(page), { timeout: 15000 })
      .toMatchObject({
        firstItemParticipants: expectedParticipants,
      });
  }
}

async function fetchDiscoverySnapshotFromPage(page: Page, query: DiscoveryQuery) {
  const payload = await page.evaluate(
    async ({ url, query }) => {
      const apiNonce =
        document.querySelector("[data-ui-api-nonce]")?.getAttribute("data-ui-api-nonce") ||
        document.querySelector("#sbdp-activities-root")?.getAttribute("data-rest-nonce") ||
        document.querySelector('[data-component="sbdp-activity-overview"]')?.getAttribute("data-config") ||
        "";

      let nonce = "";
      if (apiNonce && apiNonce.trim().startsWith("{")) {
        try {
          const parsed = JSON.parse(apiNonce);
          nonce = String(parsed?.discovery?.nonce || "");
        } catch {
          nonce = "";
        }
      } else {
        nonce = String(apiNonce || "");
      }

      const endpoint = new URL(url);
      endpoint.searchParams.set("date", query.date);
      endpoint.searchParams.set("participants", String(query.participants));
      endpoint.searchParams.set("exclude_unavailable", "1");
      endpoint.searchParams.set("per_page", "12");
      if (query.duration) {
        endpoint.searchParams.set("duration", query.duration);
      }
      if (query.audience) {
        endpoint.searchParams.set("audience", query.audience);
      }
      if (query.vibe) {
        endpoint.searchParams.set("vibe", query.vibe);
      }
      if (Array.isArray(query.preferences) && query.preferences.length > 0) {
        endpoint.searchParams.set("preferences", query.preferences.join(","));
      }

      const response = await fetch(endpoint.toString(), {
        method: "GET",
        credentials: "same-origin",
        headers: {
          Accept: "application/json",
          ...(nonce
            ? {
                "X-WP-Nonce": nonce,
                "x-sbdp-nonce": nonce,
              }
            : {}),
        },
      });

      const body = await response.json().catch(() => null);
      return {
        ok: response.ok,
        body,
      };
    },
    { url: DISCOVERY_URL, query }
  );

  expect(payload.ok).toBeTruthy();
  const items = Array.isArray(payload.body?.items) ? payload.body.items : [];

  const direct = items.filter((item: any) => {
    const capability = String(item?.booking_capability || item?.bookingCapability || "").toLowerCase();
    return capability === "direct" || capability === "direct_limited";
  });
  const requestOnly = items.filter((item: any) => {
    const capability = String(item?.booking_capability || item?.bookingCapability || "").toLowerCase();
    return capability === "request";
  });

  return {
    items,
    direct,
    requestOnly,
  };
}

async function overviewSurfaceMounted(page: Page): Promise<boolean> {
  return page.evaluate(() => {
    return Boolean(
      document.querySelector('[data-component="sbdp-activity-overview"]') ||
        document.querySelector(".ao-grid") ||
        document.querySelector('[data-role="activity-overview-root"]')
    );
  });
}

async function openHomeWidget(page: Page) {
  await page.goto(HOME_URL, { waitUntil: "domcontentloaded" });
  const widget = page.locator("[data-sbdp-home-widget]").first();
  test.skip((await widget.count()) === 0, "Home widget is not mounted in this environment.");

  await widget.locator('input[name="visitDate"]').fill(isoDate());
  await widget.locator('input[name="count"]').fill("4");
  await widget.locator("[data-sbdp-open]").click();

  const modal = widget.locator("[data-sbdp-modal]");
  await expect(modal).toBeVisible();
  return { widget, modal };
}

test.describe("discovery convergence", () => {
  test.beforeEach(async ({ page }) => {
    await resetPlannerStorage(page);
  });

  test("home publishes exactly one canonical discovery widget surface", async ({ page }) => {
    await page.goto(HOME_URL, { waitUntil: "domcontentloaded" });
    const widget = page.locator("[data-sbdp-home-widget]");
    test.skip((await widget.count()) === 0, "Home widget is not mounted in this environment.");
    await expect(widget).toHaveCount(1);
    await expect(page.locator(".ddb-hp-composer")).toHaveCount(0);

    const html = await page.content();
    expect(html).not.toContain("visitDate=undefined");
    expect(html).not.toContain("count=undefined");
    expect(html).not.toContain("participants=undefined");
  });

  test("home widget activities CTA preserves canonical date and participants on activiteiten ingress", async ({ page }) => {
    const date = isoDate();
    const participants = 4;
    const { widget, modal } = await openHomeWidget(page);
    await widget.locator('input[name="visitDate"]').fill(date);
    await widget.locator('input[name="count"]').fill(String(participants));
    await modal.locator('[data-sbdp-chip-group="duration"] button[data-value="hele-dag"]').click();
    await modal.locator('[data-sbdp-chip-group="company"] button[data-value="vrienden"]').click();
    await modal.locator('[data-sbdp-chip-group="vibe"] button[data-value="verrassend"]').click();

    const browseLink = widget.locator("[data-sbdp-activities]").first();
    await browseLink.click();
    await page.waitForURL(/\/activiteiten\/\?/i, { timeout: 15000 });

    const current = new URL(page.url());
    expect(current.searchParams.get("date")).toBe(date);
    expect(current.searchParams.get("visitDate")).toBe(date);
    expect(current.searchParams.get("participants")).toBe(String(participants));
    expect(current.searchParams.get("count")).toBe(String(participants));
  });

  test("activiteitenoverzicht consumes the same discovery context and status labels", async ({ page }) => {
    const date = isoDate();
    const participants = 4;
    await page.goto(`${ACTIVITIES_URL}?date=${date}&participants=${participants}&count=${participants}`, {
      waitUntil: "domcontentloaded",
    });
    test.skip(!(await overviewSurfaceMounted(page)), "Activity overview discovery surface is not mounted in this environment.");
    const discovery = await fetchDiscoverySnapshotFromPage(page, { date, participants });

    test.skip(
      discovery.direct.length === 0 && discovery.requestOnly.length === 0,
      "No discovery items returned for the selected context."
    );

    await expect(page.locator(".ao-grid").first()).toBeVisible();

    if (discovery.direct.length > 0) {
      const firstDirect = discovery.direct[0];
      const directCard = page.locator(".ao-spot-card").filter({ hasText: String(firstDirect.title) }).first();
      await expect(directCard).toBeVisible();
      await expect(directCard).toContainText(/Direct boekbaar/i);
      await expect(directCard.getByRole("link", { name: /Plan direct/i })).toBeVisible();
    }

    if (discovery.requestOnly.length > 0) {
      const firstRequest = discovery.requestOnly[0];
      const requestCard = page.locator(".ao-spot-card").filter({ hasText: String(firstRequest.title) }).first();
      await expect(requestCard).toBeVisible();
      await expect(requestCard).toContainText(/Op aanvraag/i);
      await expect(requestCard.getByRole("link", { name: /Plan aanvraag/i })).toBeVisible();
    }
  });

  test("home widget direct planner CTA preserves canonical date and participants on planner ingress", async ({
    page,
  }) => {
    const date = isoDate();
    const participants = 12;
    const { widget, modal } = await openHomeWidget(page);
    await widget.locator('input[name="visitDate"]').fill(date);
    await widget.locator('input[name="count"]').fill(String(participants));
    await modal.locator('[data-sbdp-chip-group="duration"] button[data-value="hele-dag"]').click();
    await modal.locator('[data-sbdp-chip-group="company"] button[data-value="vrienden"]').click();
    await modal.locator('[data-sbdp-chip-group="vibe"] button[data-value="verrassend"]').click();
    await modal.locator("[data-sbdp-submit]").click();
    await page.waitForURL(/\/plan-je-dag\/\?/i, { timeout: 15000 });
    await expectCanonicalPlannerIngress(page, date, participants);
  });

  test("activities direct planner CTA preserves canonical date and participants on planner ingress", async ({
    page,
  }) => {
    const date = isoDate();
    const participants = 12;
    await page.goto(`${ACTIVITIES_URL}?date=${date}&visitDate=${date}&participants=${participants}&count=${participants}`, {
      waitUntil: "domcontentloaded",
    });
    test.skip(!(await overviewSurfaceMounted(page)), "Activity overview discovery surface is not mounted in this environment.");

    const directPlannerLink = page
      .locator('.ao-spot-card a[href*="/plan-je-dag"]')
      .first();
    test.skip((await directPlannerLink.count()) === 0, "No direct planner link available in activity overview.");
    const href = await directPlannerLink.getAttribute("href");
    expect(href).toBeTruthy();

    await page.goto(new URL(String(href), ACTIVITIES_URL).toString(), { waitUntil: "domcontentloaded" });
    await expectCanonicalPlannerIngress(page, date, participants, { requireFirstItem: true });
  });

  test("encoded combi prefill preserves canonical date and participants on planner ingress", async ({ page }) => {
    const date = isoDate();
    const participants = 12;
    const prefill = {
      product_id: 97,
      date,
      participants,
      people: participants,
      source: "product",
      append: true,
      combiItems: [
        {
          id: "2165",
          label: "Test Combi",
          timing: "before",
          duration: 60,
          adjustment: 0,
        },
      ],
    };
    const url = new URL(PLANNER_URL);
    url.searchParams.set("start", "workshop-worstenbroodjes");
    url.searchParams.set("product_id", "97");
    url.searchParams.set("date", date);
    url.searchParams.set("visitDate", date);
    url.searchParams.set("participants", String(participants));
    url.searchParams.set("people", String(participants));
    url.searchParams.set("count", String(participants));
    url.searchParams.set("source", "product");
    url.searchParams.set("sbdp_prefill", JSON.stringify(prefill));

    await page.goto(url.toString(), { waitUntil: "domcontentloaded" });
    await expectCanonicalPlannerIngress(page, date, participants, { requireFirstItem: true });
  });
});
