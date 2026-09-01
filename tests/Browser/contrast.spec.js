import { test, expect } from '@playwright/test';
import { ACCOUNTS, login } from './helpers.js';

/**
 * Badge legibility, measured rather than asserted by class name.
 *
 * The bug this covers was a *correct* class holding a wrong value: the vendored
 * template redefines `--bs-dark-rgb` to the page background colour at `:root`, so
 * `badge bg-light text-dark` painted near-white text on a near-white pill —
 * 1.02:1, invisible — while the markup read exactly as intended. A test asserting
 * the class was present would have passed the whole time it was broken.
 *
 * So this reads the colours the browser actually computed and does the WCAG sum.
 * It is the only kind of test that can fail on this.
 */

/** WCAG 2.1 relative luminance of a computed `rgb()` / `rgba()` string. */
function luminance(color) {
  const [r, g, b] = color.match(/[\d.]+/g).slice(0, 3).map(Number);

  const channel = (v) => {
    const c = v / 255;
    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
  };

  return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

function contrast(fg, bg) {
  const a = luminance(fg);
  const b = luminance(bg);

  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

/**
 * Every badge on the page, with the colours the browser settled on.
 *
 * Walks up for the background because a badge whose own background is
 * transparent is drawn on whatever is behind it, and that is what the eye reads.
 */
async function badges(page) {
  return page.evaluate(() => {
    const opaque = (c) => c && c !== 'transparent' && !c.startsWith('rgba(0, 0, 0, 0)');

    return [...document.querySelectorAll('.badge')]
      .filter((el) => el.offsetParent !== null && el.textContent.trim() !== '')
      .map((el) => {
        let bg = getComputedStyle(el).backgroundColor;

        for (let node = el.parentElement; node && !opaque(bg); node = node.parentElement) {
          bg = getComputedStyle(node).backgroundColor;
        }

        return {
          text: el.textContent.trim().slice(0, 40),
          classes: el.className,
          color: getComputedStyle(el).color,
          background: opaque(bg) ? bg : 'rgb(255, 255, 255)',
        };
      });
  });
}

/**
 * Screens whose badges were among the thirteen, plus the wallet screen the report
 * came from and the order list where the `bg-info` case lives.
 *
 * `populated` marks the ones the dev fixtures guarantee rows for. Refunds and
 * notification logs can legitimately be empty, so demanding a badge there fails
 * on a clean database — but a file that passes because every page was blank
 * proves nothing either, which is what the total below guards.
 */
const SCREENS = [
  { url: '/admin/wallet', populated: true },
  { url: '/admin/order', populated: true },
  { url: '/admin/coupon', populated: false },
  { url: '/admin/refund', populated: false },
  { url: '/admin/notification', populated: false },
];

test.describe('Badge contrast', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
  });

  for (const { url, populated } of SCREENS) {
    test(`every badge on ${url} is legible`, async ({ page }) => {
      await page.goto(url);

      const found = await badges(page);

      if (populated) {
        expect(found.length, `${url} should render at least one badge`).toBeGreaterThan(0);
      }

      const failures = found
        .map((b) => ({ ...b, ratio: contrast(b.color, b.background) }))
        // 4.5:1 is the WCAG AA threshold for body-size text. Badge text is
        // small and bold, so this is the right bar rather than the 3:1 one.
        .filter((b) => b.ratio < 4.5);

      expect(
        failures.map((f) => `"${f.text}" [${f.classes}] ${f.ratio.toFixed(2)}:1`),
        'badges below 4.5:1',
      ).toEqual([]);
    });
  }

  test('the sweep actually saw a representative spread of badge colours', async ({ page }) => {
    // Without this, an empty database would make every check above vacuous.
    const seen = new Set();

    for (const { url } of SCREENS) {
      await page.goto(url);

      for (const badge of await badges(page)) {
        const colour = badge.classes.match(/bg-[a-z]+/);
        if (colour) seen.add(colour[0]);
      }
    }

    // The three that needed fixing plus at least one that did not, so the sweep
    // is proven to reach both the changed and the unchanged cases.
    expect([...seen].sort().join(' ')).toContain('bg-info');
    expect(seen.size, `only saw: ${[...seen].join(', ')}`).toBeGreaterThanOrEqual(3);
  });

  test('the neutral badge is still visible as a badge, not loose text', async ({ page }) => {
    await page.goto('/admin/wallet');

    // Readable text on a pill indistinguishable from the card behind it is only
    // half the fix — the shape has to survive too.
    const pill = await page.evaluate(() => {
      const el = [...document.querySelectorAll('.badge.bg-light')][0];
      if (!el) return null;

      const style = getComputedStyle(el);
      return {
        background: style.backgroundColor,
        borderWidth: style.borderTopWidth,
        card: getComputedStyle(el.closest('.card') ?? document.body).backgroundColor,
      };
    });

    expect(pill, 'a bg-light badge should exist on the wallet list').not.toBeNull();

    const againstCard = contrast(pill.background, pill.card);
    const hasEdge = parseFloat(pill.borderWidth) > 0;

    expect(hasEdge || againstCard > 1.1, 'the pill must be distinguishable from its card').toBe(true);
  });
});

