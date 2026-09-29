import { test, expect } from '@playwright/test';
import { ACCOUNTS, login } from './helpers.js';

/**
 * Laundry shares, in a real browser.
 *
 * A rule is the share of the washing a laundry **receives** — the platform keeps
 * the rest — and a laundry is on one share at a time. The half a PHP test cannot
 * reach is the choosing: that the form asks for a percentage and nothing else,
 * that the laundry dialog offers one share at a time and pre-selects exactly the
 * one the laundry is on, and that choosing another replaces it.
 *
 * Everything here puts back what it found — it drives the development database,
 * and a laundry left on a share nobody agreed to is real money next month.
 */

async function settled(page) {
  await page.waitForLoadState('networkidle');
  // The splash paints over everything until it fades.
  await page.evaluate(() => {
    document.querySelectorAll('[id*=splash],[class*=splash]').forEach((el) => el.remove());
  });
}

/** Create a share through the UI and hand back its name. */
async function createShare(page, { name, value }) {
  await page.goto('/admin/commission-rule/create');
  await settled(page);

  await page.locator('#commission-name').fill(name);
  await page.locator('#commission-rate').fill(String(value));

  await page.locator('form.store button[type="submit"]').click();
  await page.waitForLoadState('networkidle');

  return name;
}

async function deleteShare(page, name) {
  await page.goto('/admin/commission-rule');
  await settled(page);

  const row = page.locator('#commission-table-body .stack-row', { hasText: name });

  if (await row.count()) {
    page.once('dialog', (d) => d.accept());
    await row.locator('form[action*="/commission-rule/delete/"] button').first().click();
    await page.waitForLoadState('networkidle');
  }
}

/** Open the first laundry's dialog and report which share it is on ('' = general). */
async function openFirstLaundry(page) {
  await page.goto('/admin/laundry');
  await settled(page);

  await page.locator('#laundry-table-body .stack-row').first().locator('.js-commission-btn').click();

  const modal = page.locator('#commissionModal');
  await expect(modal).toBeVisible();

  return modal.locator('.js-commission-rule:checked').inputValue();
}

async function chooseAndSave(page, value) {
  const modal = page.locator('#commissionModal');
  await modal.locator(`.js-commission-rule[value="${value}"]`).check();
  await modal.locator('button[type="submit"]').click();
  await page.waitForLoadState('networkidle');
}

test.describe('a laundry share', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/set-language/en');
  });

  test('it is a percentage and nothing else', async ({ page }) => {
    await page.goto('/admin/commission-rule/create');
    await settled(page);

    // The fixed amount went when the share moved to the laundry's side, so
    // there is no basis to choose and no second box to fill.
    await expect(page.locator('#commission-rate')).toBeVisible();
    await expect(page.locator('#commission-basis')).toHaveCount(0);
    await expect(page.locator('#commission-amount')).toHaveCount(0);
    await expect(page.locator('body')).toContainText('the laundry receives 10 of every 100');
  });

  test('a share created here shows its terms and its laundry count', async ({ page }) => {
    const name = 'PW Share ' + Math.floor(Math.random() * 100000);

    try {
      await createShare(page, { name, value: 11 });

      const body = page.locator('#commission-table-body');
      await expect(body).toContainText(name);
      await expect(body).toContainText('11%');
      // Nothing attached yet, so the count is the honest zero.
      await expect(body.locator('.stack-row', { hasText: name })).toContainText('0');
    } finally {
      await deleteShare(page, name);
    }
  });
});

test.describe('putting a laundry on a share', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/set-language/en');
  });

  test('the dialog offers one share at a time and says what none means', async ({ page }) => {
    const name = 'PW Dialog ' + Math.floor(Math.random() * 100000);

    try {
      await createShare(page, { name, value: 7 });
      await openFirstLaundry(page);

      const modal = page.locator('#commissionModal');
      await expect(modal).toContainText(name);
      // Radios, not tickboxes: one share at a time.
      await expect(modal.locator('.js-commission-rule[type="checkbox"]')).toHaveCount(0);
      await expect(modal).toContainText('General laundry share (Settings)');
    } finally {
      await deleteShare(page, name);
    }
  });

  test('the dialog pre-selects exactly the share the laundry is on', async ({ page }) => {
    await openFirstLaundry(page);

    // Exactly one — the laundry's own share, or «general». Nothing selected
    // would have Save quietly move the laundry to the general share.
    expect(await page.locator('.js-commission-rule:checked').count()).toBe(1);
  });

  test('choosing a share replaces the one before, and the laundry is put back', async ({ page }) => {
    const name = 'PW Pick ' + Math.floor(Math.random() * 100000);
    let original = null;

    try {
      await createShare(page, { name, value: 9 });

      original = await openFirstLaundry(page);
      const ruleId = await page
        .locator('#commissionModal label', { hasText: name })
        .evaluate((label) => document.getElementById(label.htmlFor).value);

      await chooseAndSave(page, ruleId);

      const first = page.locator('#laundry-table-body .stack-row').first();
      await expect(first).toContainText('9%');
      await expect(first).toContainText(name);

      // Reopened, the new share is the one selected — the old one came off.
      await first.locator('.js-commission-btn').click();
      await expect(page.locator('#commissionModal')).toBeVisible();
      expect(await page.locator('.js-commission-rule:checked').inputValue()).toBe(ruleId);
    } finally {
      if (original !== null) {
        await openFirstLaundry(page);
        await chooseAndSave(page, original);
      }
      await deleteShare(page, name);
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
