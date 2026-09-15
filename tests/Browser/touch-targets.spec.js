import { test, expect } from '@playwright/test';
import { ACCOUNTS, login } from './helpers.js';

/**
 * Controls a finger can actually hit.
 *
 * The list rows carry view / edit / delete side by side at 26×26px, and the
 * third one deletes a record. A mis-tap between two of them on a phone is a row
 * gone — and the audit found 45 such controls on a single coupon page.
 *
 * **Keyed on `pointer: coarse`, not on a width.** What is too small is the
 * finger, not the window: a tablet at 1024px has the problem and a desktop
 * browser dragged narrow does not. A width query would grow the buttons on the
 * wrong machines and leave them small on the right ones — which is why the mouse
 * half of this file matters as much as the touch half.
 */
const SCREENS = ['/admin/coupon', '/admin/city', '/admin/offer'];

test.describe('On a touchscreen', () => {
  test.use({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true });

  for (const screen of SCREENS) {
    test(`${screen} — no control is under 44px`, async ({ page }) => {
      await login(page, ACCOUNTS.superAdmin);
      await page.goto(screen);

      const small = await page.evaluate(() => {
        return [...document.querySelectorAll('.action-btn, .status-toggle')]
          .map((el) => ({ el, r: el.getBoundingClientRect() }))
          .filter(({ r }) => r.width > 0 && (r.height < 44 || r.width < 44))
          .slice(0, 5)
          .map(({ el, r }) => `${el.className} ${Math.round(r.width)}x${Math.round(r.height)}`);
      });

      expect(small).toEqual([]);
    });

    test(`${screen} — growing them did not push the page sideways`, async ({ page }) => {
      // The row's action cell has to wrap rather than widen the card.
      await login(page, ACCOUNTS.superAdmin);
      await page.goto(screen);

      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth
      );

      expect(overflow).toBeLessThanOrEqual(0);
    });
  }

  test('the delete button is far enough from the one beside it', async ({ page }) => {
    // Size alone is not the whole of it: two 44px targets touching still invite
    // the wrong one. The gap is what makes the delete recoverable.
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/coupon');

    const gap = await page.evaluate(() => {
      const row = document.querySelector('.action-btn')?.parentElement;
      if (!row) return null;
      const btns = [...row.querySelectorAll('.action-btn')].map((b) => b.getBoundingClientRect());
      if (btns.length < 2) return null;
      return Math.round(btns[1].left - btns[0].right);
    });

    expect(gap).not.toBeNull();
    expect(gap).toBeGreaterThanOrEqual(4);
  });
});

test.describe('On a mouse', () => {
  test.use({ viewport: { width: 1440, height: 900 }, hasTouch: false });

  test('the buttons keep their compact size', async ({ page }) => {
    // The other half of the promise. A pointer-coarse query that also fired on
    // a desktop would have loosened every list screen in the panel for no
    // reason, and that is the regression this catches.
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/coupon');

    const height = await page.locator('.action-btn').first().evaluate(
      (el) => Math.round(el.getBoundingClientRect().height)
    );

    expect(height).toBeLessThan(34);
  });
});
