import { test, expect } from '@playwright/test';
import { ACCOUNTS, login } from './helpers.js';

/**
 * The laundry's review screen in a real browser.
 *
 * The PHP suite already proves the pricing, the state rules and the tenant
 * boundary. What it cannot prove is the part that only exists in the browser: the
 * live total that recalculates as somebody types a count, and the difference
 * against what the customer agreed to. That number is the one a person actually
 * makes a decision on, so a mistake in it is a mistake in the business, not in
 * the markup.
 *
 * Needs the dev fixtures, which park one order at `picked_up`:
 *
 *   php artisan db:seed --class=DevFixturesSeeder
 */

/** Finds an order whose detail page renders the review form. */
async function openReviewableOrder(page) {
  await page.goto('/admin/order?status=picked_up');

  const links = await page
    .locator('#order-table-body a[href*="/admin/order/show/"]')
    .evaluateAll((nodes) => nodes.map((n) => n.getAttribute('href')));

  for (const href of links) {
    await page.goto(href);
    if (await page.locator('#review-table').count() > 0) {
      return href;
    }
  }

  return null;
}

test.describe('The review screen', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
  });

  test('renders with the customer count beside the field to correct it', async ({ page }) => {
    const href = await openReviewableOrder(page);
    test.skip(!href, 'No order is waiting to be reviewed — reseed DevFixturesSeeder.');

    const headers = await page.locator('#review-table thead th').allTextContents();
    const joined = headers.join(' | ');

    // The whole design of this screen: correcting a list, not building one.
    expect(joined).toMatch(/Customer said|قال العميل/i);
    expect(joined).toMatch(/Actual count|العدد الفعلي/i);
    expect(joined).toMatch(/Unit Price|سعر/i);

    await expect(page.locator('textarea[name="note"]')).toBeVisible();
    await expect(page.locator('.review-qty').first()).toBeVisible();
  });

  test('offers more pieces than the customer ordered', async ({ page }) => {
    const href = await openReviewableOrder(page);
    test.skip(!href, 'No order is waiting to be reviewed.');

    // «تم العثور على قطعة إضافية أثناء المراجعة» is the case this screen exists
    // for, so a form limited to what was ordered could never record it.
    const rows = await page.locator('#review-table tbody tr').count();
    expect(rows).toBeGreaterThan(1);
  });

  test('the total follows the counts as they are typed', async ({ page }) => {
    const href = await openReviewableOrder(page);
    test.skip(!href, 'No order is waiting to be reviewed.');

    const first = page.locator('.review-qty').first();
    const price = parseFloat(await first.getAttribute('data-price'));

    await first.fill('1');
    await first.dispatchEvent('input');
    const oneTotal = parseFloat(await page.locator('#review-total').textContent());

    await first.fill('3');
    await first.dispatchEvent('input');
    const threeTotal = parseFloat(await page.locator('#review-total').textContent());

    // Two more pieces, two more units of that price. Nothing else may move —
    // the delivery fee is carried over, not re-priced.
    expect(threeTotal - oneTotal).toBeCloseTo(price * 2, 2);
  });

  test('the difference against the estimate is shown and signed', async ({ page }) => {
    const href = await openReviewableOrder(page);
    test.skip(!href, 'No order is waiting to be reviewed.');

    const qty = page.locator('.review-qty').first();

    // A big count must read as more expensive than the customer agreed to.
    await qty.fill('40');
    await qty.dispatchEvent('input');
    const up = await page.locator('#review-difference').textContent();
    expect(up.trim()).toMatch(/^\+/);
    await expect(page.locator('#review-difference')).toHaveClass(/text-danger/);

    // And an empty one as cheaper.
    await qty.fill('0');
    await qty.dispatchEvent('input');
    const down = await page.locator('#review-difference').textContent();
    expect(down.trim()).toMatch(/^-/);
    await expect(page.locator('#review-difference')).toHaveClass(/text-success/);
  });

  test('a line total reflects its own row only', async ({ page }) => {
    const href = await openReviewableOrder(page);
    test.skip(!href, 'No order is waiting to be reviewed.');

    const row = page.locator('#review-table tbody tr').first();
    const qty = row.locator('.review-qty');
    const price = parseFloat(await qty.getAttribute('data-price'));

    await qty.fill('4');
    await qty.dispatchEvent('input');

    const lineTotal = parseFloat(await row.locator('.review-line-total').textContent());
    expect(lineTotal).toBeCloseTo(price * 4, 2);
  });
});

test.describe('Who may price an order', () => {
  test('a laundry owner sees the review form on their own order', async ({ page }) => {
    await login(page, ACCOUNTS.ownerA);

    // Ask the server for exactly the orders that can be reviewed, rather than
    // scanning the first few of everything — which is what made this test fail
    // the moment the dev database grew a few more orders.
    await page.goto('/admin/order?status=picked_up');
    const links = await page
      .locator('#order-table-body a[href*="/admin/order/show/"]')
      .evaluateAll((nodes) => nodes.map((n) => n.getAttribute('href')));

    test.skip(links.length === 0, 'Laundry A has no collected order.');

    let sawForm = false;

    for (const href of links) {
      await page.goto(href);
      if (await page.locator('#review-table').count() > 0) {
        sawForm = true;
        break;
      }
    }

    expect(sawForm).toBeTruthy();
  });

  test('laundry staff read the order but cannot price it', async ({ page }) => {
    await login(page, ACCOUNTS.staffA);

    await page.goto('/admin/order');
    const link = page.locator('#order-table-body a[href*="/admin/order/show/"]').first();

    test.skip(await link.count() === 0, 'Laundry A has no orders.');

    await link.click();
    await page.waitForLoadState('networkidle');

    // Staff hold order.view, not order.update — reading is the whole of it.
    await expect(page.locator('#review-table')).toHaveCount(0);
    await expect(page.locator('form[action*="/admin/order/review/"]')).toHaveCount(0);
  });
});
