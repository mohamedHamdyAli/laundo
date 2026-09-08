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
 * Every status label on the page, with the colours the browser settled on.
 *
 * Walks up for the background because a badge whose own background is
 * transparent is drawn on whatever is behind it, and that is what the eye reads.
 *
 * **`.status-pill` as well as `.badge`.** When the list screens became stack
 * rows they stopped rendering Bootstrap badges: a wallet's state is now
 * `.status-pill tone-ok`, an order's is `tone-live`. Only the topbar's
 * notification counter was left matching `.badge` on those pages, and it ships
 * `hidden`, so this helper returned an empty array and four tests failed on
 * "should render at least one badge" while the screens were full of pills. The
 * two classes are the same component to a reader, so they are the same
 * component here.
 */
async function badges(page) {
  return page.evaluate(() => {
    const parse = (c) => {
      const n = c.match(/[\d.]+/g).map(Number);
      return { r: n[0], g: n[1], b: n[2], a: n.length > 3 ? n[3] : 1 };
    };
    const over = (fg, bg) => ({
      r: bg.r + (fg.r - bg.r) * fg.a,
      g: bg.g + (fg.g - bg.g) * fg.a,
      b: bg.b + (fg.b - bg.b) * fg.a,
      a: 1,
    });
    const css = (c) => `rgb(${Math.round(c.r)}, ${Math.round(c.g)}, ${Math.round(c.b)})`;

    /**
     * The colour behind the label, composited.
     *
     * This used to stop at the first background that was not fully transparent
     * and use it at full strength. A `.status-pill tone-ok` is
     * `rgba(91, 208, 138, .14)` — a 14% tint — behind text of that same hue, so
     * the two came out identical and the pill measured **1.00:1** while being
     * perfectly readable. Alpha is not optional in a contrast sum.
     */
    const ground = (el) => {
      const layers = [];
      for (let node = el; node; node = node.parentElement) {
        const c = parse(getComputedStyle(node).backgroundColor);
        if (c.a === 0) continue;
        layers.push(c);
        if (c.a >= 0.999) break;
      }
      return layers
        .reverse()
        .reduce((acc, c) => over(c, acc), { r: 255, g: 255, b: 255, a: 1 });
    };

    return [...document.querySelectorAll('.badge, .status-pill')]
      .filter((el) => el.offsetParent !== null && el.textContent.trim() !== '')
      .map((el) => {
        const bg = ground(el);

        return {
          text: el.textContent.trim().slice(0, 40),
          classes: el.className,
          // Composited too: a `color` carrying an alpha is drawn over its pill.
          color: css(over(parse(getComputedStyle(el).color), bg)),
          background: css(bg),
        };
      });
  });
}

/**
 * Screens whose badges were among the thirteen, plus the wallet screen the report
 * came from.
 *
 * `populated` marks the ones the dev fixtures guarantee rows for. Refunds and
 * notification logs can legitimately be empty, so demanding a badge there fails
 * on a clean database — but a file that passes because every page was blank
 * proves nothing either, which is what the total below guards.
 *
 * The `bg-info` case this file was written for is **not on the order list any
 * more** — it is a task's status on an order's detail page, along
 * `bg-light text-dark` and `bg-secondary`. So the detail page is measured too,
 * and its URL is resolved from the list rather than hard-coded: ids move with
 * the fixtures.
 */
const SCREENS = [
  { url: '/admin/wallet', populated: true },
  { url: '/admin/order', populated: true },
  { url: '/admin/coupon', populated: true },
  { url: '/admin/refund', populated: false },
  { url: '/admin/notification', populated: false },
];

/** The first order's detail page, or null when the fixtures have no orders. */
async function firstOrderDetail(page) {
  await page.goto('/admin/order');

  const href = await page.evaluate(() => {
    const link = [...document.querySelectorAll('a')]
      .map((a) => a.getAttribute('href'))
      .find((h) => h && /\/admin\/order\/show\/\d+$/.test(h));
    return link ?? null;
  });

  return href;
}

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
    const detail = await firstOrderDetail(page);

    for (const { url } of [...SCREENS, ...(detail ? [{ url: detail }] : [])]) {
      await page.goto(url);

      for (const badge of await badges(page)) {
        // Both vocabularies: Bootstrap's `bg-*` on the detail screens and this
        // project's `tone-*` on the stack lists.
        const colour = badge.classes.match(/bg-[a-z]+|tone-[a-z]+/);
        if (colour) seen.add(colour[0]);
      }
    }

    // `bg-light` is the one this file was written for — near-white text on a
    // near-white pill — and it renders on an order's detail page. Asserting
    // `bg-info` here is what went stale: it now appears only on an *unfinished*
    // task, which no fixture guarantees, so the whole-page sweep covers it.
    expect([...seen].sort().join(' '), `only saw: ${[...seen].join(', ')}`).toContain('bg-light');
    expect(seen.size, `only saw: ${[...seen].join(', ')}`).toBeGreaterThanOrEqual(3);
  });

  test('the neutral badge is still visible as a badge, not loose text', async ({ page }) => {
    // On an order's detail page now, not the wallet list — the lists render
    // `.status-pill`, which carries its own tinted fill.
    const detail = await firstOrderDetail(page);
    expect(detail, 'the fixtures should contain at least one order').not.toBeNull();
    await page.goto(detail);

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

    expect(pill, "a bg-light badge should exist on an order's detail page").not.toBeNull();

    const againstCard = contrast(pill.background, pill.card);
    const hasEdge = parseFloat(pill.borderWidth) > 0;

    expect(hasEdge || againstCard > 1.1, 'the pill must be distinguishable from its card').toBe(true);
  });
});

