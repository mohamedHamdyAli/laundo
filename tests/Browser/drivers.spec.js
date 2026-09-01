import { test, expect } from '@playwright/test';
import { ACCOUNTS, login, sidebarLabels } from './helpers.js';

/**
 * P5 in the browser: the driver pages render, the document warning is visible,
 * the zone picker is grouped by city, and the availability switch survives a
 * round trip through the form.
 *
 * Fixtures come from the driver seeding step in the P5 verification — two
 * drivers, one of them holding a lapsed licence so the warning has something to
 * show.
 */

test.describe('driver management', () => {
  test('the drivers page renders and is reachable from the sidebar', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);

    const joined = (await sidebarLabels(page)).join(' | ');
    expect(joined).toMatch(/Drivers/);

    const response = await page.goto('/admin/driver');

    expect(response.status()).toBe(200);
    await expect(page.locator('body')).toContainText(/Drivers/i);
    await expect(page.locator('body')).not.toContainText(/Whoops|SQLSTATE|Undefined variable/i);
  });

  test('the list shows vehicle, shift, areas and availability', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/driver');

    const table = page.locator('#driver-table-body');

    await expect(table).toContainText('Mahmoud Driver');
    await expect(table).toContainText('Motorcycle');
    await expect(table).toContainText('QRS 4821');
    await expect(table).toContainText('09:00 – 21:00');
    await expect(table).toContainText(/Available/);
  });

  test('an expired licence is flagged in the list', async ({ page }) => {
    // Surfaced for a person to act on — by decision it does not stop assignment.
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/driver');

    const row = page.locator('#driver-table-body tr', { hasText: 'Expired Docs Driver' });

    await expect(row).toContainText(/Documents expired/i);
  });

  test('a valid licence is not flagged', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/driver');

    const row = page.locator('#driver-table-body tr', { hasText: 'Mahmoud Driver' });

    await expect(row).not.toContainText(/Documents expired/i);
  });

  test('the create form shows every section from the design', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/driver/create');

    const body = page.locator('body');

    // The design's driver account screen: personal, vehicle, documents, hours, areas.
    await expect(body).toContainText(/Personal Information/i);
    await expect(body).toContainText(/Vehicle/i);
    await expect(body).toContainText(/Documents/i);
    await expect(body).toContainText(/Working Hours/i);
    await expect(body).toContainText(/Service Areas/i);

    await expect(page.locator('input[name="vehicle_type"]')).toBeVisible();
    await expect(page.locator('input[name="license_expiry"]')).toBeVisible();
    await expect(page.locator('input[name="shift_start"]')).toBeVisible();
    await expect(page.locator('input[name="is_available"]')).toBeVisible();
  });

  test('the zone picker is grouped by city and offers real zones', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/driver/create');

    const boxes = page.locator('input[name="zones[]"]');

    expect(await boxes.count()).toBeGreaterThan(0);
    await expect(page.locator('body')).toContainText('Nasr City');
  });

  test('the edit form loads the stored profile', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/driver');

    const href = await page
      .locator('#driver-table-body tr', { hasText: 'Mahmoud Driver' })
      .locator('a[href*="/driver/edit/"]')
      .first()
      .getAttribute('href');

    await page.goto(href);

    await expect(page.locator('input[name="vehicle_type"]')).toHaveValue('Motorcycle');
    await expect(page.locator('input[name="plate_number"]')).toHaveValue('QRS 4821');
    await expect(page.locator('input[name="license_number"]')).toHaveValue('DL-55210');
    await expect(page.locator('input[name="shift_start"]')).toHaveValue('09:00');
    await expect(page.locator('input[name="is_available"]')).toBeChecked();
  });

  test('the availability switch can be turned off and back on', async ({ page }) => {
    // The interesting half: an unchecked checkbox is absent from the payload, so
    // turning it off has to work through absence rather than a false value.
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/driver');

    const href = await page
      .locator('#driver-table-body tr', { hasText: 'Mahmoud Driver' })
      .locator('a[href*="/driver/edit/"]')
      .first()
      .getAttribute('href');

    await page.goto(href);
    await page.uncheck('input[name="is_available"]');
    await page.click('form.store button[type="submit"]');
    await page.waitForLoadState('networkidle');

    await expect(
      page.locator('#driver-table-body tr', { hasText: 'Mahmoud Driver' })
    ).toContainText(/Unavailable/);

    // Put the fixture back.
    await page.goto(href);
    await page.check('input[name="is_available"]');
    await page.click('form.store button[type="submit"]');
    await page.waitForLoadState('networkidle');

    await expect(
      page.locator('#driver-table-body tr', { hasText: 'Mahmoud Driver' })
    ).toContainText(/Available/);
  });

  test('driver search filters the table', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/driver');

    // pressSequentially, since setupAjaxSearch listens on keyup.
    await page.locator('#driverSearchInput').pressSequentially('Mahmoud', { delay: 30 });
    await page.waitForResponse((r) => r.url().includes('/driver/search'));

    await expect(page.locator('#driver-table-body')).toContainText('Mahmoud Driver');
    await expect(page.locator('#driver-table-body')).not.toContainText('Expired Docs Driver');
  });

  test('a laundry owner cannot reach the drivers page', async ({ page }) => {
    // Drivers belong to Laundo, not to a tenant.
    await login(page, ACCOUNTS.ownerA);

    const response = await page.goto('/admin/driver');

    expect(response.status()).toBe(403);
  });

  test('Drivers is absent from a laundry owner sidebar', async ({ page }) => {
    await login(page, ACCOUNTS.ownerA);

    const joined = (await sidebarLabels(page)).join(' | ');

    expect(joined).not.toMatch(/Drivers/);
  });
});
