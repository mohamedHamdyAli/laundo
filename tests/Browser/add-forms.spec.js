import { test, expect } from '@playwright/test';
import { ACCOUNTS, login } from './helpers.js';

/**
 * The «إضافة» screens, driven the way a person drives them.
 *
 * The PHP suite already proves what the server accepts and refuses. What it
 * structurally cannot reach is the half that happens in the page: every create
 * form in this panel carries `needs-validation`, and
 * `public/assets/js/custom/form-validation.js` intercepts the submit, posts in
 * the background, and on a 422 paints each message beside its field. Before
 * that existed, a failed validation was a full page load and everything `old()`
 * cannot carry — every file chosen, both passwords, every select2 selection —
 * was lost.
 *
 * So the claims under test here are not «the server refused it». They are:
 *
 *   - the page does **not** reload when a save fails
 *   - the message lands **beside its own field**, not only in a banner
 *   - **what you typed is still there** afterwards
 *   - fixing it and submitting again actually saves
 *
 * Every test cleans up after itself: this drives the development database, and
 * a commission charge left behind is a real invoice next month.
 */

const stamp = () => Math.floor(Math.random() * 1000000);

async function settled(page) {
  await page.waitForLoadState('networkidle');
  // The splash paints over everything until it fades.
  await page.evaluate(() => {
    document.querySelectorAll('[id*=splash],[class*=splash]').forEach((el) => el.remove());
  });
}

/** Submit a `needs-validation` form and wait for the background post to land. */
async function submitAndSettle(page) {
  const before = page.url();

  await page.locator('form.needs-validation button[type="submit"]').first().click();

  // The handler either paints errors in place or navigates. Wait for whichever
  // happened rather than for a fixed delay.
  await Promise.race([
    page.locator('.js-field-error, .js-form-banner').first().waitFor({ timeout: 8000 }).catch(() => {}),
    page.waitForURL((url) => url.toString() !== before, { timeout: 8000 }).catch(() => {}),
  ]);
}

async function deleteByName(page, indexUrl, bodySelector, deleteFragment, name) {
  await page.goto(indexUrl);
  await settled(page);

  const row = page.locator(`${bodySelector} .stack-row`, { hasText: name });

  if (await row.count()) {
    page.once('dialog', (d) => d.accept());
    await row.locator(`form[action*="${deleteFragment}"] button`).first().click();
    await page.waitForLoadState('networkidle');
  }
}

// ===========================================================================
//  Adding a commission
// ===========================================================================

