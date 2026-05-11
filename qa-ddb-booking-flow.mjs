import { chromium, devices } from 'playwright';

const BASE = 'http://dagjedenbosch.local/plan-je-dag/';

function isoDatePlus(days) {
  const d = new Date();
  d.setDate(d.getDate() + days);
  const yyyy = d.getFullYear();
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  return `${yyyy}-${mm}-${dd}`;
}

async function waitPlanner(page) {
  await page.goto(BASE, { waitUntil: 'domcontentloaded', timeout: 45000 });
  await page.locator('.sbdp-participants-stepper__value').first().waitFor({ timeout: 30000 });
}

async function setDateAndParticipantsNoBlur(page, participants) {
  const date = isoDatePlus(7);
  const dateInput = page.locator('input[type="date"]').first();
  await dateInput.fill(date);
  const pInput = page.locator('.sbdp-participants-stepper__value').first();
  await pInput.fill(String(participants));

  await page.evaluate(() => {
    const overlay = document.querySelector('e-page-transition');
    if (overlay) {
      overlay.remove();
    }
  });

  const startBtn = page.getByRole('button', { name: /Start plannen/i }).first();
  try {
    await startBtn.click({ force: true });
  } catch {
    await page.evaluate(() => {
      const buttons = Array.from(document.querySelectorAll('button'));
      const target = buttons.find((b) => /Start plannen/i.test((b.textContent || '').trim()));
      if (target) {
        target.click();
      }
    });
  }
  await page.waitForTimeout(1200);
  return date;
}

async function addFirstActivity(page) {
  const addBtn = page.getByRole('button', { name: /^Voeg toe$/i }).first();
  if (await addBtn.count() === 0) {
    return { ok: false, reason: 'no_add_button' };
  }

  await page.evaluate(() => {
    const bar = document.querySelector('.sbdp-mobile-action-bar');
    if (bar) {
      bar.style.pointerEvents = 'none';
      bar.style.opacity = '0';
    }
  });

  await addBtn.click({ force: true });
  await page.waitForTimeout(800);

  const doneBtn = page.getByRole('button', { name: /^Gereed$/i }).first();
  if (await doneBtn.count() > 0) {
    try {
      await doneBtn.click({ force: true });
    } catch {
      await page.evaluate(() => {
        const buttons = Array.from(document.querySelectorAll('button'));
        const target = buttons.find((b) => /^Gereed$/i.test((b.textContent || '').trim()));
        if (target) {
          target.click();
        }
      });
    }
  }
  await page.waitForTimeout(1500);
  return { ok: true };
}

async function captureProducts(page) {
  const hits = [];
  page.on('response', async (res) => {
    try {
      if (!res.url().includes('/planner/v1/products')) return;
      const data = await res.json();
      const ids = (Array.isArray(data?.products) ? data.products : []).map((p) => Number(p?.id)).filter((n) => Number.isFinite(n) && n > 0);
      if (ids.length) hits.push(...ids);
    } catch {}
  });
  return hits;
}

async function injectPrefillItem(page, opts) {
  await page.evaluate((detail) => {
    window.dispatchEvent(new CustomEvent('sbdp:planner/prefill', { detail }));
  }, opts);
  await page.waitForTimeout(2000);
}

async function getFirstProductId(page, capturedIds = []) {
  if (Array.isArray(capturedIds) && capturedIds.length > 0) {
    const viaCapture = Number(capturedIds.find((id) => Number.isFinite(id) && id > 0));
    if (viaCapture > 0) {
      return viaCapture;
    }
  }

  const viaConfig = await page.evaluate(() => {
    const cfg = window.SBDP_DAY_PLANNER || {};
    const products = Array.isArray(cfg.products) ? cfg.products : [];
    const hit = products.find((p) => Number.isFinite(Number(p?.id)) && Number(p.id) > 0);
    return hit ? Number(hit.id) : 0;
  });
  if (Number.isFinite(viaConfig) && viaConfig > 0) {
    return viaConfig;
  }

  const viaDom = await page.evaluate(() => {
    const node = document.querySelector('[data-product-id]');
    if (!node) return 0;
    const id = Number(node.getAttribute('data-product-id'));
    return Number.isFinite(id) && id > 0 ? id : 0;
  });

  return Number.isFinite(viaDom) && viaDom > 0 ? viaDom : 0;
}

async function ensurePlannerPanelOpen(page) {
  const bookBtn = page.getByRole('button', { name: /Boek mijn dag/i }).first();
  if (await bookBtn.count() > 0) {
    return;
  }

  const openBtn = page.getByRole('button', { name: /Open planner|Bekijk daglijn/i }).first();
  if (await openBtn.count() > 0) {
    await openBtn.click({ force: true });
    await page.waitForTimeout(800);
  }
}

async function scenario1(page, mode) {
  await waitPlanner(page);
  await setDateAndParticipantsNoBlur(page, 7);
  const add = await addFirstActivity(page);
  await ensurePlannerPanelOpen(page);
  const participantsShown = await page.locator('text=/7\\s+deelnemers/i').count();
  const bookBtn = page.getByRole('button', { name: /Boek mijn dag/i }).first();
  const bookVisible = (await bookBtn.count()) > 0;
  const bookEnabled = bookVisible ? await bookBtn.isEnabled() : false;
  let redirected = false;
  if (add.ok && bookVisible && bookEnabled) {
    await Promise.allSettled([
      page.waitForURL(/cart|checkout/i, { timeout: 12000 }),
      bookBtn.click({ force: true }),
    ]);
    redirected = /cart|checkout/i.test(page.url());
  }
  return {
    id: '1_participants_without_blur',
    mode,
    participantsShown: participantsShown > 0,
    addOk: add.ok,
    bookVisible,
    bookEnabled,
    redirected,
    url: page.url(),
  };
}

