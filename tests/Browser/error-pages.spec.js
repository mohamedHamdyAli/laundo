import { test, expect } from '@playwright/test';

/**
 * The branded error pages.
 *
 * The PHPUnit suite proves they render and query nothing. This proves they
 * *look* like something: that landing.css actually arrives, that the page fills
 * the viewport rather than sitting as unstyled text at the top, and that Arabic
 * comes out right-to-left.
 */
test.describe('Error pages', () => {
  test('a missing page is the branded 404, not Laravel default', async ({ page }) => {
    const response = await page.goto('/a-url-that-does-not-exist');
    expect(response.status()).toBe(404);

    await expect(page.locator('.error-code')).toHaveText('404');
    await expect(page.locator('.error-title')).toBeVisible();

    // The stylesheet actually loaded: an unstyled page would leave the card at
    // the natural text width with a transparent ground.
    const bg = await page.locator('.error-page').evaluate(
      (el) => getComputedStyle(el).backgroundColor
    );
    expect(bg).not.toBe('rgba(0, 0, 0, 0)');

    // And it fills the screen rather than collapsing to a line of text.
    const box = await page.locator('.error-page').boundingBox();
    const viewport = page.viewportSize();
    expect(box.height).toBeGreaterThan(viewport.height * 0.8);
  });

  test('every line of text is actually readable against the ground', async ({ page }) => {
    /*
     * This shipped broken once and looked fine in every PHPUnit assertion.
     *
     * landing.css paints `h1, h2, h3, h4` with `--text-strong`, and its default
     * token set is the *light* theme — so `--text-strong` is `#1f1f1f`. The
     * title only inherited its colour from the card, and a bare-element rule
     * beats an inherited value however specific the ancestor is: «Page not
     * found» rendered near-black on a near-black ground and was invisible on
     * production.
     *
     * Contrast is the only assertion that catches that class of bug, so it is
     * measured rather than eyeballed.
     */
    await page.goto('/a-url-that-does-not-exist');

    const luminance = (rgb) => {
      const [r, g, b] = rgb.match(/\d+(\.\d+)?/g).slice(0, 3).map(Number);
      const lin = (c) => {
        const s = c / 255;
        return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
      };
      return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
    };

    const ground = await page.locator('.error-page').evaluate(
      (el) => getComputedStyle(el).backgroundColor
    );

    for (const sel of ['.error-title', '.error-message']) {
      const fg = await page.locator(sel).evaluate((el) => getComputedStyle(el).color);

      const a = luminance(fg);
      const b = luminance(ground);
      const ratio = (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);

      // WCAG AA for large text is 3:1; these are body and heading sizes, so 4.5
      // is the bar. The broken version scored about 1.2.
      expect(ratio, `${sel} contrast against the page ground`).toBeGreaterThan(4.5);
    }
  });

  test('the brand shows the full wordmark, not the square badge', async ({ page }) => {
    await page.goto('/a-url-that-does-not-exist');

    const brand = page.locator('.error-brand');
    await expect(brand).toBeVisible();
    await expect(brand).toHaveAttribute('src', /laundo-light\.png/);

    // A wordmark is far wider than it is tall. The square badge was 1:1, so this
    // catches a revert to it even if the filename changes.
    const box = await brand.boundingBox();
    expect(box.width / box.height).toBeGreaterThan(2);
  });

  test('the home button goes home', async ({ page }) => {
    await page.goto('/a-url-that-does-not-exist');
    await page.locator('.error-btn-primary').click();
    await expect(page).toHaveURL(/\/(ar|en)?$/);
  });

  test('none of the panel assets are requested', async ({ page }) => {
    const requested = [];
    page.on('request', (r) => requested.push(r.url()));

    await page.goto('/a-url-that-does-not-exist');

    // The same rule landing.spec.js enforces for the marketing page: this
    // layout must not grow the admin chain.
    for (const asset of ['main/app.css', 'theme.css', 'jquery', 'select2', 'apexcharts']) {
      expect(requested.filter((u) => u.includes(asset)), `${asset} was requested`).toHaveLength(0);
    }

    expect(requested.some((u) => u.includes('landing.css'))).toBe(true);
    expect(requested.some((u) => u.includes('error.css'))).toBe(true);
  });

  // An unmatched URL never reaches the `web` group, so the session's language
  // choice is not available to the page — the browser's own preference is. This
  // uses a genuinely Arabic browser rather than /locale/ar for that reason.
  test.describe('in an Arabic browser', () => {
    test.use({ locale: 'ar-EG', extraHTTPHeaders: { 'Accept-Language': 'ar-EG,ar;q=0.9' } });

    test('it reads right-to-left in Arabic', async ({ page }) => {
    await page.goto('/a-url-that-does-not-exist');

    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await expect(page.locator('.error-title')).toContainText('الصفحة غير موجودة');

    // The status code stays in Western digits — Arabic-Indic numerals for a
    // status code would be a puzzle, not a localisation.
    await expect(page.locator('.error-code')).toHaveText('404');
    });
  });

  test('the page does not scroll sideways on a phone', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/a-url-that-does-not-exist');

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth
    );
    expect(overflow).toBeLessThanOrEqual(0);
  });
});