test.describe('Badge contrast in dark mode', () => {
  test('badges stay legible with the dark theme on', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
    await page.goto('/admin/wallet');

    // bg-light and bg-warning are NOT restyled for the dark theme, so a
    // theme-following text colour would go pale and vanish all over again.
    // This is the test that catches that specific regression.
    await page.evaluate(() => document.body.classList.add('theme-dark'));

    const failures = (await badges(page))
      .map((b) => ({ ...b, ratio: contrast(b.color, b.background) }))
      .filter((b) => b.ratio < 4.5);

    expect(
      failures.map((f) => `"${f.text}" [${f.classes}] ${f.ratio.toFixed(2)}:1`),
      'badges below 4.5:1 in dark mode',
    ).toEqual([]);
  });
});

test.describe('Badge contrast in Arabic', () => {
  test('rtl.css carries the same broken token and must also be overridden', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);

    // rtl.css redefines --bs-dark-rgb identically at its own line 421, so the
    // Arabic layout is a separate path to the same bug.
    await page.goto('/admin/set-language/ar');
    await page.goto('/admin/wallet');

    const failures = (await badges(page))
      .map((b) => ({ ...b, ratio: contrast(b.color, b.background) }))
      .filter((b) => b.ratio < 4.5);

    expect(
      failures.map((f) => `"${f.text}" [${f.classes}] ${f.ratio.toFixed(2)}:1`),
      'badges below 4.5:1 in RTL',
    ).toEqual([]);

    await page.goto('/admin/set-language/en');
  });
});

/**
 * The whole-page sweep.
 *
 * Badges were the reported symptom; they were not the only thing failing. This
 * measures every element that renders its own text, on every screen a person
 * actually works in, and it is the test that found:
 *
 *   - `bg-info` order-status badges at 1.96:1 (white on cyan)
 *   - the footer at 2.80:1
 *   - the roles screen painting white on #84b5ec at 2.14:1
 *   - a `text-warning` figure on the revenue report at 1.63:1 — my own, from P12
 *
 * Two traps are handled here because both produced confident false readings
 * while this was being written, and either would make the sweep worse than
 * useless by reporting problems that are not there:
 *
 *   1. The preloader is a fixed white 80% sheet at z-index 9999. Measured mid
 *      fade, every colour on the page reads through it.
 *   2. A gradient lives in `background-image`, so `backgroundColor` on that
 *      element returns transparent. Walking past it finds some far ancestor
 *      instead — which reported the navy sidebar as 1.46:1 when it is 11.25:1.
 */
