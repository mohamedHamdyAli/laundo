import { test, expect } from '@playwright/test';
import { ACCOUNTS, login } from './helpers.js';

/**
 * «خريطة السواقين» in a real browser.
 *
 * The PHP suite proves what the data endpoint answers and to whom. What it
 * cannot prove is the page: that a driver becomes a marker, that typing in the
 * box narrows the list and the map together, that a click opens the card, and
 * that a waiting order offers its drivers.
 *
 * The development database holds drivers with no position, so most of these
 * serve the map's own data endpoint from a fixture instead of writing positions
 * into shared rows. The first test reads the real endpoint.
 */

const FIXTURE = {
  generated_at: new Date().toISOString(),
  poll_after_seconds: 3600,
  fresh_for_seconds: 120,
  counts: { all: 3, on_job: 1, available: 1, unavailable: 1, inactive: 0, signal_lost: 1, late: 1, waiting: 1 },
  drivers: [
    {
      id: 9001, name: 'Probe Busy', phone: '+201000009001', photo: '/assets/images/logo.png',
      plate: 'PRB 1', vehicle: 'Motorcycle', status: 'on_job', account_active: true, available: true,
      zone_ids: [1], zones: ['Probe Zone'], signal: 'stale', signal_lost: true,
      location: { lat: 30.06, lng: 31.24, at: null, age_seconds: 600, seen: '10 minutes ago', signal: 'stale' },
      orders_in_hand: 1, cap: 5, is_late: true,
      legs: [{
        id: 1, type: 'pickup_from_customer', type_label: 'Pick up from customer', status: 'started',
        status_label: 'In progress', order_id: 77, order_code: 'PRB-77', customer: '<b>Not bold</b>',
        laundry: 'Probe Laundry', destination: 'Probe Street', destination_point: { lat: 30.05, lng: 31.21 },
        due: '3:00 PM', is_late: true, order_url: null,
      }],
      url: '/admin/driver/show/9001',
    },
    {
      id: 9002, name: 'Probe Free', phone: '+201000009002', photo: '/assets/images/logo.png',
      plate: 'PRB 2', vehicle: null, status: 'available', account_active: true, available: true,
      zone_ids: [1], zones: [], signal: 'live', signal_lost: false,
      location: { lat: 30.04, lng: 31.25, at: null, age_seconds: 5, seen: 'now', signal: 'live' },
      orders_in_hand: 0, cap: null, is_late: false, legs: [], url: '/admin/driver/show/9002',
    },
    {
      id: 9003, name: 'Probe Nowhere', phone: '+201000009003', photo: '/assets/images/logo.png',
      plate: null, vehicle: null, status: 'unavailable', account_active: true, available: false,
      zone_ids: [], zones: [], signal: 'none', signal_lost: false, location: null,
      orders_in_hand: 0, cap: null, is_late: false, legs: [], url: '/admin/driver/show/9003',
    },
  ],
  waiting: [{
    task_id: 555, order_id: 78, order_code: 'PRB-78', type_label: 'Pick up from customer', status: 'pending',
    status_label: 'Awaiting a driver', legs_waiting: 4, customer: 'Probe Customer', place: 'Waiting Street',
    point: { lat: 30.07, lng: 31.22 }, zone_id: 1, due: null, is_late: false, order_url: null,
  }],
};

async function withFixture(page) {
  await page.route('**/admin/driver-map/data', (route) => route.fulfill({ json: FIXTURE }));
  await page.route('**/admin/driver-map/task/555/candidates', (route) => route.fulfill({
    json: {
      task_id: 555,
      candidates: [
        { id: 9002, name: 'Probe Free', distance_km: 3.4, signal: 'live', orders_in_hand: 0, cap: null },
        { id: 9001, name: 'Probe Busy', distance_km: null, signal: 'stale', orders_in_hand: 1, cap: 5 },
      ],
      nobody: null,
      assign_url: '/admin/order/task/assign/555',
    },
  }));
}