test.describe('Badge contrast in dark mode', () => {
  test('badges stay legible with the dark theme on', async ({ page }) => {
    await login(page, ACCOUNTS.superAdmin);

    // The theme goes in before the first paint, not by adding the class at
    // runtime. `.status-pill` reads `--tone-ok-bg` and friends, which
    // `body.theme-dark` remaps — and a custom property read in the same task
    // that changed the class defining it can still hold the old theme's value.
    // Toggling at runtime made this test measure light-mode colours while
    // reporting on dark mode, i.e. it passed without checking anything.
    await page.addInitScript(() => localStorage.setItem('theme', 'theme-dark'));

    // The list, for the pills, and an order's detail page, where `bg-light` and
    // `bg-secondary` live — neither is restyled for the dark theme, so a
    // theme-following text colour would go pale and vanish all over again.
    const detail = await firstOrderDetail(page);

    for (const url of ['/admin/wallet', ...(detail ? [detail] : [])]) {
      await page.goto(url);

      expect(
        await page.evaluate(() => document.body.classList.contains('theme-dark')),
        'the dark theme should be on at first paint',
      ).toBe(true);

      const failures = (await badges(page))
        .map((b) => ({ ...b, ratio: contrast(b.color, b.background) }))
        .filter((b) => b.ratio < 4.5);

      expect(
        failures.map((f) => `"${f.text}" [${f.classes}] ${f.ratio.toFixed(2)}:1`),
        `badges below 4.5:1 in dark mode on ${url}`,
      ).toEqual([]);
    }
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
        const parse = (c) => {
          const n = c.match(/[\d.]+/g).map(Number);
          return { r: n[0], g: n[1], b: n[2], a: n.length > 3 ? n[3] : 1 };
        };
        const lum = (c) => {
          const ch = (v) => {
            const x = v / 255;
            return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4);
          };
          return 0.2126 * ch(c.r) + 0.7152 * ch(c.g) + 0.0722 * ch(c.b);
        };
        const ratio = (f, b) => {
          const a = lum(f);
          const c = lum(b);
          return (Math.max(a, c) + 0.05) / (Math.min(a, c) + 0.05);
        };
        /** `over` composites a translucent colour onto an opaque one. */
        const over = (fg, bg) => ({
          r: bg.r + (fg.r - bg.r) * fg.a,
          g: bg.g + (fg.g - bg.g) * fg.a,
          b: bg.b + (fg.b - bg.b) * fg.a,
          a: 1,
        });

        // Resolves a gradient to its first stop rather than skipping the element.
        const paint = (el) => {
          const s = getComputedStyle(el);
          const bc = parse(s.backgroundColor);
          if (bc.a > 0) return bc;
          const img = s.backgroundImage;
          if (img && img !== 'none') {
            const m = img.match(/rgba?\([^)]+\)/);
            if (m) return parse(m[0]);
          }
          return null;
        };

        /**
         * The colour actually behind the text, composited.
         *
         * The third trap, and it made every one of these sixteen tests fail
         * while nothing was wrong: this used to take the first background it
         * found that was not fully transparent and hand it over as-is, alpha
         * discarded. The topbar's language chip is `rgba(0, 0, 0, .05)` — a 5%
         * tint over white — and it was read as **solid black**, which reported
         * the `EN` label at 1.27:1 when it is 14.76:1. The chip is on every
         * screen, so every screen failed on it.
         *
         * A translucent layer has to be blended onto what is under it, and the
         * walk continues until something opaque is reached.
         */
        const ground = (el) => {
          const layers = [];
          for (let n = el; n; n = n.parentElement) {
            const c = paint(n);
            if (!c) continue;
            layers.push(c);
            if (c.a >= 0.999) break;
          }
          return layers
            .reverse()
            .reduce((acc, c) => over(c, acc), { r: 255, g: 255, b: 255, a: 1 });
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

          const bg = ground(el);

          const size = parseFloat(st.fontSize);
          const bold = parseInt(st.fontWeight, 10) >= 700;
          // WCAG AA: large text clears at 3:1, everything else at 4.5:1.
          const need = size >= 24 || (bold && size >= 18.66) ? 3 : 4.5;
          // The text colour is composited too — a `color` with an alpha is
          // drawn over its own background, not over nothing.
          const got = ratio(over(parse(st.color), bg), bg);

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

/**
 * The roles permission grid, in both themes.
 *
 * The sweep above already visits `/admin/roles`, and it passed the whole time
 * the grid was unreadable, for two reasons worth writing down:
 *
 *   1. **The grid starts `d-none`.** `offsetParent === null` skips it, so the
 *      34 module labels were never measured. A screen is not covered because
 *      its URL is in a list — only what is on screen gets read.
 *   2. **The sweep never ran in dark mode.** `.permissions-box` hard-coded
 *      `#f6f7fb`, and nothing in the dark theme took it back: the panel stayed
 *      a light slab inside a dark card with the body's light text on it at
 *      1.06:1. The markup and the classes were entirely correct.
 *
 * So this opens the grid and measures it under each theme. The Save button gets
 * its own check because contrast alone cannot catch what was wrong with it: it
 * was `.btn-primary` sitting on the brand-blue band, so its *label* was a
 * passing 5.17:1 white-on-blue while the button had no edge at all — 1:1
 * against its own background. Legible text on an invisible control.
 */
const THEMES = ['theme-light', 'theme-dark'];

test.describe('The roles permission grid', () => {
  for (const theme of THEMES) {
    test.describe(theme, () => {
      test.beforeEach(async ({ page }) => {
        await login(page, ACCOUNTS.superAdmin);

        // Set before navigating, so the class is on `body` at first paint.
        // Read straight after a runtime toggle, custom properties can still
        // hold the previous theme's values in the same task — which produced a
        // page of confident false failures while this was being written.
        await page.addInitScript((t) => localStorage.setItem('theme', t), theme);
        await page.goto('/admin/roles');
        await settled(page);

        await page.click('.toggle-permissions');
        await page.waitForSelector('.permissions-box:not(.d-none)');
      });

      test('every module label is legible', async ({ page }) => {
        const rows = await page.evaluate(() => {
          const opaque = (c) => c && c !== 'transparent' && !c.startsWith('rgba(0, 0, 0, 0)');

          return [...document.querySelectorAll('.permission-row .col-3')].map((el) => {
            let bg = getComputedStyle(el).backgroundColor;

            for (let n = el.parentElement; n && !opaque(bg); n = n.parentElement) {
              bg = getComputedStyle(n).backgroundColor;
            }

            return {
              text: el.textContent.trim(),
              color: getComputedStyle(el).color,
              background: opaque(bg) ? bg : 'rgb(255, 255, 255)',
            };
          });
        });

        // One row per model in config/dashboard.php. If this ever reads zero the
        // check below is vacuous, which is how the original bug survived.
        expect(rows.length, 'the grid should render a row per dashboard model').toBeGreaterThan(20);

        const failures = rows
          .map((r) => ({ ...r, ratio: contrast(r.color, r.background) }))
          .filter((r) => r.ratio < 4.5);

        expect(
          failures.map((f) => `"${f.text}" ${f.ratio.toFixed(2)}:1`),
          `module labels below 4.5:1 with ${theme}`,
        ).toEqual([]);
      });

      test('the Save button is distinguishable from the band it sits in', async ({ page }) => {
        const save = await page.evaluate(() => {
          const el = document.querySelector('.permission-header .btn');
          if (!el) return null;

          const s = getComputedStyle(el);

          return {
            fill: s.backgroundColor,
            label: s.color,
            borderWidth: s.borderTopWidth,
            borderColor: s.borderTopColor,
            band: getComputedStyle(el.closest('.permission-header')).backgroundColor,
          };
        });

        expect(save, 'the grid header should carry a Save button').not.toBeNull();

        // Its label has to be readable...
        expect(contrast(save.label, save.fill)).toBeGreaterThanOrEqual(4.5);

        // ...and the control itself needs an edge against the band. 3:1 is the
        // WCAG bar for the boundary of a non-text UI component.
        //
        // The edge is a border whose COLOUR separates from the band. Comparing
        // the border *width* to the band colour instead — the first thing this
        // line did — makes `hasEdge` true for any bordered button, and the check
        // then passed on the very CSS it exists to fail on: `.btn-primary`
        // carries `border: 1px solid #2563eb` on a #2563eb band.
        const againstBand = contrast(save.fill, save.band);
        const hasEdge =
          parseFloat(save.borderWidth) > 0 && contrast(save.borderColor, save.band) >= 3;

        expect(
          againstBand >= 3 || hasEdge,
          `Save is ${againstBand.toFixed(2)}:1 against its own band with ${theme}`,
        ).toBe(true);
      });

      test('an unchecked permission box does not read as a filled one', async ({ page }) => {
        // These are bare `<input type="checkbox">`, so the browser draws them.
        // With no `color-scheme` the dark panel got the *light* widget, and an
        // unchecked box is then a solid white square — which reads as switched
        // on, the most expensive way for this particular screen to be wrong.
        const box = await page.evaluate(() => ({
          dark: document.body.classList.contains('theme-dark'),
          colorScheme: getComputedStyle(
            document.querySelector('.permission-row input[type="checkbox"]'),
          ).colorScheme,
        }));

        if (box.dark) {
          expect(box.colorScheme, 'the UA needs the dark palette for these boxes').toContain('dark');
        }
      });
    });
  }
});
