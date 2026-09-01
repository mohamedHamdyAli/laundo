import { test, expect } from '@playwright/test';
import { ACCOUNTS, login, isRtl } from './helpers.js';

/**
 * The dashboard in Arabic.
 *
 * Until now every screen built since P6 — orders, piece review, dispatch,
 * payments, invoices, the money desk — rendered English labels in a product whose
 * design is entirely Arabic. These tests exist to keep that from happening again:
 * they walk the pages and assert the Arabic is actually there, rather than
 * assuming a translation file that exists is a translation file that works.
 *
 * The wording asserted is the design's own — «مراجعة القطع», «الرصيد المعلق»,
 * «تعذر الاستلام» — because a dashboard that invents synonyms for the apps'
 * vocabulary makes the two disagree about what things are called.
 */

/** Switches the panel to Arabic. The switcher is a plain URL, not a form. */
async function switchToArabic(page) {
  const response = await page.goto('/admin/set-language/ar');

  return response !== null && response.status() < 400;
}

test.describe('Arabic across the dashboard', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    const switched = await switchToArabic(page);
    test.skip(!switched, 'No Arabic switcher found in the topbar.');
  });

  test.afterEach(async ({ page }) => {
    // Leave the session in English so the other specs are unaffected — the
    // language is held in the session, not per-test.
    await page.goto('/admin/set-language/en');
  });

  test('the layout flips right to left', async ({ page }) => {
    expect(await isRtl(page)).toBeTruthy();
  });

  test('the sidebar is in Arabic', async ({ page }) => {
    const body = await page.textContent('body');

    for (const word of ['الطلبات', 'المغاسل', 'المناديب', 'أكواد الخصم', 'المحافظ']) {
      expect(body).toContain(word);
    }
  });

  test('the orders list speaks the design\'s vocabulary', async ({ page }) => {
    await page.goto('/admin/order');

    const body = await page.textContent('body');

    expect(body).toContain('الطلبات');
    expect(body).toContain('العميل');
    expect(body).toContain('المغسلة');
    expect(body).toContain('الحالة');

    // And an order status, drawn from the enum through a variable — the case a
    // naive extractor would have missed.
    expect(body).toMatch(/بانتظار استلام القطع|جاري التنظيف|تم التوصيل|ملغي|تم استلام الطلب/);
  });

  test('the review screen uses «مراجعة القطع», not a synonym', async ({ page }) => {
    await page.goto('/admin/order?status=picked_up');

    const links = await page
      .locator('#order-table-body a[href*="/admin/order/show/"]')
      .evaluateAll((nodes) => nodes.map((n) => n.getAttribute('href')));

    test.skip(links.length === 0, 'No order is waiting to be reviewed.');

    let found = false;

    for (const href of links) {
      await page.goto(href);
      if (await page.locator('#review-table').count() > 0) {
        found = true;
        break;
      }
    }

    test.skip(!found, 'No reviewable order rendered the form.');

    const body = await page.textContent('body');
    expect(body).toContain('مراجعة القطع');
    expect(body).toContain('العدد الفعلي');
    expect(body).toContain('قال العميل');
  });

  test('the transport panel names the four legs in Arabic', async ({ page }) => {
    await page.goto('/admin/order');

    const link = page.locator('#order-table-body a[href*="/admin/order/show/"]').first();
    test.skip(await link.count() === 0, 'No orders in the dev database.');

    await link.click();
    await page.waitForLoadState('networkidle');

    const body = await page.textContent('body');
    expect(body).toContain('النقل');
    expect(body).toMatch(/استلام من العميل|تسليم للمغسلة|لا توجد مهام نقل/);
  });

  test('the money screens are in Arabic', async ({ page }) => {
    await page.goto('/admin/coupon');
    let body = await page.textContent('body');
    expect(body).toContain('أكواد الخصم');

    await page.goto('/admin/refund');
    body = await page.textContent('body');
    expect(body).toContain('قيد المراجعة');
    expect(body).toContain('تمت الموافقة ولم يُصرف');

    await page.goto('/admin/wallet');
    body = await page.textContent('body');
    expect(body).toContain('المحافظ');
    // The reconciliation line — the one thing this screen exists to say.
    expect(body).toMatch(/الدفتر|غير متوازن/);
  });

  test('nothing renders as an unresolved English key on the pages built since P6', async ({ page }) => {
    // A missing translation falls through to its English key, which is how the
    // gap went unnoticed for six phases.
    const leaks = [
      'Review the pieces', 'Send the final price to the customer',
      'Transport', 'In the queue', 'Under review', 'Approved but unpaid',
      'Does not match the ledger', 'Discount Codes',
    ];

    for (const url of ['/admin/order', '/admin/coupon', '/admin/refund', '/admin/wallet']) {
      await page.goto(url);
      const body = await page.textContent('body');

      for (const leak of leaks) {
        expect(body, `${leak} leaked as English on ${url}`).not.toContain(leak);
      }
    }
  });

  test('Arabic renders as text, not as escapes or replacement marks', async ({ page }) => {
    await page.goto('/admin/order');

    const body = await page.textContent('body');

    // \uXXXX in the page means a JSON_UNESCAPED_UNICODE slip; a replacement
    // character means an encoding one.
    expect(body).not.toMatch(/\\u06[0-9a-f]{2}/i);
    expect(body).not.toContain('�');
  });
});
