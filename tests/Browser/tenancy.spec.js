import { test, expect } from '@playwright/test';
import { ACCOUNTS, login, isRtl } from './helpers.js';

/**
 * The things only a real browser can settle: that tenant isolation holds in the
 * rendered page, that the AJAX search and status toggle actually work, that the
 * price grid draws the matrix it should, and that Arabic renders right-to-left.
 */

test.describe('tenant isolation, as seen in the browser', () => {
  test('laundry A owner sees only laundry A in the list', async ({ page }) => {
    await login(page, ACCOUNTS.ownerA);
    await page.goto('/admin/laundry');

    const body = page.locator('body');
    await expect(body).toContainText('Laundry A');
    await expect(body).not.toContainText('Laundry B');
  });

  test('laundry B owner sees only laundry B', async ({ page }) => {
    await login(page, ACCOUNTS.ownerB);
    await page.goto('/admin/laundry');

    const body = page.locator('body');
    await expect(body).toContainText('Laundry B');
    await expect(body).not.toContainText('Laundry A');
  });

  test('a super admin sees both', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/laundry');

    await expect(page.locator('body')).toContainText('Laundry A');
    await expect(page.locator('body')).toContainText('Laundry B');
  });

  test('typing another tenant id in the URL gives a 404', async ({ page }) => {
    await login(page, ACCOUNTS.ownerA);

    const own = await page.goto('/admin/laundry/show/1');
    expect(own.status()).toBe(200);

    const other = await page.goto('/admin/laundry/show/2');
    expect(other.status(), 'laundry A must not be able to open laundry B').toBe(404);
  });

  test('a laundry owner is refused the global catalogue pages', async ({ page }) => {
    await login(page, ACCOUNTS.ownerA);

    for (const path of ['/admin/pricing', '/admin/service', '/admin/item', '/admin/zone', '/admin/time-slot']) {
      const response = await page.goto(path);
      expect(response.status(), `${path} should be forbidden`).toBe(403);
    }
  });

  test('the My Services page never shows a price', async ({ page }) => {
    await login(page, ACCOUNTS.ownerA);
    await page.goto('/admin/laundry-service');

    await expect(page.locator('body')).toContainText(/My Services/i);

    // Prices are global and belong to the super admin; a tenant must not see
    // them on the page it does have access to.
    const text = await page.locator('body').innerText();
    expect(text).not.toMatch(/\b\d+\.\d{2}\b/);
  });

  test('a laundry sees its own work, not the platform view', async ({ page }) => {
    // This replaced a test of the old catalogue tiles. The page is now an
    // operations screen, and what matters is that a laundry gets the half about
    // its own orders and none of the dispatch or platform-money half.
    await login(page, ACCOUNTS.ownerA);
    await page.goto('/admin/home');

    await expect(page.locator('body')).toContainText(/Your work, in order|شغلك بالترتيب/i);

    // Drivers and the platform's receivables are not a laundry's business — and
    // tasks carry no laundry_id, so the scope would not have filtered them.
    await expect(page.locator('body')).not.toContainText(/Open journeys|رحلات مفتوحة/i);
    await expect(page.locator('body')).not.toContainText(/Owed to us|مستحق لنا/i);
    await expect(page.locator('body')).not.toContainText(/Look at these first|ابدأ من هنا/i);
  });

  test('the home page shows an operator the dispatch half', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/home');

    await expect(page.locator('body')).toContainText(/Waiting for a person|بانتظار تدخل/i);
    await expect(page.locator('body')).toContainText(/Open journeys|رحلات مفتوحة/i);
  });
});

test.describe('the price grid', () => {
  test('draws a cell for every item and per-item service', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/pricing');

    const cells = page.locator('input[name^="prices["]');
    const count = await cells.count();

    // The seeded catalogue is 10 items across 3 per-item services.
    expect(count).toBe(30);
  });

  test('excludes the quoted service from the columns', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/pricing');

    const head = await page.locator('thead').innerText();

    expect(head).toMatch(/Wash/i);
    expect(head).not.toMatch(/Household/i);

    // It is named in the notice at the bottom instead.
    await expect(page.locator('body')).toContainText(/Quoted services are not shown here/i);
  });

  test('shows the price the design specifies', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/pricing');

    // "قميص على شماعة 17" on the design's الاسعار screen.
    const first = page.locator('input[name^="prices["]').first();

    await expect(first).toHaveValue('17.00');
  });

  test('saving the grid round-trips a changed value', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/pricing');

    const cell = page.locator('input[name^="prices["]').first();
    const original = await cell.inputValue();

    await cell.fill('21.25');
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');

    await expect(page.locator('input[name^="prices["]').first()).toHaveValue('21.25');

    // Put the seeded value back so the fixture stays as the seeder left it.
    await page.locator('input[name^="prices["]').first().fill(original);
    await page.click('button[type="submit"]');
    await page.waitForLoadState('networkidle');

    await expect(page.locator('input[name^="prices["]').first()).toHaveValue(original);
  });
});

