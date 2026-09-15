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

  test('the way out sits below the notifications, not above them', async ({ page }) => {
    /*
     * Rows were appended to a list that already ended with «See all
     * notifications», so the way out of the dropdown rendered as a heading
     * above the things it was a way out of.
     */
    await page.click('#notificationDropdownToggle');

    const order = await page.locator('#notification-list > li').evaluateAll((els) =>
      els.filter((el) => !el.hidden).map((el) => el.className.split(' ')[0])
    );

    expect(order[0]).toBe('notification-head');
    expect(order[order.length - 1]).toBe('notification-foot');
    expect(order).toContain('notification-item');
  });

  test('a row reads as three lines, not one run-on sentence', async ({ page }) => {
    /*
     * `app.css` sets `.dropdown-item { display: flex; white-space: nowrap }`,
     * which laid the title, the message and the timestamp side by side:
     * «A task is waiting for a driverOrder 10029 has had no driver for over 2
     * hours.4 hours ago». Measured by geometry rather than by reading the
     * stylesheet back — the fault was a layout, so a layout is what must be
     * asserted.
     */
    await page.click('#notificationDropdownToggle');

    const row = page.locator('.notification-row').first();
    await expect(row).toBeVisible();

    const boxes = await row.locator('.notification-body > span').evaluateAll((els) =>
      els.map((el) => el.getBoundingClientRect().top)
    );

    expect(boxes.length).toBeGreaterThanOrEqual(2);

    // Each line starts lower than the one before it. Side by side they would
    // share a top edge.
    for (let i = 1; i < boxes.length; i++) {
      expect(boxes[i]).toBeGreaterThan(boxes[i - 1]);
    }

    await expect(row).toHaveCSS('white-space', 'normal');
  });

  test('a notification goes somewhere, and one with nowhere to go is not a link', async ({ page }) => {
    await page.click('#notificationDropdownToggle');

    const rows = page.locator('.notification-row');
    const count = await rows.count();
    test.skip(count === 0, 'nothing in this inbox');

    let linked = 0;

    for (let i = 0; i < count; i++) {
      const href = await rows.nth(i).getAttribute('href');

      if (href === null) {
        // No destination: it must not pretend. An `href="#"` scrolls the page
        // to the top and reads as a broken link.
        await expect(rows.nth(i)).toHaveAttribute('role', 'button');
        continue;
      }

      expect(href).not.toBe('#');
      linked++;
    }

    expect(linked).toBeGreaterThan(0);
  });

  test('each line points whichever way its own words run', async ({ page }) => {
    // The panel is operated in Arabic while plenty of notification copy is
    // English, so a single paragraph direction puts an English full stop on the
    // wrong side.
    await page.click('#notificationDropdownToggle');

    const dirs = await page.locator('.notification-body > span').evaluateAll((els) =>
      els.map((el) => el.getAttribute('dir'))
    );

    expect(dirs.length).toBeGreaterThan(0);
    dirs.forEach((d) => expect(d).toBe('auto'));
  });

  test('there is a way to clear the count without opening every one', async ({ page }) => {
    await page.click('#notificationDropdownToggle');

    const button = page.locator('#notification-mark-all');
    await expect(button).toBeVisible();

    await button.click();
    await page.waitForResponse((r) => r.url().includes('my-notifications/unread'));

    await expect(page.locator('#notification-badge')).toBeHidden();
    // The menu must still be open — the point is watching it clear.
    await expect(page.locator('#notification-list')).toBeVisible();
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
