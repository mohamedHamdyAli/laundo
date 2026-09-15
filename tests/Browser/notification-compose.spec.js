import { test, expect } from '@playwright/test';
import { ACCOUNTS, login } from './helpers.js';

/**
 * «إرسال إشعار» — the one screen in the panel that raises a notification with no
 * business action behind it.
 *
 * The behaviour worth driving in a browser rather than in PHPUnit is the pair of
 * selects: the recipient list is rebuilt client-side when the audience changes,
 * specifically so that switching from customers to drivers does **not** throw
 * away the message already typed. That was the whole point of the recent
 * form-validation work and it is invisible to a feature test.
 *
 * Anything this spec sends is titled so nobody reading the log mistakes it for a
 * real message.
 */

const PROBE_TITLE = 'BROWSER TEST — please ignore';

test.describe('sending a notification by hand', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/notification/compose');
  });

  test('the recipient list is built for the chosen audience', async ({ page }) => {
    const target = page.locator('#target');

    // Always an «everyone» option, and it carries the count — the number that
    // decides whether to press send.
    await expect(target.locator('option').first()).toContainText(/\(\d+\)/);

    const customers = await target.locator('option').allTextContents();

    await page.selectOption('#audience', 'driver');
    const drivers = await target.locator('option').allTextContents();

    expect(drivers).not.toEqual(customers);
  });

  test('changing the audience keeps what has been typed', async ({ page }) => {
    // The fault this panel was recently fixed for: a form that throws away the
    // message because something else on the page changed.
    await page.fill('#title', PROBE_TITLE);
    await page.fill('#body', 'Typed before the audience changed.');

    await page.selectOption('#audience', 'laundry');

    await expect(page.locator('#title')).toHaveValue(PROBE_TITLE);
    await expect(page.locator('#body')).toHaveValue('Typed before the audience changed.');
  });

  test('a broadcast asks before it goes, and a refusal sends nothing', async ({ page }) => {
    await page.fill('#title', PROBE_TITLE);
    await page.fill('#body', 'This one should never be sent.');
    await page.selectOption('#target', 'all');

    let asked = false;
    page.on('dialog', async (dialog) => {
      asked = true;
      expect(dialog.message()).toMatch(/\d+/);
      await dialog.dismiss();
    });

    await page.click('#sendNotification');
    await page.waitForTimeout(500);

    expect(asked).toBe(true);
    // Dismissed, so the form must still be sitting there unposted.
    await expect(page).toHaveURL(/\/admin\/notification\/compose/);
  });

  test('one person can be sent a message and it appears in the log', async ({ page }) => {
    const target = page.locator('#target');

    // The second option is the first real person; the first is «everyone».
    const options = await target.locator('option').all();
    test.skip(options.length < 2, 'no customers on this install to write to');

    await target.selectOption({ index: 1 });
    await page.fill('#title', PROBE_TITLE);
    await page.fill('#body', 'Sent by the browser suite. Safe to ignore.');

    await page.click('#sendNotification');
    await page.waitForURL(/\/admin\/notification($|\?)/);

    // The log is the point: a message that was sent and not recorded is the one
    // thing this screen exists to make impossible.
    await expect(page.locator('.data-stack')).toContainText(PROBE_TITLE);
    await expect(page.locator('.data-stack')).toContainText('Sent by');
  });

  test('the message field will not take an empty send', async ({ page }) => {
    await page.click('#sendNotification');

    // form-validation.js posts in the background and paints the 422 in place,
    // so the page must not have navigated.
    await expect(page).toHaveURL(/\/admin\/notification\/compose/);
    await expect(page.locator('.js-field-error, .is-invalid').first()).toBeVisible();
  });
});