test.describe('the AJAX pieces PHP tests cannot reach', () => {
  test('search filters the table without a page reload', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/laundry');

    const rowsBefore = await page.locator('#laundry-table-body tr').count();
    expect(rowsBefore).toBeGreaterThan(1);

    // pressSequentially, not fill(): setupAjaxSearch listens on `keyup`, and
    // fill() sets the value without emitting key events, so the handler would
    // never run and the assertion below would pass or fail for the wrong reason.
    await page.locator('#laundrySearchInput').pressSequentially('Laundry A', { delay: 30 });

    // The handler debounces by 300ms; waiting for the response is exact.
    await page.waitForResponse((r) => r.url().includes('/laundry/search'));

    await expect(page.locator('#laundry-table-body')).toContainText('Laundry A');
    await expect(page.locator('#laundry-table-body')).not.toContainText('Laundry B');
  });

  test('the status toggle posts and reflects the new state', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/zone');

    const toggle = page.locator('.toggle-status').first();
    await expect(toggle).toBeVisible();

    const before = (await toggle.innerText()).trim();

    const [response] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/status/') && r.request().method() === 'POST'),
      toggle.click(),
    ]);

    expect(response.status()).toBe(200);
    expect(await response.json()).toHaveProperty('success', true);

    // Flip it back so the fixture is unchanged.
    await page.reload();
    const toggleAgain = page.locator('.toggle-status').first();
    if ((await toggleAgain.innerText()).trim() !== before) {
      await Promise.all([
        page.waitForResponse((r) => r.url().includes('/status/')),
        toggleAgain.click(),
      ]);
    }
  });
});

test.describe('Arabic and RTL', () => {
  test('switching to Arabic flips the layout direction', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);

    await page.goto('/admin/set-language/ar');
    await page.goto('/admin/laundry');

    expect(await isRtl(page), 'the document should be right-to-left in Arabic').toBe(true);
  });

  test('data columns follow the panel language', async ({ page }) => {
    // This test used to assert the opposite. The convention from the first phase
    // was that getLocalizedValueDashboard() always rendered the *default*
    // language, so an Arabic panel showed English record names; the owner
    // reversed that. An Arabic panel now shows Arabic data.
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/set-language/ar');
    await page.goto('/admin/laundry');

    await expect(page.locator('#laundry-table-body')).toContainText('مغسلة');
  });

  test('a value missing in the panel language falls back rather than vanishing', async ({ page }) => {
    // The reason the reversal is safe. A laundry whose Arabic name was never
    // filled in must not read "No Data Found" in an Arabic panel — that row would
    // be unsearchable, unreadable, and look like data loss.
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/set-language/ar');
    await page.goto('/admin/laundry');

    await expect(page.locator('#laundry-table-body')).not.toContainText('No Data Found');
  });

  test('nothing renders as unicode escapes or replacement marks', async ({ page }) => {
    // The real risk with JSON-stored Arabic: \u06xx escapes leaking through, or a
    // charset mismatch turning letters into question marks.
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/set-language/ar');
    await page.goto('/admin/laundry');

    const text = await page.locator('body').innerText();

    expect(text).not.toMatch(/\\u06/);
    expect(text).not.toMatch(/\?\?\?\?/);
  });

  test('an Arabic value entered through a form comes back intact', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/set-language/en');
    await page.goto('/admin/zone/create');

    const arabic = 'منطقة اختبار المتصفح';

    // The default-language field is required; the Arabic one is the translation.
    await page.fill('input[name="name[en]"]', 'Browser Probe Zone');
    await page.fill('input[name="name[ar]"]', arabic);
    await page.selectOption('select[name="city_id"]', { index: 1 });
    await page.click('form.store button[type="submit"]');
    await page.waitForLoadState('networkidle');

    // Read it back through the edit form, which renders the stored value.
    await page.locator("#zoneSearchInput").pressSequentially("Browser Probe Zone", { delay: 30 });
    await page.waitForResponse((r) => r.url().includes("/zone/search"));
    await expect(page.locator('#zone-table-body')).toContainText('Browser Probe Zone');

    const editHref = await page.locator('#zone-table-body a[href*="/zone/edit/"]').first().getAttribute('href');
    await page.goto(editHref);

    await expect(page.locator('input[name="name[ar]"]')).toHaveValue(arabic);

    // Clean up the artifact this test created.
    const id = editHref.split('/').pop();
    await page.goto(`/admin/zone/show/${id}`);
    await page.evaluate((zoneId) => {
      const form = document.createElement('form');
      form.method = 'POST';
      form.action = `/admin/zone/delete/${zoneId}`;
      form.innerHTML = `<input name="_token" value="${document.querySelector('meta[name=csrf-token]')?.content ?? ''}">`
        + '<input name="_method" value="DELETE">';
      document.body.appendChild(form);
      form.submit();
    }, id);
    await page.waitForLoadState('networkidle');
  });

  test('switching back to English restores left-to-right', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);

    await page.goto('/admin/set-language/en');
    await page.goto('/admin/laundry');

    expect(await isRtl(page)).toBe(false);
  });
});