/**
 * A limitation worth stating, because it already let four failures through: this
 * measures what RENDERS. A conditional class — `$count > 0 ? 'text-warning' : ''`
 * — contributes nothing to measure when the count is zero, so four `text-warning`
 * uses passed this sweep while being 1.63:1 whenever they did appear. The durable
 * fix was to correct the class in theme.css rather than to chase the conditions;
 * this list is a net, not a proof.
 */
const ALL_SCREENS = [
  '/admin/home',
  '/admin/order',
  '/admin/wallet',
  '/admin/coupon',
  '/admin/report/revenue',
  '/admin/report/orders',
  '/admin/report/operations',
  '/admin/laundry',
  '/admin/user',
  '/admin/city',
  '/admin/roles',
  '/admin/rating',
  '/admin/recurrence',
  '/admin/complaint',
  '/admin/faq',
  '/admin/my-notifications',
];

/** Waits out the preloader, so colours are not read through a white sheet. */
async function settled(page) {
  await page
    .waitForFunction(
      () => {
        const l = document.getElementById('loader-container');
        if (!l) return true;
        const s = getComputedStyle(l);
        return s.display === 'none' || parseFloat(s.opacity) < 0.01;
      },
      { timeout: 15000 },
    )
    .catch(() => {});

  await page.waitForTimeout(400);
}

test.describe('Text contrast across the dashboard', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);
  });

  for (const url of ALL_SCREENS) {
    test(`every text element on ${url} meets WCAG AA`, async ({ page }) => {
      await page.goto(url);
      await settled(page);

      const failures = await page.evaluate(() => {
        const lum = (c) => {
          const [r, g, b] = c.match(/[\d.]+/g).slice(0, 3).map(Number);
          const ch = (v) => {
            const x = v / 255;
            return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
          };
          return 0.2126 * ch(r) + 0.7152 * ch(g) + 0.0722 * ch(b);
        };
        const ratio = (f, b) => {
          const a = lum(f);
          const c = lum(b);
          return (Math.max(a, c) + 0.05) / (Math.min(a, c) + 0.05);
        };

        // Resolves a gradient to its first stop rather than skipping the element.
        const paint = (el) => {
          const s = getComputedStyle(el);
          const bc = s.backgroundColor;
          if (bc && bc !== 'transparent' && !bc.startsWith('rgba(0, 0, 0, 0)')) return bc;
          const img = s.backgroundImage;
          if (img && img !== 'none') {
            const m = img.match(/rgba?\([^)]+\)/);
            if (m) return m[0];
          }
          return null;
        };

        const out = [];

        for (const el of document.querySelectorAll('body *')) {
          if (el.offsetParent === null) continue;
          if (el.tagName === 'SCRIPT' || el.closest('script')) continue;

          const st = getComputedStyle(el);
          if (st.visibility !== 'visible' || parseFloat(st.opacity) < 0.95) continue;

          // Only elements owning their own text; a wrapper inherits its child's.
          const owns = [...el.childNodes].some(
            (n) => n.nodeType === 3 && n.textContent.trim().length > 1,
          );
          if (!owns) continue;

          let bg = null;
          for (let n = el; n && !bg; n = n.parentElement) bg = paint(n);
          if (!bg) bg = 'rgb(255,255,255)';

          const size = parseFloat(st.fontSize);
          const bold = parseInt(st.fontWeight, 10) >= 700;
          // WCAG AA: large text clears at 3:1, everything else at 4.5:1.
          const need = size >= 24 || (bold && size >= 18.66) ? 3 : 4.5;
          const got = ratio(st.color, bg);

          if (got < need) {
            const text = el.textContent.trim().replace(/\s+/g, ' ').slice(0, 34);
            out.push(
              `"${text}" [${el.tagName.toLowerCase()}.${el.className}] ` +
                `${got.toFixed(2)}:1 needs ${need}:1`,
            );
          }
        }

        return out;
      });

      expect(failures, `elements below WCAG AA on ${url}`).toEqual([]);
    });
  }
});
