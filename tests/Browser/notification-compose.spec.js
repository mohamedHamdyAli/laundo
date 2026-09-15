import { test, expect } from '@playwright/test';
import { ACCOUNTS, login } from './helpers.js';

/**
 * «إرسال إشعار» — the one screen in the panel that raises a notification with no
 * business action behind it.
 *
 * **Everything here goes through select2, and that is the point.**
 * `footer_script` turns every `select.form-select` in this panel into one, so the
 * native `<select>` is hidden and what a person actually clicks is a widget. The
 * first version of this spec drove the native element with `page.selectOption()`
 * — which sets the value and fires a native `change` — and it passed green while
 * the real screen was broken: select2 announces a change with a **jQuery** event,
 * which `addEventListener('change')` never hears, so choosing «Drivers» left the
 * recipient list offering customers.
 *
 * That is the same mistake as asserting on `style.display` for an element the
 * vendor CSS hides with `!important`: testing the thing underneath instead of the
 * thing a person sees. So these tests click the widget and read the widget.
 *
 * Anything this spec sends is titled so nobody reading the log mistakes it for a
 * real message.
 */

const PROBE_TITLE = 'BROWSER TEST — please ignore';

/** The widget select2 put in front of a native select. */
function widget(page, selectId) {
  return page.locator(`#${selectId} + .select2-container`);
}

/** What the closed control is showing right now. */
function shown(page, selectId) {
  return widget(page, selectId).locator('.select2-selection__rendered');
}

async function open(page, selectId) {
  await widget(page, selectId).locator('.select2-selection').click();
  await expect(page.locator('.select2-results__option').first()).toBeVisible();
}

/** Every line the open dropdown is offering. */
async function offered(page, selectId) {
  await open(page, selectId);
  const rows = await page.locator('.select2-results__option').allTextContents();
  await page.keyboard.press('Escape');

  return rows.map((row) => row.trim());
}

async function choose(page, selectId, text) {
  await open(page, selectId);
  await page.locator('.select2-results__option', { hasText: text }).first().click();
}

async function chooseByIndex(page, selectId, index) {
  await open(page, selectId);
  await page.locator('.select2-results__option').nth(index).click();
}

test.describe('sending a notification by hand', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/notification/compose');
    // select2 initialises on DOM ready; without waiting for it the first click
    // lands on a control that is still a plain select.
    await expect(widget(page, 'audience')).toBeVisible();
  });

  test('changing the audience rebuilds the recipient list', async ({ page }) => {
    /*
     * The regression this file exists for. Reported from production: audience
     * read «Drivers» while the recipient list still offered eleven customers.
     */
    const customers = await offered(page, 'target');
    expect(customers[0]).toMatch(/customer/i);

    await choose(page, 'audience', 'Drivers');
    await expect(shown(page, 'audience')).toHaveText('Drivers');

    const drivers = await offered(page, 'target');

    expect(drivers[0]).toMatch(/driver/i);
    expect(drivers[0]).not.toMatch(/customer/i);
    expect(drivers).not.toEqual(customers);
  });

  test('the control shows the person it is about to write to', async ({ page }) => {
    // Rebuilding the options underneath select2 changes nothing on screen until
    // it is told to re-read them — so the closed control could name somebody
    // from the previous audience while the form posted somebody else.
    await choose(page, 'audience', 'Laundries');

    const listed = await offered(page, 'target');
    const rendered = (await shown(page, 'target').textContent()).trim();

    expect(listed).toContain(rendered);
  });

  test('the everyone option carries its count', async ({ page }) => {
    // The number that decides whether to press send.
    const rows = await offered(page, 'target');

    expect(rows[0]).toMatch(/\(\d+\)/);
  });

  test('changing the audience keeps what has been typed', async ({ page }) => {
    // The fault this panel was recently fixed for: a form that throws away the
    // message because something else on the page changed.
    await page.fill('#title', PROBE_TITLE);
    await page.fill('#body', 'Typed before the audience changed.');

    await choose(page, 'audience', 'Laundries');

    await expect(page.locator('#title')).toHaveValue(PROBE_TITLE);
    await expect(page.locator('#body')).toHaveValue('Typed before the audience changed.');
  });

  test('a broadcast asks before it goes, and a refusal sends nothing', async ({ page }) => {
    await page.fill('#title', PROBE_TITLE);
    await page.fill('#body', 'This one should never be sent.');
    await chooseByIndex(page, 'target', 0); // «Every …»

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
    const rows = await offered(page, 'target');
    test.skip(rows.length < 2, 'no customers on this install to write to');

    // Index 1 is the first real person; index 0 is «everyone».
    await chooseByIndex(page, 'target', 1);
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
