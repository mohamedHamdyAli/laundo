import { test, expect } from '@playwright/test';
import { ACCOUNTS, login, sidebarLabels } from './helpers.js';

/**
 * The Orders dashboard in a real browser.
 *
 * The PHP suite already proves the tenant scoping and the assignment rules. What
 * it cannot prove is that the pages render, that the AJAX search and the status
 * filter actually fire and repaint the table, and that the detail page draws the
 * pricing and history a person needs to read.
 *
 * These tests expect the dev fixtures to be present:
 *
 *   php artisan db:seed --class=DevFixturesSeeder
 *
 * and at least one order to exist. Orders are created by customers through the
 * API, so a run against an empty orders table asserts the empty state instead of
 * the populated one rather than failing — the pages must be correct either way.
 */

test.describe('Orders — super admin', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
  });

  test('the sidebar offers Orders', async ({ page }) => {
    const labels = await sidebarLabels(page);
    expect(labels.some((l) => /orders|الطلبات/i.test(l))).toBeTruthy();
  });

  test('the list page renders with its three counters', async ({ page }) => {
    await page.goto('/admin/order');

    await expect(page.locator('.card-title')).toContainText(/Orders|الطلبات/i);

    // All / Active / Unassigned — the third is the triage queue, so it has to be
    // visible without hunting.
    const body = await page.textContent('body');
    expect(body).toMatch(/All Orders|كل الطلبات/i);
    expect(body).toMatch(/Active|نشط/i);
    expect(body).toMatch(/Unassigned|غير مُعيَّن|غير معين/i);

    await expect(page.locator('#order-table-body')).toBeVisible();
  });

  test('the table has every column the operator reads', async ({ page }) => {
    await page.goto('/admin/order');

    const headers = await page.locator('#table_list thead th').allTextContents();
    const joined = headers.join(' | ');

    for (const column of [/Order|الطلب/i, /Customer|العميل/i, /Service|الخدمة/i,
      /Laundry|المغسلة/i, /Status|الحالة/i, /Total|الإجمالي/i]) {
      expect(joined).toMatch(column);
    }
  });

  test('AJAX search repaints the table', async ({ page }) => {
    await page.goto('/admin/order');

    const rowsBefore = await page.locator('#order-table-body tr').count();

    // pressSequentially, not fill: setupAjaxSearch listens on keyup, which
    // page.fill() never emits.
    const search = page.locator('#orderSearchInput');
    const response = page.waitForResponse((r) => r.url().includes('/admin/order/search'));
    await search.pressSequentially('zzz-no-such-order');
    await response;

    await expect(page.locator('#order-table-body')).toContainText(/No data found|لا توجد/i);

    // Clearing it brings the list back.
    const back = page.waitForResponse((r) => r.url().includes('/admin/order/search'));
    await search.fill('');
    await search.pressSequentially(' ');
    await back;

    const rowsAfter = await page.locator('#order-table-body tr').count();
    expect(rowsAfter).toBeGreaterThanOrEqual(Math.min(rowsBefore, 1));
  });

  test('the status filter hits the same endpoint and composes with the term', async ({ page }) => {
    await page.goto('/admin/order');

    const request = page.waitForRequest((r) => r.url().includes('/admin/order/search'));
    await page.selectOption('#orderStatusFilter', 'cancelled');
    const fired = await request;

    const url = new URL(fired.url());
    expect(url.searchParams.get('status')).toBe('cancelled');
    // The term travels with it rather than being dropped.
    expect(url.searchParams.has('query')).toBeTruthy();

    await expect(page.locator('#order-table-body')).toBeVisible();
  });

  test('an order detail page shows pricing, pieces and history', async ({ page }) => {
    await page.goto('/admin/order');

    const firstView = page.locator('#order-table-body a[href*="/admin/order/show/"]').first();

    if (await firstView.count() === 0) {
      test.skip(true, 'No orders in the dev database — place one through the API first.');
    }

    await firstView.click();
    await page.waitForLoadState('networkidle');

    const body = await page.textContent('body');
    expect(body).toMatch(/Pricing|السعر|التسعير/i);
    expect(body).toMatch(/Pieces|القطع/i);
    expect(body).toMatch(/History|السجل|التاريخ/i);
    expect(body).toMatch(/Summary|الملخص/i);

    // The estimated total is always present; the final one only after review.
    expect(body).toMatch(/Estimated total|الإجمالي المتوقع/i);

    // And the reminder that the prices are historical, not live.
    expect(body).toMatch(/agreed when the order was placed|not current prices|المتفق عليها/i);
  });

  test('there is no create or delete control for orders', async ({ page }) => {
    await page.goto('/admin/order');

    // An order is a customer's agreement. An operator must not be able to invent
    // or erase one from here.
    await expect(page.locator('a[href*="/admin/order/create"]')).toHaveCount(0);
    await expect(page.locator('form[action*="/admin/order/delete"]')).toHaveCount(0);
  });
});