test.describe('The drivers map', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
  });

  test('opens from the sidebar and lists the real drivers', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });

    // In the sidebar — inside the Delivery group, which starts folded, so the
    // link is followed rather than clicked.
    await page.goto('/admin/home');
    const link = page.locator('a[href$="/admin/driver-map"]').first();
    await expect(link).toHaveCount(1);
    await page.goto(await link.getAttribute('href'));
    await expect(page).toHaveURL(/\/admin\/driver-map$/);

    await expect(page.locator('[data-dm-canvas].leaflet-container')).toBeVisible();
    await expect(page.locator('[data-dm-updated]')).not.toBeEmpty();

    const all = Number(await page.locator('[data-dm-count="all"]').textContent());
    await expect(page.locator('[data-dm-driver]')).toHaveCount(all);

    expect(errors).toEqual([]);
  });

  test('every located driver is a marker, coloured by what they are doing', async ({ page }) => {
    await withFixture(page);
    await page.goto('/admin/driver-map');

    // Two of the three have a position; the third is listed and not drawn.
    await expect(page.locator('.dm-pin')).toHaveCount(2);
    await expect(page.locator('[data-dm-driver]')).toHaveCount(3);
    await expect(page.locator('.dm-pin.dm-pin--on_job.dm-pin--late.dm-pin--stale')).toHaveCount(1);
    await expect(page.locator('.dm-pin.dm-pin--available')).toHaveCount(1);

    // The quiet driver on a job is first in the list and announced.
    await expect(page.locator('[data-dm-driver]').first()).toHaveAttribute('data-dm-driver', '9001');
    await expect(page.locator('[data-dm-lost]')).toBeVisible();
    await expect(page.locator('[data-dm-count="signal_lost"]')).toHaveText('1');
  });

  test('typing narrows the list and the map together', async ({ page }) => {
    await withFixture(page);
    await page.goto('/admin/driver-map');
    await expect(page.locator('.dm-pin')).toHaveCount(2);

    // By order code — the driver carrying it.
    await page.locator('[data-dm-search]').type('PRB-77');
    await expect(page.locator('[data-dm-driver]')).toHaveCount(1);
    await expect(page.locator('.dm-pin')).toHaveCount(1);

    // A miss empties both and says so.
    await page.locator('[data-dm-search]').fill('');
    await page.locator('[data-dm-search]').type('nobody-like-this');
    await expect(page.locator('[data-dm-driver]')).toHaveCount(0);
    await expect(page.locator('.dm-pin')).toHaveCount(0);
    await expect(page.locator('[data-dm-empty]')).toBeVisible();
  });

  test('a status chip filters, and the list opens the driver card', async ({ page }) => {
    await withFixture(page);
    await page.goto('/admin/driver-map');

    await page.locator('[data-dm-filter="available"]').click();
    await expect(page.locator('[data-dm-driver]')).toHaveCount(1);
    await expect(page.locator('.dm-pin')).toHaveCount(1);

    await page.locator('[data-dm-filter="all"]').click();
    await page.locator('[data-dm-driver="9001"] .dm-row-head').click();

    const card = page.locator('.leaflet-popup-content');
    await expect(card).toContainText('Probe Busy');
    await expect(card).toContainText('PRB-77');
    // Typed by a person: shown, never parsed.
    await expect(card).toContainText('<b>Not bold</b>');
    await expect(card.locator('b')).toHaveCount(0);
    // The selected driver gets a line to where the leg is going.
    await expect(page.locator('.dm-destination')).toHaveCount(1);
  });

  test('a driver with no position opens in the list instead', async ({ page }) => {
    await withFixture(page);
    await page.goto('/admin/driver-map');

    await page.locator('[data-dm-driver="9003"] .dm-row-head').click();
    await expect(page.locator('[data-dm-driver="9003"] .dm-row-body')).toBeVisible();
    await expect(page.locator('[data-dm-driver="9003"] .dm-row-body')).toContainText('Probe Nowhere');
  });

  test('a waiting order offers its drivers, nearest first, with an assign button', async ({ page }) => {
    await withFixture(page);
    await page.goto('/admin/driver-map');

    await page.locator('.dm-wait-pin').click();
    await page.locator('[data-dm-who="555"]').click();

    const rows = page.locator('.leaflet-popup-content .dm-candidate');
    await expect(rows).toHaveCount(2);
    await expect(rows.first()).toContainText('Probe Free');

    const form = rows.first().locator('form[data-dm-assign]');
    await expect(form).toHaveAttribute('action', '/admin/order/task/assign/555');
    await expect(form.locator('input[name="driver_id"]')).toHaveValue('9002');
    await expect(form.locator('input[name="rest_of_order"]')).toHaveValue('1');

    // Unticking «and the rest of the order» reaches every form in the card.
    await page.locator('.leaflet-popup-content [data-dm-rest]').uncheck();
    await expect(form.locator('input[name="rest_of_order"]')).toHaveValue('0');
  });
});
