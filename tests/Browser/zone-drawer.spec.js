import { test, expect } from '@playwright/test';
import { ACCOUNTS, login } from './helpers.js';

/**
 * Drawing a zone: the place search and the shape tools on `x-zone-drawer`.
 *
 * Read-only against the database: every test opens the add-zone form and
 * leaves without saving, and reads the hidden `boundary` input the form would
 * post. What the server does with a ring is `ZoneBoundaryTest`'s job.
 *
 * Nominatim is answered by `page.route()`, never called: a public server that
 * rate-limits is not something a test suite should lean on, and a failure there
 * would read as a failure here.
 */

const NOMINATIM = 'https://nominatim.openstreetmap.org/search**';

async function openForm(page) {
  await login(page, ACCOUNTS.superAdmin);
  await page.goto('/admin/zone/create');
  await page.waitForSelector('.zone-drawer-canvas .leaflet-tile-loaded, .zone-drawer-canvas .leaflet-tile');
  await page.locator('.zone-drawer-canvas').scrollIntoViewIfNeeded();
  // Leaflet re-measures its container 200ms after start-up.
  await page.waitForTimeout(600);
}

async function corners(page) {
  const value = await page.inputValue('#zone-boundary');
  return value ? JSON.parse(value).length : 0;
}

/** The map's centre on screen — measured again each time, since buttons scroll the page. */
async function centre(page) {
  const box = await page.locator('.zone-drawer-canvas').boundingBox();
  return [box.x + box.width / 2, box.y + box.height / 2];
}

async function drag(page, from, to, steps = 12) {
  await page.mouse.move(from[0], from[1]);
  await page.mouse.down();
  await page.mouse.move(to[0], to[1], { steps });
  await page.mouse.up();
  await page.waitForTimeout(300);
}

async function useTool(page, mode) {
  await page.click(`[data-zone-mode="${mode}"]`);
  await expect(page.locator(`[data-zone-mode="${mode}"]`)).toHaveAttribute('aria-pressed', 'true');
}

test.describe('zone drawer — place search', () => {
  test('a found place moves the map and marks it, and adds no corner', async ({ page }) => {
    await page.route(NOMINATIM, (route) => route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify([{
        lat: '30.0561', lon: '31.3301', display_name: 'Nasr City, Cairo, Egypt',
        boundingbox: ['30.0300', '30.0800', '31.3000', '31.3700'],
      }]),
    }));

    await openForm(page);
    await page.fill('[data-map-search]', 'Nasr City');
    await page.press('[data-map-search]', 'Enter');

    // Enter searched; it did not submit the zone form around the box.
    await expect(page).toHaveURL(/\/admin\/zone\/create$/);
    await expect(page.locator('.map-picker-result')).toHaveCount(1);
    await page.locator('.map-picker-result').first().click();

    await expect(page.locator('[data-map-results]')).toBeHidden();
    await expect(page.locator('.zone-drawer-found-label')).toHaveText('Nasr City');
    expect(await corners(page)).toBe(0);
  });

  test('a word typed ending in ه is asked with ة first, then as typed, and both answers are listed', async ({ page }) => {
    // Nominatim matches letters as typed: «مدينه» (as Egyptians type it) is
    // not «مدينة» (as the map writes it). Answered here per spelling.
    const asked = [];
    await page.route(NOMINATIM, (route) => {
      const q = new URL(route.request().url()).searchParams.get('q');
      asked.push(q);
      const rows = {
        'مدينة نصر': [{ place_id: 1, lat: '30.039', lon: '31.367', display_name: 'مدينة نصر, القاهرة, مصر' }],
        'مدينه نصر': [
          { place_id: 1, lat: '30.039', lon: '31.367', display_name: 'مدينة نصر, القاهرة, مصر' },
          { place_id: 2, lat: '30.10', lon: '31.40', display_name: 'شارع زهراء مدينه نصر, مدينة نصر, القاهرة, مصر' },
        ],
      }[q] || [];
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify(rows) });
    });

    await openForm(page);
    await page.fill('[data-map-search]', 'مدينه نصر');
    await page.press('[data-map-search]', 'Enter');

    // The second spelling a second after the first, and the place both found
    // listed once.
    await expect(page.locator('.map-picker-result')).toHaveCount(2, { timeout: 5000 });
    expect(asked).toEqual(['مدينة نصر', 'مدينه نصر']);
    await expect(page.locator('.map-picker-result .place').first()).toHaveText('مدينة نصر');
  });

  test('a place picked before the second spelling answers stays picked', async ({ page }) => {
    await page.route(NOMINATIM, (route) => route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify([{ place_id: 1, lat: '30.039', lon: '31.367', display_name: 'مدينة نصر, القاهرة, مصر' }]),
    }));

    await openForm(page);
    await page.fill('[data-map-search]', 'مدينه نصر');
    await page.press('[data-map-search]', 'Enter');
    await page.locator('.map-picker-result').first().click();

    // The second spelling would have answered by now; the list stays shut.
    await page.waitForTimeout(1800);
    await expect(page.locator('[data-map-results]')).toBeHidden();
  });

  test('a query with nothing to respell is asked once', async ({ page }) => {
    const asked = [];
    await page.route(NOMINATIM, (route) => {
      asked.push(new URL(route.request().url()).searchParams.get('q'));
      return route.fulfill({ contentType: 'application/json', body: '[]' });
    });

    await openForm(page);
    await page.fill('[data-map-search]', 'Nasr City');
    await page.press('[data-map-search]', 'Enter');

    await expect(page.locator('.map-picker-empty')).toBeVisible();
    await page.waitForTimeout(1500);
    expect(asked).toEqual(['Nasr City']);
  });

  test('a search that fails says so, in the zone map\'s own words', async ({ page }) => {
    await page.route(NOMINATIM, (route) => route.abort());

    await openForm(page);
    await page.fill('[data-map-search]', 'Maadi');
    await page.click('[data-map-search-go]');

    await expect(page.locator('.map-picker-empty')).toBeVisible();
    const failed = await page.locator('[data-map-search-box]').getAttribute('data-failed');
    await expect(page.locator('.map-picker-empty')).toHaveText(failed);
  });
});

