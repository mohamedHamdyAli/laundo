import { test, expect } from '@playwright/test';
import { ACCOUNTS, login } from './helpers.js';

/**
 * The driver bonus, in a real browser.
 *
 * The PHP suite proves the arithmetic, the gates and who may set what. What it
 * cannot prove is the half that is a person clicking: that the two amount boxes
 * swap when the basis changes, that a tier row can be added and removed, and
 * that the assign dialog still opens after an AJAX search has redrawn the row it
 * belongs to.
 *
 * Everything here cleans up after itself — it drives the development database,
 * and a driver left on terms nobody agreed to is real money next month.
 */

async function settled(page) {
  await page.waitForLoadState('networkidle');
  // The splash paints over everything until it fades.
  await page.evaluate(() => {
    document.querySelectorAll('[id*=splash],[class*=splash]').forEach((el) => el.remove());
  });
}

test.describe('bonus rules', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/set-language/en');
  });

  test('the amount and the percentage are never both on screen', async ({ page }) => {
    await page.goto('/admin/driver-bonus-rule/create');
    await settled(page);

    // They are the same question asked two ways, and the basis decides which is
    // being asked. Showing both invites somebody to fill both, and only one
    // would ever be read.
    await page.selectOption('#rule-basis', 'per_order');
    await expect(page.locator('#amount-field')).toBeVisible();
    await expect(page.locator('#rate-field')).toBeHidden();

    await page.selectOption('#rule-basis', 'percent_delivery_fee');
    await expect(page.locator('#rate-field')).toBeVisible();
    await expect(page.locator('#amount-field')).toBeHidden();

    // And the hint follows, so the choice is explained where it is made.
    await expect(page.locator('#basis-hint')).toContainText(/distance/i);
  });

  test('a tier row can be added and removed, and the last one never disappears', async ({ page }) => {
    await page.goto('/admin/driver-bonus-rule/create');
    await settled(page);

    const rows = page.locator('#tier-rows .tier-row');
    await expect(rows).toHaveCount(1);

    await page.locator('#add-tier').click();
    await page.locator('#add-tier').click();
    await expect(rows).toHaveCount(3);

    await page.locator('.js-remove-tier').first().click();
    await expect(rows).toHaveCount(2);

    // Removing down to one clears it instead of emptying the container — an
    // empty container has nothing for "Add a target" to clone.
    await page.locator('.js-remove-tier').first().click();
    await page.locator('.js-remove-tier').first().click();
    await expect(rows).toHaveCount(1);
  });

  test('a rule created here appears in the list with its terms', async ({ page }) => {
    const name = 'PW Bonus ' + Math.floor(Math.random() * 100000);

    await page.goto('/admin/driver-bonus-rule/create');
    await settled(page);

    await page.locator('#rule-name').fill(name);
    await page.selectOption('#rule-basis', 'per_order');
    await page.locator('#rule-amount').fill('25');
    await page.locator('#rule-ontime').fill('90');
    await page.locator('#tier-rows input[name="tier_min_orders[]"]').first().fill('100');
    await page.locator('#tier-rows input[name="tier_amounts[]"]').first().fill('500');

    await page.locator('form.store button[type="submit"]').click();
    await page.waitForLoadState('networkidle');

    const body = page.locator('#rule-table-body');
    await expect(body).toContainText(name);
    // The terms in words, so an operator does not have to open every rule.
    await expect(body).toContainText('Per order');
    await expect(body).toContainText(/1 target/i);
    await expect(body).toContainText(/90/);

    // Leave the dev database as it was found.
    const row = page.locator('#rule-table-body .stack-row', { hasText: name });
    page.once('dialog', (d) => d.accept());
    await row.locator('form[action*="/driver-bonus-rule/delete/"] button').first().click();
    await page.waitForLoadState('networkidle');

    await expect(page.locator('#rule-table-body')).not.toContainText(name);
  });
});

test.describe('assigning a driver', () => {
  test('the dialog still opens after a search has redrawn the row', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/set-language/en');
    await page.goto('/admin/driver');
    await settled(page);

    await expect(page.locator('.stack-head')).toContainText('Bonus');

    // setupAjaxSearch binds keyup, so type() and not fill() — and it replaces
    // the whole row container, which is what would break a handler bound to the
    // buttons rather than delegated from the document.
    await page.locator('#driverSearchInput').type('Driver');
    await page.waitForTimeout(900);

    await page.locator('.js-bonus-btn').first().click();

    const modal = page.locator('#bonusModal');
    await expect(modal).toBeVisible();
    // «No bonus» has to be an option and has to mean it.
    await expect(modal).toContainText('No bonus');
  });

  test('a driver with no rule reads as no bonus, not as missing data', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/set-language/en');
    await page.goto('/admin/driver');
    await settled(page);

    // Never blank: an empty cell would read as «not loaded» rather than «not
    // paid», and the difference is a driver's money.
    const rows = page.locator('#driver-table-body .stack-row');
    await expect(rows.first()).toContainText(/No bonus|Per order|Per journey|Share of delivery|Rule switched off/);
  });
});

test.describe('the monthly screen', () => {
  test('it leads with what is waiting and says nothing pays itself', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/set-language/en');
    await page.goto('/admin/driver-bonus');
    await settled(page);

    await expect(page.locator('body')).toContainText('Waiting for you');
    await expect(page.locator('body')).toContainText('nothing is paid until you approve it');
    await expect(page.locator('.stack-head')).toContainText('On time');

    // The month picker is a fixed list, not a free date input: the screen is
    // per-month by construction.
    await expect(page.locator('#bonusPeriodFilter option')).toHaveCount(12);
  });

  test('picking a month reloads the whole page, cards included', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/set-language/en');
    await page.goto('/admin/driver-bonus');
    await settled(page);

    const options = await page.locator('#bonusPeriodFilter option').allTextContents();
    const target = options[1].trim();

    // The handler navigates, so wait for the URL rather than for the network to
    // fall quiet — networkidle can resolve before the navigation even starts.
    await Promise.all([
        page.waitForURL(/period=/),
        page.selectOption('#bonusPeriodFilter', target),
    ]);

    // A full reload rather than an AJAX swap, because the four cards follow the
    // month and the search path only replaces the rows.
    expect(page.url()).toContain('period=' + target);
    await expect(page.locator('body')).toContainText(target);
  });
});
