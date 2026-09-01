import { test, expect } from '@playwright/test';
import { ACCOUNTS, login, sidebarLabels, isRtl } from './helpers.js';

/**
 * The report screens in a real browser.
 *
 * The PHP suite already proves the arithmetic — that revenue counts paid orders,
 * that refunds are dated by payout, that receivables sit outside net. What it
 * cannot prove is the part a person actually touches: that the pages render at
 * all, that the date range survives a round trip through the URL so a report can
 * be bookmarked, that the CSV opens in Excel with Arabic intact, and that a
 * laundry owner is stopped at the two reports that are not tenant-scoped.
 *
 * That last one is the reason this file exists. Driver performance and operations
 * health read the tasks table, which carries no laundry scope, so nothing but the
 * permission gate stands between one laundry and another's drivers.
 */

test.describe('Reports as the super admin', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
  });

  test('the sidebar offers the reports group', async ({ page }) => {
    const joined = (await sidebarLabels(page)).join(' | ');

    expect(joined).toMatch(/Reports|التقارير/i);
  });

  test('all five reports render', async ({ page }) => {
    const pages = [
      ['/admin/report/revenue', /Revenue Report|تقرير الإيرادات/i],
      ['/admin/report/orders', /Orders Report|تقرير الطلبات/i],
      ['/admin/report/laundries', /Laundry Performance|أداء المغاسل/i],
      ['/admin/report/drivers', /Driver Performance|أداء المناديب/i],
      ['/admin/report/operations', /Operations Health|صحة التشغيل/i],
    ];

    for (const [url, heading] of pages) {
      const response = await page.goto(url);

      expect(response.status(), `${url} should render`).toBe(200);
      await expect(page.locator('.card-title').first()).toHaveText(heading);

      // A 200 that rendered an exception trace is still a broken page.
      await expect(page.locator('body')).not.toContainText('Whoops');
    }
  });

  test('the date range round-trips through the URL', async ({ page }) => {
    // A report you cannot link to is one somebody re-finds every morning, so the
    // form is a plain GET and the inputs must come back holding what was asked for.
    await page.goto('/admin/report/revenue?from=2026-01-01&to=2026-01-31');

    await expect(page.locator('input[name="from"]')).toHaveValue('2026-01-01');
    await expect(page.locator('input[name="to"]')).toHaveValue('2026-01-31');

    await page.fill('input[name="from"]', '2026-02-01');
    await page.fill('input[name="to"]', '2026-02-28');
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');

    expect(page.url()).toContain('from=2026-02-01');
    expect(page.url()).toContain('to=2026-02-28');
    await expect(page.locator('input[name="to"]')).toHaveValue('2026-02-28');
  });

  test('the shortcut buttons move the window', async ({ page }) => {
    await page.goto('/admin/report/revenue');

    await page.getByRole('link', { name: /Last 7 days|آخر 7 أيام/i }).click();
    await page.waitForLoadState('networkidle');

    const from = await page.locator('input[name="from"]').inputValue();
    const to = await page.locator('input[name="to"]').inputValue();
    const span = (new Date(to) - new Date(from)) / 86_400_000;

    expect(span).toBe(6); // seven days counted inclusively
  });

  test('a backwards range is corrected rather than shown as an empty month', async ({ page }) => {
    // Typing the dates the wrong way round returns nothing, which reads exactly
    // like a quiet month. The range object swaps them instead.
    await page.goto('/admin/report/revenue?from=2026-03-31&to=2026-03-01');

    // The form shows the corrected window, not the one that was typed — a report
    // covering a different range from the one on screen is worse than an error.
    await expect(page.locator('input[name="from"]')).toHaveValue('2026-03-01');
    await expect(page.locator('input[name="to"]')).toHaveValue('2026-03-31');
    await expect(page.locator('body')).not.toContainText('Whoops');
  });

  test('an absurd range does not hang the page', async ({ page }) => {
    // One bar per day, and these dates come off a URL people paste. Unclamped
    // this asks the browser to lay out 36,525 of them.
    const started = Date.now();
    const response = await page.goto('/admin/report/revenue?from=1900-01-01&to=2099-12-31');

    expect(response.status()).toBe(200);
    expect(Date.now() - started).toBeLessThan(15_000);

    await expect(page.locator('input[name="from"]')).not.toHaveValue('1900-01-01');
    await expect(page.locator('input[name="to"]')).toHaveValue('2099-12-31');
  });

  test('the CSV downloads with a BOM so Excel reads the Arabic', async ({ page }) => {
    await page.goto('/admin/report/revenue');

    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.getByRole('link', { name: /Export CSV|تصدير CSV/i }).click(),
    ]);

    expect(download.suggestedFilename()).toMatch(/\.csv$/);

    const stream = await download.createReadStream();
    const chunks = [];
    for await (const chunk of stream) chunks.push(chunk);
    const body = Buffer.concat(chunks);

    // Without these three bytes Excel opens the file as cp1252 and every Arabic
    // laundry name becomes mojibake.
    expect(body.subarray(0, 3)).toEqual(Buffer.from([0xef, 0xbb, 0xbf]));
    expect(body.length).toBeGreaterThan(3);
  });

  test('operations health names what is waiting rather than only counting it', async ({ page }) => {
    await page.goto('/admin/report/operations');

    // Every panel either lists rows or says plainly that there is nothing — a
    // blank card is indistinguishable from a card that failed to load.
    const body = await page.locator('section.section').textContent();

    expect(body).toMatch(/Waiting on a customer|بانتظار عميل/i);
    expect(body).toMatch(/Unassigned orders|طلبات غير مُعيَّنة/i);
    expect(body).toMatch(/Wallets out of balance|محافظ غير متوازنة/i);
  });
});

test.describe('Reports as a laundry owner', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.ownerA);
  });

  test('the three tenant-safe reports are reachable', async ({ page }) => {
    for (const url of ['/admin/report/revenue', '/admin/report/orders', '/admin/report/laundries']) {
      const response = await page.goto(url);

      expect(response.status(), `${url} should be allowed`).toBe(200);
    }
  });

  test('driver performance and operations health are refused', async ({ page }) => {
    // Not a styling choice: tasks are not tenant-scoped, so if these opened, one
    // laundry would read another's drivers and another's stuck orders.
    for (const url of ['/admin/report/drivers', '/admin/report/operations']) {
      const response = await page.goto(url);

      expect(response.status(), `${url} should be refused`).toBe(403);
    }
  });

  test('the sidebar does not advertise what the owner cannot open', async ({ page }) => {
    const joined = (await sidebarLabels(page)).join(' | ');

    expect(joined).not.toMatch(/Driver Performance|أداء المناديب/i);
    expect(joined).not.toMatch(/Operations Health|صحة التشغيل/i);
  });
});

test.describe('Reports in Arabic', () => {
  test('the revenue report holds its layout right-to-left', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);

    await page.goto('/admin/set-language/ar');
    await page.goto('/admin/report/revenue');

    expect(await isRtl(page)).toBe(true);

    const heading = await page.locator('.card-title').first().textContent();
    expect(heading).toMatch(/[؀-ۿ]/); // actually Arabic, not a fallback

    // The figures are the point of the page; a chart that overflows the card in
    // RTL hides them.
    const overflow = await page.evaluate(() => {
      return document.documentElement.scrollWidth > document.documentElement.clientWidth + 2;
    });
    expect(overflow, 'the page must not scroll sideways in RTL').toBe(false);

    await page.goto('/admin/set-language/en');
  });
});