async function scenarioRequestLike(page, mode, mixed = false) {
  const productIds = await captureProducts(page);
  await waitPlanner(page);
  await setDateAndParticipantsNoBlur(page, 4);

  if (mixed) {
    await addFirstActivity(page);
  }

  const firstProductId = await getFirstProductId(page, productIds);
  const visitDate = isoDatePlus(7);
  if (!firstProductId) {
    return { id: mixed ? '3_mixed_direct_request_plan' : '2_request_only_item', mode, setup: 'no_product_id' };
  }

  await injectPrefillItem(page, {
    product_id: firstProductId,
    date: visitDate,
    time: '10:00',
    append: true,
    planItem: {
      productId: firstProductId,
      product_id: firstProductId,
      participants: 4,
      startTime: '10:00',
      endTime: '11:00',
      bookingCapability: 'REQUEST_ONLY',
      bookingResolution: {
        status: 'needs_choice',
      },
    },
  });

  await ensurePlannerPanelOpen(page);
  const bookBtn = page.getByRole('button', { name: /Boek mijn dag/i }).first();
  const quoteBtn = page.getByRole('button', { name: /Vraag offerte aan/i }).first();
  const blockedMsg = await page.locator('text=/offerte vereist|alleen via offerte|niet direct afrekenbaar/i').count();

  return {
    id: mixed ? '3_mixed_direct_request_plan' : '2_request_only_item',
    mode,
    bookDisabled: (await bookBtn.count()) ? !(await bookBtn.isEnabled()) : null,
    quoteEnabled: (await quoteBtn.count()) ? await quoteBtn.isEnabled() : null,
    blockedMsg: blockedMsg > 0,
  };
}

async function scenarioAvailabilityFailure(page, mode) {
  await page.route('**/sbdp/v1/availability/slots**', async (route) => {
    await route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: 'forced availability failure' }) });
  });
  await waitPlanner(page);
  await setDateAndParticipantsNoBlur(page, 3);
  const add = await addFirstActivity(page);
  await ensurePlannerPanelOpen(page);
  await page.waitForTimeout(1800);
  const availabilityIssue = await page.locator('text=/beschikbaarheid|tijdslot|slot/i').count();
  const bookBtn = page.getByRole('button', { name: /Boek mijn dag/i }).first();
  return {
    id: '4_availability_lookup_failure',
    mode,
    addOk: add.ok,
    availabilityIssueVisible: availabilityIssue > 0,
    bookEnabled: (await bookBtn.count()) ? await bookBtn.isEnabled() : null,
  };
}

async function scenarioCombiRequestComponent(page, mode) {
  const productIds = await captureProducts(page);
  await waitPlanner(page);
  await setDateAndParticipantsNoBlur(page, 5);
  const firstProductId = await getFirstProductId(page, productIds);
  if (!firstProductId) {
    return { id: '5_combideal_required_request_component', mode, setup: 'no_product_id' };
  }

  await injectPrefillItem(page, {
    product_id: firstProductId,
    date: isoDatePlus(7),
    time: '11:00',
    append: true,
    planItem: {
      productId: firstProductId,
      product_id: firstProductId,
      type: 'arrangement',
      source: 'product-combi',
      participants: 5,
      startTime: '11:00',
      endTime: '12:00',
      options: {
        combiItems: [
          { id: firstProductId, label: 'Req segment', timing: 'after' }
        ],
      },
      bookingResolution: {
        status: 'needs_choice',
        segments: [
          { role: 'anchor', status: 'confirmed', duration_minutes: 60 },
          { role: 'post', status: 'needs_choice', duration_minutes: 30 }
        ],
      },
    },
  });

  await ensurePlannerPanelOpen(page);
  const bookBtn = page.getByRole('button', { name: /Boek mijn dag/i }).first();
  const quoteBtn = page.getByRole('button', { name: /Vraag offerte aan/i }).first();

  return {
    id: '5_combideal_required_request_component',
    mode,
    bookDisabled: (await bookBtn.count()) ? !(await bookBtn.isEnabled()) : null,
    quoteEnabled: (await quoteBtn.count()) ? await quoteBtn.isEnabled() : null,
  };
}

async function runSuite(mode, contextOptions) {
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext(contextOptions);
  const page = await context.newPage();
  const results = [];
  try {
    results.push(await scenario1(page, mode));
    results.push(await scenarioRequestLike(page, mode, false));
    results.push(await scenarioRequestLike(page, mode, true));
    results.push(await scenarioAvailabilityFailure(page, mode));
    results.push(await scenarioCombiRequestComponent(page, mode));
  } finally {
    await context.close();
    await browser.close();
  }
  return results;
}

const desktop = await runSuite('desktop', { viewport: { width: 1440, height: 900 } });
const mobile = await runSuite('mobile', devices['iPhone 13']);

console.log(JSON.stringify({ desktop, mobile }, null, 2));