test.describe('Add Commission', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/set-language/en');
  });

  test('an empty save is refused without reloading the page', async ({ page }) => {
    await page.goto('/admin/commission-rule/create');
    await settled(page);

    // A marker that only survives if the document is never replaced. This is
    // the whole point of the background submit, and a reload would lose it.
    await page.evaluate(() => { window.__notReloaded = true; });

    await submitAndSettle(page);

    expect(page.url()).toContain('/commission-rule/create');
    expect(await page.evaluate(() => window.__notReloaded)).toBe(true);
  });

  test('the missing name is reported beside the name box', async ({ page }) => {
    await page.goto('/admin/commission-rule/create');
    await settled(page);

    await submitAndSettle(page);

    const nameInput = page.locator('#commission-name');
    await expect(nameInput).toHaveClass(/is-invalid/);

    // Beside the field, not only in the banner at the top. The rule «a name in
    // at least one language» fails under the bare key `name` while the input is
    // `name[en]`, which is exactly the mapping that used to fall through.
    const group = page.locator('#commission-name').locator('xpath=ancestor::*[contains(@class,"form-group")][1]');
    await expect(group.locator('.js-field-error')).toHaveCount(1);
    await expect(group.locator('.js-field-error')).toContainText(/at least one language/i);
  });

  test('the value box is reported on the box the basis actually asks for', async ({ page }) => {
    await page.goto('/admin/commission-rule/create');
    await settled(page);

    // A name, so the only thing left to fail is the value.
    await page.locator('#commission-name').fill('PW ' + stamp());

    await page.selectOption('#commission-basis', 'percent');
    await submitAndSettle(page);

    await expect(page.locator('#commission-rate')).toHaveClass(/is-invalid/);
    await expect(page.locator('#commission-amount')).not.toHaveClass(/is-invalid/);

    await page.selectOption('#commission-basis', 'fixed');
    await submitAndSettle(page);

    // The basis decides which of the two boxes is being asked about, and the
    // message has to follow it.
    await expect(page.locator('#commission-amount')).toHaveClass(/is-invalid/);
    await expect(page.locator('#commission-rate')).not.toHaveClass(/is-invalid/);
  });

  test('what you typed survives a refused save', async ({ page }) => {
    await page.goto('/admin/commission-rule/create');
    await settled(page);

    const name = 'PW Kept ' + stamp();

    await page.locator('#commission-name').fill(name);
    await page.selectOption('#commission-basis', 'fixed');
    await page.selectOption('#commission-status', 'inactive');
    // Amount deliberately left empty, so the save is refused.

    await submitAndSettle(page);

    // Everything `old()` cannot carry, still on screen.
    await expect(page.locator('#commission-name')).toHaveValue(name);
    await expect(page.locator('#commission-basis')).toHaveValue('fixed');
    await expect(page.locator('#commission-status')).toHaveValue('inactive');
    // And the box the basis asks for is still the one showing.
    await expect(page.locator('#commission-amount-field')).toBeVisible();
    await expect(page.locator('#commission-rate-field')).toBeHidden();
  });

  test('fixing the error and saving again lands the record in the list', async ({ page }) => {
    const name = 'PW Retry ' + stamp();

    try {
      await page.goto('/admin/commission-rule/create');
      await settled(page);

      // Refused once.
      await page.locator('#commission-name').fill(name);
      await submitAndSettle(page);
      await expect(page.locator('#commission-rate')).toHaveClass(/is-invalid/);

      // Then corrected in place, without retyping the name.
      await page.locator('#commission-rate').fill('13');
      await submitAndSettle(page);

      await expect(page).toHaveURL(/\/admin\/commission-rule$/);
      const body = page.locator('#commission-table-body');
      await expect(body).toContainText(name);
      await expect(body).toContainText('13%');
    } finally {
      await deleteByName(page, '/admin/commission-rule', '#commission-table-body', '/commission-rule/delete/', name);
    }
  });

  test('the previous message is cleared, not stacked, on a second attempt', async ({ page }) => {
    await page.goto('/admin/commission-rule/create');
    await settled(page);

    await submitAndSettle(page);
    const first = await page.locator('.js-field-error').count();
    expect(first).toBeGreaterThan(0);

    await submitAndSettle(page);

    // Two attempts must not leave two copies of the same complaint.
    await expect(page.locator('.js-field-error')).toHaveCount(first);
  });

  test('a charge can be added with an Arabic name alone', async ({ page }) => {
    // The panel's own rule: at least one language, not all of them. On an
    // English-default install the single box IS the default language, so this
    // proves the Arabic round-trips rather than that the rule is relaxed.
    const name = 'رسوم ' + stamp();

    try {
      await page.goto('/admin/commission-rule/create');
      await settled(page);

      await page.locator('#commission-name').fill(name);
      await page.selectOption('#commission-basis', 'fixed');
      await page.locator('#commission-amount').fill('7.5');
      await submitAndSettle(page);

      await expect(page).toHaveURL(/\/admin\/commission-rule$/);

      const body = page.locator('#commission-table-body');
      await expect(body).toContainText(name);
      // Not \uXXXX escapes and not replacement marks.
      await expect(body).not.toContainText('\\u');
      await expect(body).not.toContainText('�');
    } finally {
      await deleteByName(page, '/admin/commission-rule', '#commission-table-body', '/commission-rule/delete/', name);
    }
  });

  test('the laundries it applies to can be chosen while adding it', async ({ page }) => {
    const name = 'PW Attached ' + stamp();

    try {
      await page.goto('/admin/commission-rule/create');
      await settled(page);

      await page.locator('#commission-name').fill(name);
      await page.selectOption('#commission-basis', 'percent');
      await page.locator('#commission-rate').fill('8');

      const boxes = page.locator('input[name="laundry_ids[]"]');
      await expect(await boxes.count()).toBeGreaterThan(0);
      await boxes.first().check();

      await submitAndSettle(page);

      await expect(page).toHaveURL(/\/admin\/commission-rule$/);

      // The count on the row is the figure that says this charge now bills
      // somebody — an added-but-unattached charge is inert.
      const row = page.locator('#commission-table-body .stack-row', { hasText: name });
      await expect(row).toContainText('1');
    } finally {
      await deleteByName(page, '/admin/commission-rule', '#commission-table-body', '/commission-rule/delete/', name);
    }
  });
});