test.describe('zone drawer — shape tools', () => {
  test('each shape becomes corners, and the tool goes back to corners after', async ({ page }) => {
    await openForm(page);

    await useTool(page, 'rectangle');
    await expect(page.locator('[data-zone-mode-hint]')).toBeVisible();
    let [x, y] = await centre(page);
    await drag(page, [x - 120, y - 80], [x + 120, y + 80]);
    expect(await corners(page)).toBe(4);
    await expect(page.locator('[data-zone-mode="points"]')).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('[data-zone-mode-hint]')).toBeHidden();

    // Back on «Corners», the shape's corners are ordinary corners: one drags.
    const drawn = await page.inputValue('#zone-boundary');
    const handle = await page.locator('.zone-drawer-corner').first().boundingBox();
    await drag(page, [handle.x + 7, handle.y + 7], [handle.x + 47, handle.y + 37], 8);
    expect(await corners(page)).toBe(4);
    expect(await page.inputValue('#zone-boundary')).not.toBe(drawn);

    await useTool(page, 'triangle');
    [x, y] = await centre(page);
    await drag(page, [x - 100, y - 90], [x + 100, y + 90]);
    expect(await corners(page)).toBe(3);

    await useTool(page, 'circle');
    [x, y] = await centre(page);
    await drag(page, [x, y], [x + 110, y]);
    expect(await corners(page)).toBe(32);

    await useTool(page, 'freehand');
    [x, y] = await centre(page);
    await page.mouse.move(x - 100, y - 60);
    await page.mouse.down();
    for (const [dx, dy] of [[100, -70], [120, 60], [-20, 100], [-110, 40], [-100, -58]]) {
      await page.mouse.move(x + dx, y + dy, { steps: 15 });
    }
    await page.mouse.up();
    await page.waitForTimeout(300);
    const traced = await corners(page);
    // A handful of corners, not one per mouse event.
    expect(traced).toBeGreaterThanOrEqual(3);
    expect(traced).toBeLessThanOrEqual(100);
  });

  test('a tap is not a shape, Undo brings the last drawing back, and clicks still add corners', async ({ page }) => {
    await openForm(page);

    await useTool(page, 'circle');
    let [x, y] = await centre(page);
    await drag(page, [x, y], [x + 100, y]);
    expect(await corners(page)).toBe(32);

    // A tap with a tool on replaces nothing, and the tool stays on.
    await useTool(page, 'rectangle');
    [x, y] = await centre(page);
    await drag(page, [x + 10, y + 10], [x + 12, y + 11], 2);
    expect(await corners(page)).toBe(32);
    await expect(page.locator('[data-zone-mode="rectangle"]')).toHaveAttribute('aria-pressed', 'true');

    // The rectangle replaces the circle; Undo brings the circle back.
    [x, y] = await centre(page);
    await drag(page, [x - 90, y - 60], [x + 90, y + 60]);
    expect(await corners(page)).toBe(4);
    await page.click('[data-zone-undo]');
    expect(await corners(page)).toBe(32);

    await page.click('[data-zone-clear]');
    expect(await corners(page)).toBe(0);

    // Corners by click, as before the tools existed.
    [x, y] = await centre(page);
    for (const [dx, dy] of [[-80, -60], [80, -60], [0, 70]]) {
      await page.mouse.click(x + dx, y + dy);
      await page.waitForTimeout(250);
    }
    expect(await corners(page)).toBe(3);
  });

  test('the corner-limit warning is not shown on an empty map', async ({ page }) => {
    await openForm(page);
    await expect(page.locator('[data-zone-limit]')).toBeHidden();
  });
});
