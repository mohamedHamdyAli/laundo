import { test, expect } from '@playwright/test';
import { ACCOUNTS, login, sidebarLabels } from './helpers.js';

/**
 * Where the money shows up, in a real browser.
 *
 * The PHP suite proves the arithmetic and the isolation. What it cannot prove is
 * that the two audiences — the super admin and the laundry owner — each find
 * their own money without typing a URL, and that the settings driving both say
 * what they do.
 *
 * Choosing the charges themselves lives in `commission-rules.spec.js`.
 */

/*
 * The «commission button» block that used to live here has moved to
 * `commission-rules.spec.js`.
 *
 * It tested a single percentage per laundry, typed into one box: a rate set here
 * shows on the row, an empty box means «follow the general rate», 0 means «free
 * of charge». That model is gone. A laundry now carries any number of named
 * charges that add together, so «the rate» is not a thing the screen has any
 * more and the dialog is a list of tickboxes rather than a number field.
 *
 * Deleted rather than rewritten in place, because the replacement is not the
 * same test with new selectors — it has to prove stacking, the pre-tick, and
 * that unticking actually detaches. What stays below is everything that did not
 * change: the settlements screen, «محفظتي», and the settings that drive both.
 */

test.describe('The settlements screen', () => {
  test('the super admin reaches it from the sidebar', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/set-language/en');

    const joined = (await sidebarLabels(page)).join(' | ');
    expect(joined).toMatch(/Order Settlements|تسويات الطلبات/i);

    await page.goto('/admin/settlement');

    await expect(page.locator('.stack-head')).toContainText('Commission');
    await expect(page.locator('#settlement-table-body')).toBeVisible();
    // The figure the screen leads with: what has not been paid yet.
    await expect(page.locator('body')).toContainText('Waiting to settle');
  });

  test('the status filter reloads the list', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/set-language/en');
    await page.goto('/admin/settlement');

    await page.selectOption('#settlementStatusFilter', 'settled');
    await page.waitForLoadState('networkidle');

    expect(page.url()).toContain('status=settled');
    await expect(page.locator('#settlementStatusFilter')).toHaveValue('settled');
  });

  test('a laundry owner sees the screen without its Laundry column', async ({ page }) => {
    await login(page, ACCOUNTS.ownerA);
    await page.goto('/admin/set-language/en');
    await page.goto('/admin/settlement');

    const head = page.locator('.stack-head');
    await expect(head).toContainText('Order');
    await expect(head).toContainText('Your share');

    // Every row it can see is its own, so a column repeating its name on every
    // line would carry no information.
    await expect(head).not.toContainText('Laundry');
  });
});

test.describe('My Wallet', () => {
  test('a laundry owner reads their own balance without the wallets list', async ({ page }) => {
    await login(page, ACCOUNTS.ownerA);
    await page.goto('/admin/set-language/en');

    await page.goto('/admin/my-wallet');
    await expect(page.locator('body')).toContainText('Balance');
    await expect(page.locator('body')).toContainText('Transactions');

    // wallet.view is the right to read EVERY balance on the platform, and the
    // list is not tenant-scoped. An owner must not hold it.
    const response = await page.goto('/admin/wallet');
    expect(response.status()).toBe(403);
  });

  test('it is offered from the user menu, not by typing a URL', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/set-language/en');
    await page.goto('/admin/home');

    await page.locator('#topbarUserDropdown').click();

    const link = page.locator('.dropdown-menu a[href*="/admin/my-wallet"]');
    await expect(link).toBeVisible();

    await link.click();
    await page.waitForLoadState('networkidle');

    expect(page.url()).toContain('/admin/my-wallet');
  });
});

test.describe('The settings that drive both', () => {
  test('the tax and the commission are both percentages with a hint', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/set-language/en');
    await page.goto('/admin/generalSetting');

    const tax = page.locator('#setting-tax');
    await expect(tax).toBeVisible();
    await expect(tax).toHaveAttribute('type', 'number');
    await expect(tax).toHaveAttribute('max', '100');

    const commission = page.locator('#setting-commission');
    await expect(commission).toBeVisible();
    await expect(commission).toHaveAttribute('max', '100');

    // The sentence that stops somebody believing a rate change restates old
    // invoices.
    await expect(page.locator('body')).toContainText('never restates an invoice already issued');
  });
});