// ===========================================================================
//  Adding a bonus rule
// ===========================================================================

test.describe('Add Bonus Rule', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/set-language/en');
  });

  test('an empty save is refused in place and names the missing name', async ({ page }) => {
    await page.goto('/admin/driver-bonus-rule/create');
    await settled(page);

    await page.evaluate(() => { window.__notReloaded = true; });
    await submitAndSettle(page);

    expect(page.url()).toContain('/driver-bonus-rule/create');
    expect(await page.evaluate(() => window.__notReloaded)).toBe(true);

    const nameInput = page.locator('#rule-name');
    await expect(nameInput).toHaveClass(/is-invalid/);

    const group = page.locator('#rule-name').locator('xpath=ancestor::*[contains(@class,"form-group")][1]');
    await expect(group.locator('.js-field-error')).toContainText(/at least one language/i);
  });

  test('the tiers and the gates survive a refused save', async ({ page }) => {
    await page.goto('/admin/driver-bonus-rule/create');
    await settled(page);

    // A whole rule typed out, with only the amount missing.
    await page.locator('#rule-name').fill('PW Bonus ' + stamp());
    await page.selectOption('#rule-basis', 'per_order');
    await page.locator('#rule-ontime').fill('92');
    await page.locator('#rule-rating').fill('4.4');
    await page.locator('#rule-failed').fill('2');

    await page.locator('#add-tier').click();
    const mins = page.locator('#tier-rows input[name="tier_min_orders[]"]');
    const amounts = page.locator('#tier-rows input[name="tier_amounts[]"]');
    await mins.nth(0).fill('50');
    await amounts.nth(0).fill('300');
    await mins.nth(1).fill('100');
    await amounts.nth(1).fill('800');

    await submitAndSettle(page);

    // Retyping three gates and two tiers because one box was empty is exactly
    // what the background submit exists to prevent.
    await expect(page.locator('#rule-ontime')).toHaveValue('92');
    await expect(page.locator('#rule-rating')).toHaveValue('4.4');
    await expect(page.locator('#rule-failed')).toHaveValue('2');
    await expect(mins.nth(0)).toHaveValue('50');
    await expect(amounts.nth(1)).toHaveValue('800');
    await expect(page.locator('#tier-rows .tier-row')).toHaveCount(2);
  });

  test('a half-filled tier row is refused and says so', async ({ page }) => {
    await page.goto('/admin/driver-bonus-rule/create');
    await settled(page);

    await page.locator('#rule-name').fill('PW Half ' + stamp());
    await page.selectOption('#rule-basis', 'per_order');
    await page.locator('#rule-amount').fill('20');

    // A target with a count and no money against it. The service would
    // otherwise drop it silently, which is worse than saying so.
    await page.locator('#tier-rows input[name="tier_min_orders[]"]').first().fill('40');

    await submitAndSettle(page);

    expect(page.url()).toContain('/driver-bonus-rule/create');

    // The key is `tier_min_orders.0`, which cannot resolve to an input named
    // `tier_min_orders[]` — so this one legitimately lands in the banner. The
    // message still has to reach the operator somewhere.
    const shown = page.locator('.js-field-error, .js-form-banner');
    await expect(shown.first()).toBeVisible();
    await expect(page.locator('body')).toContainText(/both a number of orders and an amount/i);
  });

  test('a complete rule saves and shows its terms in the list', async ({ page }) => {
    const name = 'PW Full ' + stamp();

    try {
      await page.goto('/admin/driver-bonus-rule/create');
      await settled(page);

      await page.locator('#rule-name').fill(name);
      await page.selectOption('#rule-basis', 'per_order');
      await page.locator('#rule-amount').fill('18');
      await page.locator('#rule-ontime').fill('90');
      await page.locator('#tier-rows input[name="tier_min_orders[]"]').first().fill('60');
      await page.locator('#tier-rows input[name="tier_amounts[]"]').first().fill('450');

      await submitAndSettle(page);

      await expect(page).toHaveURL(/\/admin\/driver-bonus-rule$/);

      const row = page.locator('#rule-table-body .stack-row', { hasText: name });
      await expect(row).toContainText('Per order');
      await expect(row).toContainText(/1 target/i);
      // The gate has to be visible on the row, or an operator cannot tell a
      // gated rule from an ungated one without opening it.
      await expect(row).toContainText('90');
    } finally {
      await deleteByName(page, '/admin/driver-bonus-rule', '#rule-table-body', '/driver-bonus-rule/delete/', name);
    }
  });

  test('a rule with only monthly targets is allowed to pay nothing per order', async ({ page }) => {
    const name = 'PW Monthly ' + stamp();

    try {
      await page.goto('/admin/driver-bonus-rule/create');
      await settled(page);

      await page.locator('#rule-name').fill(name);
      await page.selectOption('#rule-basis', 'per_order');
      // «مرتبك بره، والمكافأة آخر الشهر» — a perfectly ordinary arrangement.
      await page.locator('#rule-amount').fill('0');
      await page.locator('#tier-rows input[name="tier_min_orders[]"]').first().fill('80');
      await page.locator('#tier-rows input[name="tier_amounts[]"]').first().fill('600');

      await submitAndSettle(page);

      await expect(page).toHaveURL(/\/admin\/driver-bonus-rule$/);

      const row = page.locator('#rule-table-body .stack-row', { hasText: name });
      await expect(row).toContainText(/No immediate bonus/i);
      await expect(row).toContainText(/1 target/i);
    } finally {
      await deleteByName(page, '/admin/driver-bonus-rule', '#rule-table-body', '/driver-bonus-rule/delete/', name);
    }
  });
});

