import { test, expect } from '@playwright/test';
import { ACCOUNTS, login } from './helpers.js';

/**
 * The bell, which could not draw its own counter.
 *
 * The badge ships with the `hidden` attribute and the vendor `app.css` carries
 * `[hidden] { display: none !important }`. The script raised it with
 * `style.display = 'inline-block'` — an inline style, which cannot out-rank
 * `!important` from a stylesheet — so the counter was invisible whatever the
 * number behind it.
 *
 * That was the whole of «nothing ever arrives in the dashboard». The alerts had
 * been arriving and sitting unread for weeks (fifty of them on the live install,
 * the newest that morning) and the dropdown behind the bell was filling
 * correctly the entire time. Nothing said so, so nobody opened it.
 *
 * Asserted on `boundingBox()` rather than on the inline style, because the fault
 * was precisely a style that was set and had no effect.
 */

test.describe('the notification bell', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);

    // Armed *before* the navigation. The count is fetched the moment the script
    // runs and then every fifteen seconds, so waiting after `goto()` resolves
    // means waiting for the next poll — or, if the first one already landed,
    // waiting past the timeout for a response that has been and gone.
    const counted = page.waitForResponse((r) => r.url().includes('my-notifications/unread'));
    await page.goto('/admin/home');
    await counted;
  });

  test('an unread count is actually visible', async ({ page }) => {
    const badge = page.locator('#notification-badge');

    const count = await page.evaluate(async (url) => {
      const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      return (await response.json()).count;
    }, '/admin/my-notifications/unread');

    test.skip(count === 0, 'this install has nothing unread to show');

    await expect(badge).toBeVisible();
    await expect(badge).toHaveText(count > 99 ? '99+' : String(count));

    // The real assertion. `toBeVisible()` would pass on an element the browser
    // lays out and paints nowhere; a box with height is the thing a person sees.
    const box = await badge.boundingBox();
    expect(box).not.toBeNull();
    expect(box.height).toBeGreaterThan(0);
  });

  test('the dropdown lists what the badge is counting', async ({ page }) => {
    await page.click('#notificationDropdownToggle');

    const items = page.locator('#notification-list .notification-item');
    const count = await items.count();

    test.skip(count === 0, 'nothing in this inbox to list');

    await expect(items.first()).toBeVisible();
    // The «nothing here» row must give way once there is something.
    await expect(page.locator('#notification-empty')).toBeHidden();
  });

  test('there is a way out of a ten-item dropdown', async ({ page }) => {
    // An operations alert that scrolled past the tenth used to be unreachable.
    await page.click('#notificationDropdownToggle');

    const seeAll = page.locator('#notification-list a[href*="my-notifications"]');
    await expect(seeAll).toBeVisible();

    await seeAll.click();
    await expect(page).toHaveURL(/my-notifications/);
  });
});
