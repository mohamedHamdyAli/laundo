import { test, expect } from '@playwright/test';
import { ACCOUNTS, login, sidebarLabels } from './helpers.js';

/**
 * The dashboard as a person actually meets it: pages that render, a sidebar that
 * shows the right things, and the AJAX pieces that PHP tests cannot reach.
 */

test.describe('authentication', () => {
  test('the login page renders its form', async ({ page }) => {
    await page.goto('/login');

    await expect(page.locator('input[name="email"]')).toBeVisible();
    await expect(page.locator('input[name="password"]')).toBeVisible();
    await expect(page.locator('button[type="submit"]')).toBeVisible();
  });

  test('bad credentials are rejected and stay on the form', async ({ page }) => {
    await login(page, { email: ACCOUNTS.superAdmin.email, password: 'definitely-wrong' });

    await expect(page).toHaveURL(/\/login/);
    await expect(page.locator('input[name="email"]')).toBeVisible();
  });

  test('a super admin reaches the dashboard', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);

    await expect(page).toHaveURL(/\/admin\/home/);
  });

  test('a customer account is refused the dashboard', async ({ page }) => {
    // role type `app` — the mobile audience, which EnsureDashboardRole blocks.
    await login(page, ACCOUNTS.customer);

    await expect(page.locator('body')).toContainText(/403|Unauthorized|Forbidden/i);
  });
});

test.describe('every page renders for a super admin', () => {
  const pages = [
    // Was /Total/i — the old page's catalogue tiles. The home page is now an
    // operations screen; «Right now» is the section that always renders whatever
    // the data looks like.
    ['/admin/home', /Right now|الوضع الآن/i],
    ['/admin/user', /Users|Customers/i],
    ['/admin/laundry', /Laundries/i],
    ['/admin/laundry-staff', /Laundry Staff/i],
    ['/admin/service', /Services/i],
    ['/admin/item-category', /Item Categories/i],
    ['/admin/item', /Items/i],
    ['/admin/pricing', /Prices/i],
    ['/admin/zone', /Zones/i],
    ['/admin/time-slot', /Time Slots/i],
    ['/admin/laundry-service', /Services/i],
    ['/admin/laundry-zone', /Areas/i],
    ['/admin/country', /Countries/i],
    ['/admin/city', /Cities/i],
    ['/admin/language', /Languages/i],
    ['/admin/roles', /Roles/i],
    ['/admin/moderator', /Moderators/i],
    ['/admin/banner', /Banners/i],
    ['/admin/intro', /Intros/i],
    ['/admin/generalSetting', /Setting/i],
  ];

  for (const [path, expected] of pages) {
    test(`${path} renders without an error page`, async ({ page }) => {
      await login(page, ACCOUNTS.superAdmin);

      const response = await page.goto(path);

      expect(response.status(), `${path} returned ${response.status()}`).toBe(200);
      await expect(page.locator('body')).toContainText(expected);

      // A rendered 500 still returns 200 in some setups; catch the giveaways.
      await expect(page.locator('body')).not.toContainText(/Whoops|SQLSTATE|Undefined variable|ParseError/i);
    });
  }
});

test.describe('the create forms render', () => {
  const forms = [
    '/admin/laundry/create',
    '/admin/laundry-staff/create',
    '/admin/service/create',
    '/admin/item-category/create',
    '/admin/item/create',
    '/admin/zone/create',
    '/admin/time-slot/create',
  ];

  for (const path of forms) {
    test(`${path} shows a submittable form`, async ({ page }) => {
      await login(page, ACCOUNTS.superAdmin);
      await page.goto(path);

      // `form.store` is the class every create/edit view uses. A bare `form`
      // also matches the layout's hidden logout form.
      await expect(page.locator('form.store')).toBeVisible();
      await expect(page.locator('form.store button[type="submit"]')).toBeVisible();
      await expect(page.locator('body')).not.toContainText(/Whoops|SQLSTATE|Undefined variable/i);
    });
  }
});

test.describe('the sidebar reflects permissions', () => {
  test('a super admin sees the catalogue group', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);

    const labels = await sidebarLabels(page);
    const joined = labels.join(' | ');

    expect(joined).toMatch(/Laundries/);
    expect(joined).toMatch(/Services/);
    expect(joined).toMatch(/Prices/);
    expect(joined).toMatch(/Users/);
  });

  test('a laundry owner sees only their own scoped entries', async ({ page }) => {
    await login(page, ACCOUNTS.ownerA);

    const joined = (await sidebarLabels(page)).join(' | ');

    expect(joined).toMatch(/Laundries/);
    expect(joined).toMatch(/My Services/);
    expect(joined).toMatch(/My Areas/);

    // The global catalogue belongs to the super admin.
    expect(joined).not.toMatch(/\bPrices\b/);
    expect(joined).not.toMatch(/Item Categories/);
    expect(joined).not.toMatch(/\bRoles\b/);
    expect(joined).not.toMatch(/\bLanguages\b/);
  });

  test('the retired Categories entry is gone from the sidebar', async ({ page }) => {
    // Hidden in P2 because the table is empty and the label collided with the
    // new Item Categories.
    await login(page, ACCOUNTS.superAdmin);

    const joined = (await sidebarLabels(page)).join(' | ');

    expect(joined).not.toMatch(/^Categories$|\| Categories \|/);
  });
});
