import { test, expect } from '@playwright/test';
import { ACCOUNTS, login } from './helpers.js';

/**
 * Commissions, in a real browser.
 *
 * A laundry can carry several charges and they add together, so the half a PHP
 * test cannot reach is the choosing: that the percentage box and the amount box
 * swap when the basis changes, that the laundry dialog shows exactly what is
 * already attached and nothing else, and that ticking two of them leaves the
 * row reading «10% + 5 ج» rather than one blended number.
 *
 * Everything here cleans up after itself — it drives the development database,
 * and a laundry left on a charge nobody agreed to is real money next month.
 */

async function settled(page) {
  await page.waitForLoadState('networkidle');
  // The splash paints over everything until it fades.
  await page.evaluate(() => {
    document.querySelectorAll('[id*=splash],[class*=splash]').forEach((el) => el.remove());
  });
}

/** Create a charge through the UI and hand back its name. */
async function createCharge(page, { name, basis, value }) {
  await page.goto('/admin/commission-rule/create');
  await settled(page);

  await page.locator('#commission-name').fill(name);
  await page.selectOption('#commission-basis', basis);

  if (basis === 'fixed') {
    await page.locator('#commission-amount').fill(String(value));
  } else {
    await page.locator('#commission-rate').fill(String(value));
  }

  await page.locator('form.store button[type="submit"]').click();
  await page.waitForLoadState('networkidle');

  return name;
}

async function deleteCharge(page, name) {
  await page.goto('/admin/commission-rule');
  await settled(page);

  const row = page.locator('#commission-table-body .stack-row', { hasText: name });

  if (await row.count()) {
    page.once('dialog', (d) => d.accept());
    await row.locator('form[action*="/commission-rule/delete/"] button').first().click();
    await page.waitForLoadState('networkidle');
  }
}

test.describe('a commission charge', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/set-language/en');
  });

  test('the percentage and the amount are never both on screen', async ({ page }) => {
    await page.goto('/admin/commission-rule/create');
    await settled(page);

    // They are the same question asked two ways, and the basis decides which is
    // being asked. Showing both invites somebody to fill both, and only one
    // would ever be read.
    await page.selectOption('#commission-basis', 'percent');
    await expect(page.locator('#commission-rate-field')).toBeVisible();
    await expect(page.locator('#commission-amount-field')).toBeHidden();

    await page.selectOption('#commission-basis', 'fixed');
    await expect(page.locator('#commission-amount-field')).toBeVisible();
    await expect(page.locator('#commission-rate-field')).toBeHidden();
  });

  test('a charge created here shows its terms and its laundry count', async ({ page }) => {
    const name = 'PW Charge ' + Math.floor(Math.random() * 100000);

    try {
      await createCharge(page, { name, basis: 'percent', value: 11 });

      const body = page.locator('#commission-table-body');
      await expect(body).toContainText(name);
      await expect(body).toContainText('11%');
      // Nothing attached yet, so the count is the honest zero.
      await expect(body.locator('.stack-row', { hasText: name })).toContainText('0');
    } finally {
      await deleteCharge(page, name);
    }
  });
});

test.describe('attaching charges to a laundry', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/set-language/en');
  });

  test('the dialog lists the charges and says what ticking nothing means', async ({ page }) => {
    const name = 'PW Dialog ' + Math.floor(Math.random() * 100000);

    try {
      await createCharge(page, { name, basis: 'fixed', value: 7 });

      await page.goto('/admin/laundry');
      await settled(page);
      await page.locator('.js-commission-btn').first().click();

      const modal = page.locator('#commissionModal');
      await expect(modal).toBeVisible();
      await expect(modal).toContainText(name);
      // The distinction the whole model rests on.
      await expect(modal).toContainText('Tick nothing to follow the general rate');
      await expect(modal).toContainText('charge of 0');
    } finally {
      await deleteCharge(page, name);
    }
  });

  test('two charges tick on, stack on the row, and tick back off', async ({ page }) => {
    const stamp = Math.floor(Math.random() * 100000);
    const percent = 'PW Pct ' + stamp;
    const fixed = 'PW Fix ' + stamp;

    try {
      await createCharge(page, { name: percent, basis: 'percent', value: 9 });
      await createCharge(page, { name: fixed, basis: 'fixed', value: 4 });

      await page.goto('/admin/laundry');
      await settled(page);

      const row = page.locator('#laundry-table-body .stack-row').first();
      await row.locator('.js-commission-btn').click();

      const modal = page.locator('#commissionModal');
      await expect(modal).toBeVisible();

      await modal.locator('label', { hasText: percent }).click();
      await modal.locator('label', { hasText: fixed }).click();
      await modal.locator('button[type="submit"]').click();
      await page.waitForLoadState('networkidle');

      // Every charge named, not a blended figure: «9% + 4 ج» is what the
      // agreement says, and collapsing it to one number is what made a single
      // column insufficient in the first place.
      const first = page.locator('#laundry-table-body .stack-row').first();
      await expect(first).toContainText('9%');
      await expect(first).toContainText('2 charges');

      // Untick them both: sync() has to take them off, or a charge nobody can
      // remove is the worst kind.
      await first.locator('.js-commission-btn').click();
      await expect(modal).toBeVisible();
      await modal.locator('label', { hasText: percent }).click();
      await modal.locator('label', { hasText: fixed }).click();
      await modal.locator('button[type="submit"]').click();
      await page.waitForLoadState('networkidle');

      await expect(page.locator('#laundry-table-body .stack-row').first())
        .toContainText('General rate');
    } finally {
      await deleteCharge(page, percent);
      await deleteCharge(page, fixed);
    }
  });

  test('the dialog pre-ticks only what is already attached', async ({ page }) => {
    const name = 'PW Preticked ' + Math.floor(Math.random() * 100000);

    try {
      await createCharge(page, { name, basis: 'percent', value: 6 });

      await page.goto('/admin/laundry');
      await settled(page);

      // Nothing attached, so nothing is ticked. Pre-ticking would pin a laundry
      // that follows the general rate onto today's value of it the moment
      // somebody opened the dialog and pressed Save.
      await page.locator('.js-commission-btn').first().click();
      await expect(page.locator('#commissionModal')).toBeVisible();

      const checked = await page.locator('.js-commission-rule:checked').count();
      expect(checked).toBe(0);
    } finally {
      await deleteCharge(page, name);
    }
  });

  test('the button still opens after an AJAX search has redrawn the row', async ({ page }) => {
    await page.goto('/admin/laundry');
    await settled(page);

    // setupAjaxSearch binds keyup, so type() and not fill() — and it replaces
    // the whole row container, which is what would break a handler bound to the
    // buttons rather than delegated from the document.
    await page.locator('#laundrySearchInput').type('Laundry');
    await page.waitForTimeout(900);

    await page.locator('.js-commission-btn').first().click();
    await expect(page.locator('#commissionModal')).toBeVisible();
  });
});
