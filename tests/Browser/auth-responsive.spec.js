import { test, expect } from '@playwright/test';

/**
 * The sign-in page at every size somebody actually has.
 *
 * `body.hall` carried `overflow: hidden` together with `min-height: 100vh`, and
 * on `<body>` that switches off page scrolling altogether. Any window shorter
 * than the card — a laptop at 1366×600 clipped 152px, a phone held sideways far
 * more — put the submit button below the fold with **no way to reach it**. You
 * cannot log in to a panel whose sign-in button does not exist, and nothing in
 * the markup looks wrong: the button is there, it is styled, it is simply
 * unreachable.
 *
 * A screenshot cannot catch this and neither can PHPUnit. The only assertion
 * that does is scrolling to the bottom and asking whether the control is on
 * screen.
 */
const SIZES = [
  { name: 'iPhone SE', width: 375, height: 667 },
  { name: 'iPhone 14', width: 390, height: 844 },
  { name: 'phone, short', width: 390, height: 600 },
  { name: 'phone, sideways', width: 740, height: 360 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'laptop, short', width: 1366, height: 600 },
  { name: 'laptop', width: 1366, height: 768 },
  { name: 'desktop', width: 1920, height: 1080 },
];

for (const size of SIZES) {
  test.describe(`Sign-in at ${size.name} (${size.width}×${size.height})`, () => {
    test.use({ viewport: { width: size.width, height: size.height } });

    test('the submit button can be reached', async ({ page }) => {
      await page.goto('/login');

      const submit = page.locator('[type=submit]');
      await submit.scrollIntoViewIfNeeded();
      await expect(submit).toBeInViewport();
    });

    test('both fields can be reached and filled', async ({ page }) => {
      // Reachable is not the same as usable: a field under a fixed decoration
      // takes focus and refuses the keystrokes.
      await page.goto('/login');

      await page.fill('#email', 'admin@admin.com');
      await page.fill('#password', 'password');

      await expect(page.locator('#email')).toHaveValue('admin@admin.com');
      await expect(page.locator('#password')).toHaveValue('password');
    });

    test('the page does not scroll sideways', async ({ page }) => {
      await page.goto('/login');

      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth
      );

      expect(overflow, 'the page scrolls horizontally').toBeLessThanOrEqual(0);
    });

    test('nothing sits under the edge of the screen', async ({ page }) => {
      await page.goto('/login');

      // Every visible control inside the card must be within the viewport width
      // with its side gutter intact.
      const spills = await page.evaluate(() => {
        const w = document.documentElement.clientWidth;
        return [...document.querySelectorAll('.hall-card input, .hall-card button, .hall-card a, .hall-card label')]
          .filter((el) => {
            const r = el.getBoundingClientRect();
            return r.width > 0 && (r.left < 0 || r.right > w);
          })
          .map((el) => el.tagName + '.' + el.className);
      });

      expect(spills).toEqual([]);
    });
  });
}

test.describe('Sign-in decoration', () => {
  test('the floating stat chips are withheld before they can cover the card', async ({ page }) => {
    // They are decoration, and on a narrow window they would sit on top of the
    // one thing the page is for.
    await page.setViewportSize({ width: 900, height: 800 });
    await page.goto('/login');
    await expect(page.locator('.hall-orbit')).toBeHidden();

    await page.setViewportSize({ width: 1400, height: 900 });
    await page.goto('/login');
    await expect(page.locator('.hall-orbit')).toBeVisible();
  });
});
