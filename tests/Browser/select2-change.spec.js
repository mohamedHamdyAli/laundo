import { test, expect } from '@playwright/test';
import { ACCOUNTS, login } from './helpers.js';

/**
 * Dropdowns whose change actually reaches the page.
 *
 * `footer_script` turns **every** `select.form-select` in this panel into a
 * select2. select2 announces a change with a **jQuery** event, and a listener
 * added with `addEventListener('change')` never hears one — so a handler bound
 * that way runs on page load, then never again however the field is set.
 *
 * Four screens were written that way and three of them were live and broken:
 * picking «Open a service» on an offer set the value and left the panel naming
 * the service hidden, so the thing the field exists to configure could not be
 * configured. It was reported as «الفيلتر مش شغال صح» on the notification
 * compose screen; the same defect was sitting on the offer, banner and driver
 * forms. (`map-picker` already handled it, and says so in a comment.)
 *
 * This spec is deliberately about the **class** of fault rather than one screen,
 * because the next `.form-select` somebody wires up will be written the same way.
 * It drives the widget a person actually clicks — never `page.selectOption()`,
 * which sets the hidden native element and fires a native event, passing green
 * while the real screen does nothing.
 */

function widget(page, selectId) {
  return page.locator(`#${selectId} + .select2-container`);
}

async function chooseByIndex(page, selectId, index) {
  await widget(page, selectId).locator('.select2-selection').click();
  await expect(page.locator('.select2-results__option').first()).toBeVisible();
  await page.locator('.select2-results__option').nth(index).click();
  await page.waitForTimeout(250);
}

/**
 * Which of a screen's dependent panels are on show.
 *
 * The selector differs per screen — the offer form marks them with
 * `data-target-for`, the banner form with two ids — so it is passed in rather
 * than assumed. A generic selector that matches nothing returns an empty string
 * for both readings and the comparison passes while proving nothing, which is
 * exactly how this test first went green on a broken page.
 */
async function panelState(page, selector) {
  const rows = await page.locator(selector).evaluateAll((els) =>
    els.map((el) => `${el.id || el.dataset.targetFor}=${el.hidden ? 'hidden' : 'shown'}`)
  );

  expect(rows.length, `no panels matched ${selector}`).toBeGreaterThan(0);

  return rows.join('|');
}

test.beforeEach(async ({ page }) => {
  await login(page, ACCOUNTS.superAdmin);
});

for (const screen of [
  {
    name: 'offer',
    url: '/admin/offer/create',
    select: 'offer-target-type',
    panels: '[data-target-for]',
  },
  {
    name: 'banner',
    url: '/admin/banner/create',
    select: 'banner-target-type',
    panels: '#banner-target-service-wrap, #banner-target-coupon-wrap',
  },
]) {
  test(`${screen.name}: choosing a target reveals the field that configures it`, async ({ page }) => {
    await page.goto(screen.url);
    await expect(widget(page, screen.select)).toBeVisible();

    const before = await panelState(page, screen.panels);
    expect(before, 'nothing should be on show before a target is chosen').not.toContain('=shown');

    // Index 1 rather than 0: the first option is «No action», which shows
    // nothing, so selecting it would prove nothing either.
    await chooseByIndex(page, screen.select, 1);

    const value = await page.locator(`#${screen.select}`).inputValue();
    const after = await panelState(page, screen.panels);

    expect(value).not.toBe('');
    expect(after).not.toBe(before);
    // Something the operator now has to fill in is actually on the page.
    expect(after).toContain('=shown');
  });
}

test('driver: choosing a city narrows the zone list', async ({ page }) => {
  await page.goto('/admin/driver/create');
  await expect(widget(page, 'driver-city')).toBeVisible();

  const visibleZones = () =>
    page.locator('[data-zone-city]').evaluateAll((els) =>
      els.filter((el) => !el.hidden).length
    );

  const before = await visibleZones();

  await chooseByIndex(page, 'driver-city', 1);

  const city = await page.locator('#driver-city').inputValue();
  test.skip(city === '', 'no cities seeded on this install');

  // Narrowed, not unchanged: a city filter that leaves every zone on screen is
  // a filter that never ran.
  expect(await visibleZones()).not.toBe(before);
});

test('notification compose: the audience rebuilds the recipient list', async ({ page }) => {
  // The screen the report came from, kept here beside its siblings so the class
  // is covered in one place as well as in its own spec.
  await page.goto('/admin/notification/compose');
  await expect(widget(page, 'audience')).toBeVisible();

  const first = await page.locator('#target option').allTextContents();

  await chooseByIndex(page, 'audience', 1);

  const second = await page.locator('#target option').allTextContents();

  expect(second).not.toEqual(first);
});
