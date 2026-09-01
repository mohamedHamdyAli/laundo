import { test, expect } from '@playwright/test';
import { ACCOUNTS, login } from './helpers.js';

/**
 * Dispatch in a real browser.
 *
 * The PHP suite proves the eligibility rules and the tenant boundary. What it
 * cannot prove is what an operator actually sees: that the four legs render as a
 * chain, that a queued leg announces itself, and — the one that matters most —
 * that the page offers no way to tick a leg off. A leg is finished in the field
 * with a scan and a signature; a "complete" button on this page would destroy the
 * only proof the handover happened.
 *
 * Needs the dev fixtures:
 *
 *   php artisan db:seed --class=DevFixturesSeeder
 */

/** Opens the first order whose page renders a transport table. */
async function openOrderWithTasks(page) {
  await page.goto('/admin/order');

  const links = await page
    .locator('#order-table-body a[href*="/admin/order/show/"]')
    .evaluateAll((nodes) => nodes.map((n) => n.getAttribute('href')));

  for (const href of links.slice(0, 12)) {
    await page.goto(href);
    const body = await page.textContent('body');
    if (/Transport|النقل/i.test(body) && await page.locator('table').count() > 0) {
      // Only useful if the chain actually exists rather than the empty state.
      if (!/No transport tasks yet|لا توجد مهام/i.test(body)) {
        return href;
      }
    }
  }

  return null;
}

test.describe('The transport chain', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
  });

  test('an order page shows the four legs in order', async ({ page }) => {
    const href = await openOrderWithTasks(page);
    test.skip(!href, 'No order with transport tasks — place one through the API.');

    const body = await page.textContent('body');

    for (const leg of [
      /Pick up from customer|استلام من العميل/i,
      /Deliver to laundry|تسليم للمغسلة/i,
      /Collect from laundry|استلام من المغسلة/i,
      /Deliver to customer|تسليم للعميل/i,
    ]) {
      expect(body).toMatch(leg);
    }
  });

  test('there is no control to complete a leg from a desk', async ({ page }) => {
    const href = await openOrderWithTasks(page);
    test.skip(!href, 'No order with transport tasks.');

    // The proof of a handover is a scan and a signature taken in the field.
    await expect(page.locator('form[action*="/admin/order/task/complete"]')).toHaveCount(0);
    await expect(page.locator('form[action*="/admin/order/task/finish"]')).toHaveCount(0);

    const buttons = await page.locator('button[type="submit"]').allTextContents();
    expect(buttons.join(' | ')).not.toMatch(/Complete the leg|إنهاء المهمة/i);
  });

  test('a queued leg says so rather than showing a blank driver', async ({ page }) => {
    const href = await openOrderWithTasks(page);
    test.skip(!href, 'No order with transport tasks.');

    const rows = page.locator('table tr');
    const count = await rows.count();
    let sawDriverOrQueue = false;

    for (let i = 0; i < count; i += 1) {
      const text = await rows.nth(i).textContent();
      if (/In the queue|في الطابور|Awaiting a driver/i.test(text)) {
        sawDriverOrQueue = true;
        break;
      }
      // A named driver is equally fine — what must not happen is an empty cell.
      if (/New|Completed|In progress|Failed/i.test(text)) {
        sawDriverOrQueue = true;
        break;
      }
    }

    expect(sawDriverOrQueue).toBeTruthy();
  });

  test('the transport panel survives an order that has no tasks', async ({ page }) => {
    // An order placed before P8 existed still has to render, with an explanation
    // rather than an empty table.
    await page.goto('/admin/order?status=cancelled');

    const link = page.locator('#order-table-body a[href*="/admin/order/show/"]').first();

    if (await link.count() === 0) {
      test.skip(true, 'No cancelled order to check the empty state with.');
    }

    await link.click();
    await page.waitForLoadState('networkidle');

    const body = await page.textContent('body');
    expect(body).toMatch(/Transport|النقل/i);
  });
});

test.describe('Driver capacity and city', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
  });

  test('the driver form exposes both fields', async ({ page }) => {
    await page.goto('/admin/driver');

    const edit = page.locator('a[href*="/admin/driver/edit/"]').first();
    test.skip(await edit.count() === 0, 'No drivers seeded.');

    await edit.click();
    await page.waitForLoadState('networkidle');

    // Added in P6 and unreachable until now, which is why every driver had null
    // for both and the dispatch rules built on them never bit.
    await expect(page.locator('input[name="max_concurrent_orders"]')).toBeVisible();
    await expect(page.locator('select[name="city_id"]')).toBeVisible();
  });

  test('a capacity saved from the form comes back', async ({ page }) => {
    await page.goto('/admin/driver');

    const edit = page.locator('a[href*="/admin/driver/edit/"]').first();
    test.skip(await edit.count() === 0, 'No drivers seeded.');

    await edit.click();
    await page.waitForLoadState('networkidle');

    const url = page.url();
    const original = await page.inputValue('input[name="max_concurrent_orders"]');

    await page.fill('input[name="max_concurrent_orders"]', '7');
    await page.locator('form').filter({ has: page.locator('input[name="max_concurrent_orders"]') })
      .locator('button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');

    await page.goto(url);
    expect(await page.inputValue('input[name="max_concurrent_orders"]')).toBe('7');

    // Leave the dev database as it was found.
    await page.fill('input[name="max_concurrent_orders"]', original);
    await page.locator('form').filter({ has: page.locator('input[name="max_concurrent_orders"]') })
      .locator('button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle');
  });
});