test.describe('Orders — tenant isolation in the browser', () => {
  test('a laundry owner sees the Orders page but only their own rows', async ({ page }) => {
    await login(page, ACCOUNTS.ownerA);
    await page.goto('/admin/order');

    await expect(page.locator('#order-table-body')).toBeVisible();

    // Whatever is listed, none of it may belong to laundry B.
    const table = await page.textContent('#order-table-body');
    expect(table).not.toMatch(/Laundry B|مغسلة B/i);
  });

  test('a laundry owner cannot reach an order that is not theirs', async ({ page }) => {
    // Collect the ids the super admin can see...
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/order');

    const links = await page.locator('#order-table-body a[href*="/admin/order/show/"]')
      .evaluateAll((nodes) => nodes.map((n) => n.getAttribute('href')));

    if (links.length === 0) {
      test.skip(true, 'No orders in the dev database.');
    }

    // ...then try every one of them as owner B, and count how many render.
    await page.context().clearCookies();
    await login(page, ACCOUNTS.ownerB);

    let rendered = 0;
    let notFound = 0;

    for (const href of links.slice(0, 6)) {
      const response = await page.goto(href);
      if (response.status() === 404) {
        notFound += 1;
      } else if (response.ok()) {
        rendered += 1;
      }
    }

    // At least one of the admin's orders must be invisible to this tenant —
    // otherwise the scope is not doing anything.
    expect(notFound + rendered).toBeGreaterThan(0);
    expect(notFound).toBeGreaterThan(0);
  });

  test('a customer account is refused the orders dashboard', async ({ page }) => {
    await login(page, ACCOUNTS.customer);

    const response = await page.goto('/admin/order');

    // EnsureDashboardRole rejects an `app`-type role outright. The 403 renders at
    // the same URL — Laravel does not redirect — so the status and the absence of
    // the orders table are what prove it, not a change of address.
    expect(response.status()).toBe(403);
    await expect(page.locator('#order-table-body')).toHaveCount(0);
  });
});

test.describe('Delivery rates are reachable from the dashboard', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
  });

  test('a zone form exposes its per-km rate and minimum', async ({ page }) => {
    await page.goto('/admin/zone');

    const edit = page.locator('a[href*="/admin/zone/edit/"]').first();

    if (await edit.count() === 0) {
      test.skip(true, 'No zones seeded.');
    }

    await edit.click();
    await page.waitForLoadState('networkidle');

    // Without these fields the column exists but nobody can ever set it, and
    // every delivery fee comes back «unknown».
    await expect(page.locator('input[name="price_per_km"]')).toBeVisible();
    await expect(page.locator('input[name="min_delivery_fee"]')).toBeVisible();
  });

  test('a laundry form exposes the coordinates fees are measured from', async ({ page }) => {
    await page.goto('/admin/laundry');

    const edit = page.locator('a[href*="/admin/laundry/edit/"]').first();

    if (await edit.count() === 0) {
      test.skip(true, 'No laundries seeded.');
    }

    await edit.click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('input[name="lat"]')).toBeVisible();
    await expect(page.locator('input[name="lng"]')).toBeVisible();
  });

  test('saving a zone rate persists it', async ({ page }) => {
    await page.goto('/admin/zone');

    const edit = page.locator('a[href*="/admin/zone/edit/"]').first();

    if (await edit.count() === 0) {
      test.skip(true, 'No zones seeded.');
    }

    await edit.click();
    await page.waitForLoadState('networkidle');

    const url = page.url();
    const original = await page.inputValue('input[name="price_per_km"]');

    await page.fill('input[name="price_per_km"]', '7.25');
    await page.locator('form').filter({ has: page.locator('input[name="price_per_km"]') })
      .locator('button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    await page.goto(url);
    expect(await page.inputValue('input[name="price_per_km"]')).toBe('7.25');

    // Put it back, so the run leaves the dev database as it found it.
    await page.fill('input[name="price_per_km"]', original || '5.00');
    await page.locator('form').filter({ has: page.locator('input[name="price_per_km"]') })
      .locator('button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');
  });
});