// ===========================================================================
//  What both screens share
// ===========================================================================

test.describe('Both Add screens', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
  });

  for (const [label, path, nameId] of [
    ['commission', '/admin/commission-rule/create', '#commission-name'],
    ['bonus rule', '/admin/driver-bonus-rule/create', '#rule-name'],
  ]) {
    test(`the ${label} form is wired for the background submit`, async ({ page }) => {
      await page.goto('/admin/set-language/en');
      await page.goto(path);
      await settled(page);

      // `needs-validation` is what form-validation.js binds to. Without the
      // class the form posts natively and every typed value is lost on a 422 —
      // silently, because the page still works.
      await expect(page.locator('form.needs-validation')).toHaveCount(1);
      await expect(page.locator(nameId)).toBeVisible();

      // No browser-level `required` on the translatable name: the rule is «at
      // least one language», which no single attribute can express, and the
      // browser would refuse a save the server accepts.
      await expect(page.locator(nameId)).not.toHaveAttribute('required', /.*/);
    });

    test(`the ${label} form renders right-to-left in Arabic`, async ({ page }) => {
      await page.goto('/admin/set-language/ar');

      try {
        await page.goto(path);
        await settled(page);

        await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
        await expect(page.locator('form.needs-validation')).toHaveCount(1);

        const body = await page.locator('body').innerText();
        expect(body).not.toContain('\\u');
        expect(body).not.toContain('�');
      } finally {
        await page.goto('/admin/set-language/en');
      }
    });
  }
});
