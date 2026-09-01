import { test, expect } from '@playwright/test';
import { ACCOUNTS, login, sidebarLabels } from './helpers.js';

/**
 * The money screens in a real browser.
 *
 * These pages exist because the services were unreachable: operations had no way
 * to create a coupon or approve a refund except through code. So the first thing
 * worth proving is simply that they are reachable — from the sidebar, by a person,
 * without a URL typed by hand.
 *
 * The second is the wallet's reconciliation line. A cached balance that has
 * drifted from its own ledger is invisible until somebody disputes a figure, and
 * this is the only place it is ever stated.
 */

test.describe('The money group', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
  });

  test('the sidebar offers all three screens', async ({ page }) => {
    const labels = await sidebarLabels(page);
    const joined = labels.join(' | ');

    expect(joined).toMatch(/Discount Codes|أكواد الخصم/i);
    expect(joined).toMatch(/Refunds|الاستردادات|طلبات الاسترداد/i);
    expect(joined).toMatch(/Wallets|المحافظ/i);
  });

  test('the coupon form explains what the value means for each type', async ({ page }) => {
    await page.goto('/admin/coupon/create');

    await expect(page.locator('input[name="code"]')).toBeVisible();
    await expect(page.locator('select[name="type"]')).toBeVisible();

    // The value means different things for the two types, and a form that does
    // not say so invites somebody to type 50 meaning fifty pounds.
    await page.selectOption('#coupon-type', 'fixed');
    const fixedHint = await page.locator('#value-hint').textContent();

    await page.selectOption('#coupon-type', 'percentage');
    const percentHint = await page.locator('#value-hint').textContent();

    expect(fixedHint.trim()).not.toBe('');
    expect(percentHint.trim()).not.toBe('');
    expect(fixedHint).not.toBe(percentHint);
  });

  test('a code created here appears in the list', async ({ page }) => {
    const code = 'PWTEST' + Math.floor(Math.random() * 100000);

    await page.goto('/admin/coupon/create');
    await page.fill('input[name="code"]', code);
    await page.fill('input[name="value"]', '15');
    await page.locator('input[name^="name["]').first().fill('Playwright test');
    await page.locator('form.store button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('#coupon-table-body')).toContainText(code);

    // Leave the dev database as it was found.
    const row = page.locator('#coupon-table-body tr', { hasText: code }).first();
    const del = row.locator('form[action*="/admin/coupon/delete/"] button, a[href*="/admin/coupon/delete/"]');

    if (await del.count() > 0) {
      page.once('dialog', (d) => d.accept());
      await del.first().click();
      await page.waitForLoadState('networkidle');
    }
  });

  test('the coupon search repaints the table', async ({ page }) => {
    await page.goto('/admin/coupon');

    const search = page.locator('#couponSearchInput');
    const response = page.waitForResponse((r) => r.url().includes('/admin/coupon/search'));

    // pressSequentially, not fill: setupAjaxSearch listens on keyup.
    await search.pressSequentially('zzz-no-such-code');
    await response;

    await expect(page.locator('#coupon-table-body')).toContainText(/No data found|لا توجد/i);
  });

  test('the refund queue shows both counters', async ({ page }) => {
    await page.goto('/admin/refund');

    const body = await page.textContent('body');
    expect(body).toMatch(/Under review|قيد المراجعة/i);
    // Approved but never paid out — without this counter it disappears.
    expect(body).toMatch(/Approved but unpaid|موافق عليه/i);

    await expect(page.locator('#refund-table-body')).toBeVisible();
  });

  test('the refund status filter is a real navigation', async ({ page }) => {
    await page.goto('/admin/refund');

    await page.selectOption('#refundStatusFilter', 'all');
    await page.waitForLoadState('networkidle');

    // A reload, not a repaint: the approve modals live inside the rows, and
    // swapping the tbody would strip their handlers.
    expect(page.url()).toContain('status=all');
    await expect(page.locator('#refund-table-body')).toBeVisible();
  });

  test('the wallet list states whether the ledger balances', async ({ page }) => {
    await page.goto('/admin/wallet');

    const body = await page.textContent('body');

    expect(body).toMatch(/Total held|إجمالي/i);
    expect(body).toMatch(/Out of balance|غير متوازن/i);
    // The point of the screen: a drift is stated, not left to be discovered.
    expect(body).toMatch(/Ledger|الدفتر/i);
  });

  test('a wallet detail page offers an adjustment that demands a reason', async ({ page }) => {
    await page.goto('/admin/wallet');

    const view = page.locator('#wallet-table-body a[href*="/admin/wallet/show/"]').first();
    test.skip(await view.count() === 0, 'No wallet holds money in the dev database.');

    await view.click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('select[name="direction"]')).toBeVisible();
    await expect(page.locator('input[name="amount"]')).toBeVisible();

    // An adjustment nobody explained is one nobody can defend later.
    const note = page.locator('textarea[name="note"]');
    await expect(note).toBeVisible();
    await expect(note).toHaveAttribute('required', '');

    // And the reconciliation is stated on every visit.
    const body = await page.textContent('body');
    expect(body).toMatch(/Balanced|Does not match the ledger|متوازن/i);
  });

  test('no screen offers a way to set a balance directly', async ({ page }) => {
    await page.goto('/admin/wallet');

    const view = page.locator('#wallet-table-body a[href*="/admin/wallet/show/"]').first();
    test.skip(await view.count() === 0, 'No wallet holds money in the dev database.');

    await view.click();
    await page.waitForLoadState('networkidle');

    // Nothing anywhere sets a balance — every change is a transaction.
    await expect(page.locator('input[name="balance"]')).toHaveCount(0);
    await expect(page.locator('input[name="pending_balance"]')).toHaveCount(0);
  });
});

test.describe('Who may see the money screens', () => {
  test('a laundry owner is refused', async ({ page }) => {
    await login(page, ACCOUNTS.ownerA);

    for (const url of ['/admin/coupon', '/admin/refund', '/admin/wallet']) {
      const response = await page.goto(url);
      expect(response.status()).toBe(403);
    }
  });

  test('a customer is refused', async ({ page }) => {
    await login(page, ACCOUNTS.customer);

    const response = await page.goto('/admin/coupon');
    expect(response.status()).toBe(403);
  });
});
