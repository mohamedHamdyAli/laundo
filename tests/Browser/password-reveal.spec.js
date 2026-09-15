import { test, expect } from '@playwright/test';
import { ACCOUNTS, login } from './helpers.js';

/**
 * The eye on a password box.
 *
 * Five panel screens take a password and none of them could show it. That
 * matters more here than on a sign-in form: an operator is not recalling their
 * own password, they are **inventing one for somebody else** and then reading it
 * out. A typo they cannot see becomes a locked-out driver, and the «Confirm» box
 * only catches it when the same typo was not made twice.
 *
 * `password-reveal.js` wraps the fields at runtime rather than the markup being
 * edited in five Blade files, so these tests check the real rendered page.
 */
const SCREENS = [
  { name: 'driver create', url: '/admin/driver/create' },
  { name: 'moderator create', url: '/admin/moderator/create' },
  { name: 'change password', url: '/admin/change-password' },
];

test.describe('Password reveal', () => {
  for (const screen of SCREENS) {
    test(`${screen.name} — every password box gets an eye`, async ({ page }) => {
      await login(page, ACCOUNTS.superAdmin);
      await page.goto(screen.url);

      const fields = page.locator('.pw-reveal > input');
      const count = await fields.count();

      expect(count, 'no password field was wrapped').toBeGreaterThan(0);
      await expect(page.locator('.pw-reveal-btn')).toHaveCount(count);
    });
  }

  test('clicking it shows the password, clicking again hides it', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/driver/create');

    const field = page.locator('.pw-reveal > input').first();
    const eye = page.locator('.pw-reveal-btn').first();

    await field.fill('hunter2');
    await expect(field).toHaveAttribute('type', 'password');

    await eye.click();
    await expect(field).toHaveAttribute('type', 'text');
    await expect(field).toHaveValue('hunter2');

    await eye.click();
    await expect(field).toHaveAttribute('type', 'password');
  });

  test('each eye toggles its own field, not the first one', async ({ page }) => {
    // The create forms carry «Password» and «Confirm Password» side by side. A
    // "nearest input" implementation reveals whichever the markup puts first.
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/driver/create');

    const fields = page.locator('.pw-reveal > input');
    expect(await fields.count()).toBeGreaterThan(1);

    await page.locator('.pw-reveal-btn').nth(1).click();

    await expect(fields.nth(0)).toHaveAttribute('type', 'password');
    await expect(fields.nth(1)).toHaveAttribute('type', 'text');
  });

  test('it does not submit the form', async ({ page }) => {
    // A <button> in a form defaults to type=submit. Without `type="button"` the
    // eye posts a half-filled create form the moment somebody checks what they
    // typed.
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/driver/create');

    let posted = false;
    page.on('request', (r) => {
      if (r.method() === 'POST' && r.url().includes('/admin/driver')) posted = true;
    });

    await page.locator('.pw-reveal-btn').first().click();
    await page.waitForTimeout(400);

    expect(posted, 'the eye submitted the form').toBe(false);
    await expect(page).toHaveURL(/driver\/create/);
  });

  test('the eye sits inside the field and does not overlap the text', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/driver/create');

    const field = page.locator('.pw-reveal > input').first();
    const eye = page.locator('.pw-reveal-btn').first();

    const f = await field.boundingBox();
    const e = await eye.boundingBox();

    // Inside the box, vertically centred, and the field reserves room for it.
    expect(e.x).toBeGreaterThanOrEqual(f.x);
    expect(e.x + e.width).toBeLessThanOrEqual(f.x + f.width + 1);

    const padding = await field.evaluate((el) => getComputedStyle(el).paddingInlineEnd);
    expect(parseFloat(padding)).toBeGreaterThan(24);
  });
});
