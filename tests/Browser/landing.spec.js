import { test, expect } from '@playwright/test';
import { isRtl } from './helpers.js';

/**
 * The public landing page, in a real browser.
 *
 * The PHP suite already asserts the markup, the copy and the absence of seed
 * data. What it cannot see is what this file is for: whether the layout holds at
 * 375px, whether the Arabic actually paints in the font it asked for, whether
 * the contrast a person reads clears WCAG, and whether the page still makes
 * sense with motion turned off.
 *
 * `lessons.md`, twice over: "for anything visual, assert the computed result",
 * and "before reporting a measured finding, verify one instance by eye" — which
 * is why the contrast sweep here composites backgrounds up to the first opaque
 * ancestor instead of reading the nearest `background-color` and trusting it.
 */

/* ------------------------------------------------------------------ helpers */

/**
 * Every text node's colour against the ground actually behind it.
 *
 * Walks up compositing semi-transparent layers, because reading only the
 * nearest `background-color` once reported a tinted option at 1:1 — "neither
 * the real value nor a safe direction to be wrong in".
 */
async function contrastFailures(page, minimum = 4.5) {
  return page.evaluate((min) => {
    const parseColour = (c) => {
      const m = c.match(/rgba?\(([^)]+)\)/);
      if (!m) return null;
      const p = m[1].split(',').map((n) => parseFloat(n.trim()));
      return { rgb: p.slice(0, 3), alpha: p.length > 3 ? p[3] : 1 };
    };

    const lum = ([r, g, b]) => {
      const f = (c) => {
        const s = c / 255;
        return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
      };
      return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
    };

    const ratio = (fg, bg) => {
      const a = lum(fg);
      const b = lum(bg);
      return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
    };

    const blend = (top, bottom, alpha) =>
      top.map((c, i) => Math.round(c * alpha + bottom[i] * (1 - alpha)));

    // The ground behind an element: composite every translucent layer up to the
    // first opaque one. A gradient counts as opaque — `backgroundColor` returns
    // transparent for it, which is how a navy band once measured as white.
    const groundOf = (el) => {
      let node = el;
      const layers = [];

      while (node && node !== document.documentElement.parentNode) {
        const cs = getComputedStyle(node);
        const bg = parseColour(cs.backgroundColor);

        if (cs.backgroundImage && cs.backgroundImage !== 'none') {
          // Cannot be sampled from computed style; the caller excludes these.
          return null;
        }

        if (bg && bg.alpha > 0) {
          if (bg.alpha >= 0.999) {
            let ground = bg.rgb;
            for (let i = layers.length - 1; i >= 0; i--) {
              ground = blend(layers[i].rgb, ground, layers[i].alpha);
            }
            return ground;
          }
          layers.push(bg);
        }

        node = node.parentElement;
      }

      return [255, 255, 255];
    };

    const failures = [];
    const seen = new Set();

    document.querySelectorAll('p, h1, h2, h3, h4, span, a, li, td, th, summary, dd, dt').forEach((el) => {
      const text = (el.textContent || '').trim();
      if (!text || el.children.length > 0) return;

      const rect = el.getBoundingClientRect();
      if (rect.width === 0 || rect.height === 0) return;

      const cs = getComputedStyle(el);
      if (cs.visibility === 'hidden' || cs.opacity === '0') return;

      const fg = parseColour(cs.color);
      const ground = groundOf(el);
      if (!fg || !ground) return;

      const size = parseFloat(cs.fontSize);
      const weight = parseInt(cs.fontWeight, 10) || 400;
      // WCAG "large text": 18.66px bold or 24px regular.
      const large = size >= 24 || (size >= 18.66 && weight >= 700);
      const threshold = large ? 3 : min;

      const r = ratio(fg.rgb, ground);

      if (r < threshold) {
        const key = `${cs.color}|${ground.join(',')}|${el.className}`;
        if (seen.has(key)) return;
        seen.add(key);

        failures.push({
          text: text.slice(0, 60),
          className: String(el.className),
          colour: cs.color,
          ground: `rgb(${ground.join(',')})`,
          ratio: Math.round(r * 100) / 100,
          threshold,
          fontSize: size,
        });
      }
    });

    return failures;
  }, minimum);
}

/* -------------------------------------------------------------------- tests */

test.describe('The landing page', () => {
  test('renders in English with no console errors', async ({ page }) => {
    const errors = [];
    page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
    page.on('pageerror', (e) => errors.push(String(e)));

    const response = await page.goto('/en');

    expect(response?.status()).toBe(200);
    await expect(page.locator('h1')).toHaveCount(1);
    await expect(page.locator('h1')).toBeVisible();
    expect(errors).toEqual([]);
  });

  test('loads none of the admin stylesheets or scripts', async ({ page }) => {
    // The reason this page has its own layout at all: app.css is 399 KB,
    // theme.css 93 KB, bootstrap-icons another 110 KB of webfont.
    const requested = [];
    page.on('request', (r) => requested.push(r.url()));

    await page.goto('/en');
    await page.waitForLoadState('networkidle');

    const forbidden = requested.filter((u) =>
      /main\/(app|rtl)\.css|theme\.css|custom\.css|jquery|apexcharts|select2|filepond|tinymce|sweetalert2|bootstrap-icons/i.test(u)
    );

    expect(forbidden, `admin assets requested: ${forbidden.join(', ')}`).toEqual([]);
  });

  test('makes no third-party requests', async ({ page }) => {
    const external = [];
    page.on('request', (r) => {
      const url = new URL(r.url());
      if (!['127.0.0.1', 'localhost'].includes(url.hostname)) external.push(r.url());
    });

    await page.goto('/en');
    await page.waitForLoadState('networkidle');

    // Fonts are self-hosted precisely so this stays empty — no
    // fonts.googleapis.com, no map tiles, nothing that can be slow or blocked.
    expect(external).toEqual([]);
  });

  test('lays out right-to-left in Arabic and paints the Arabic font', async ({ page }) => {
    await page.goto('/ar');

    expect(await isRtl(page)).toBe(true);
    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');

    // The font actually in use, not the one requested. Nunito ships no Arabic
    // subset, so before this work every Arabic screen fell back to whatever the
    // browser picked.
    const family = await page.evaluate(() =>
      getComputedStyle(document.querySelector('h1')).fontFamily
    );
    expect(family).toContain('IBM Plex Sans Arabic');

    const loaded = await page.evaluate(() =>
      document.fonts.check('700 2rem "IBM Plex Sans Arabic"')
    );
    expect(loaded).toBe(true);
  });

  test('gives Arabic looser leading and no letter-spacing', async ({ page }) => {
    // Arabic is cursive: tracking pulls the joins apart, and the negative
    // tracking a Latin headline wants breaks the word shape outright.
    await page.goto('/ar');

    const h1 = await page.evaluate(() => {
      const cs = getComputedStyle(document.querySelector('h1'));
      return {
        spacing: cs.letterSpacing,
        lineHeight: parseFloat(cs.lineHeight) / parseFloat(cs.fontSize),
      };
    });

    expect(h1.spacing === 'normal' || parseFloat(h1.spacing) === 0).toBe(true);
    expect(h1.lineHeight).toBeGreaterThan(1.2);
  });

  test('the price table becomes labelled rows on a phone', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/en#prices');

    const table = page.locator('.price-table').first();
    await expect(table).toBeVisible();

    // The column headings move into `::before` content from data-label, so the
    // thead is hidden rather than the table scrolling — ten items across three
    // services does not scroll usefully at 375px.
    const theadHidden = await page.evaluate(() => {
      const thead = document.querySelector('.price-table thead');
      return thead.getBoundingClientRect().width <= 1;
    });
    expect(theadHidden).toBe(true);

    const label = await page.evaluate(() => {
      const td = document.querySelector('.price-table tbody tr:not(.price-group) td');
      return getComputedStyle(td, '::before').content;
    });
    expect(label).not.toBe('none');
  });

  test('nothing overflows horizontally at 375px, in either language', async ({ page }) => {
    for (const path of ['/en', '/ar']) {
      await page.setViewportSize({ width: 375, height: 812 });
      await page.goto(path);
      await page.waitForLoadState('networkidle');

      const overflow = await page.evaluate(() => {
        const doc = document.documentElement;
        const offenders = [];

        if (doc.scrollWidth > doc.clientWidth + 1) {
          document.querySelectorAll('*').forEach((el) => {
            const r = el.getBoundingClientRect();
            if (r.right > doc.clientWidth + 1 || r.left < -1) {
              const cs = getComputedStyle(el);
              // A deliberately scrollable container is not an offender.
              if (cs.overflowX === 'auto' || cs.overflowX === 'scroll') return;
              offenders.push(`${el.tagName}.${el.className}`.slice(0, 80));
            }
          });
        }

        return { width: doc.scrollWidth, client: doc.clientWidth, offenders: offenders.slice(0, 8) };
      });

      expect(
        overflow.width,
        `${path} scrolls sideways at 375px: ${overflow.offenders.join(', ')}`
      ).toBeLessThanOrEqual(overflow.client + 1);
    }
  });

  test('the mobile menu is keyboard operable and reports its state', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/en');

    const toggle = page.locator('[data-nav-toggle]');
    await expect(toggle).toBeVisible();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');

    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await expect(page.locator('#site-nav')).toBeVisible();

    // Escape closes it and hands focus back, which is what a keyboard user
    // expects and what CSS alone cannot do.
    await page.keyboard.press('Escape');
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(toggle).toBeFocused();
  });

  test('the FAQ works without JavaScript', async ({ browser }) => {
    // Native details/summary. An accordion built from click handlers has to
    // reimplement keyboard operation and the open state, and usually gets one
    // of them wrong.
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();

    await page.goto('/en#faq');

    const second = page.locator('.faq-item').nth(1);
    await expect(second).not.toHaveAttribute('open', '');

    await second.locator('summary').click();
    await expect(second).toHaveAttribute('open', '');

    await context.close();
  });

  test('with JavaScript off the page is still fully visible', async ({ browser }) => {
    // The reveal animation hides elements only once the inline head script has
    // confirmed it can animate them. The usual version of this pattern hides
    // everything in CSS and depends on script to show it, which fails silently
    // and takes the whole page with it.
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();

    await page.goto('/en');

    const hidden = await page.evaluate(() =>
      [...document.querySelectorAll('[data-reveal]')].filter(
        (el) => parseFloat(getComputedStyle(el).opacity) < 0.99
      ).length
    );

    expect(hidden).toBe(0);
    await context.close();
  });

  test('reduced motion leaves every value in its final state', async ({ browser }) => {
    // The trap: the review card's count-up starts from the *estimate*. A
    // reduced-motion visitor who never sees the animation must not be left
    // looking at a number that is not the price being approved.
    const context = await browser.newContext({ reducedMotion: 'reduce' });
    const page = await context.newPage();

    await page.goto('/en');
    await page.waitForLoadState('networkidle');

    const state = await page.evaluate(() => {
      const actual = document.querySelector('[data-count-to]');
      const pieces = actual?.querySelector('[data-review-pieces]');
      const timeline = document.querySelector('[data-timeline]');
      const rail = timeline ? getComputedStyle(timeline, '::after').transform : null;

      return {
        expected: actual?.getAttribute('data-count-to') ?? null,
        rendered: pieces ? pieces.textContent.replace(/\D+/g, '') : null,
        rail,
        revealed: [...document.querySelectorAll('[data-reveal]')].every(
          (el) => parseFloat(getComputedStyle(el).opacity) > 0.99
        ),
      };
    });

    if (state.expected !== null) {
      expect(state.rendered).toBe(state.expected);
    }

    // The rail is drawn, not stuck at scaleX(0).
    expect(state.rail).not.toContain('matrix(0,');
    expect(state.revealed).toBe(true);

    await context.close();
  });

  test('the timeline flips to a vertical spine on a phone', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/en#journey');

    const columns = await page.evaluate(
      () => getComputedStyle(document.querySelector('[data-timeline]')).gridTemplateColumns
    );

    // One column, not six squeezed into 375px.
    expect(columns.split(' ').length).toBe(1);
  });

  test('text clears WCAG AA in light mode, both languages', async ({ page }) => {
    for (const path of ['/en', '/ar']) {
      await page.emulateMedia({ colorScheme: 'light' });
      await page.goto(path);
      await page.waitForLoadState('networkidle');

      const failures = await contrastFailures(page);

      expect(
        failures,
        `${path} light: ${JSON.stringify(failures, null, 2)}`
      ).toEqual([]);
    }
  });

  test('text clears WCAG AA in dark mode', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.goto('/en');
    await page.waitForLoadState('networkidle');

    const failures = await contrastFailures(page);

    expect(failures, `dark: ${JSON.stringify(failures, null, 2)}`).toEqual([]);
  });

  test('dark mode follows the OS and the panel-stored choice', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.goto('/en');

    const osDark = await page.evaluate(
      () => getComputedStyle(document.body).backgroundColor
    );
    expect(osDark).toBe('rgb(15, 21, 34)');

    // The panel writes theme-light/theme-dark to the same localStorage key, so
    // somebody who chose light there is not handed a dark page here.
    await page.evaluate(() => localStorage.setItem('theme', 'theme-light'));
    await page.reload();

    const forcedLight = await page.evaluate(
      () => getComputedStyle(document.body).backgroundColor
    );
    expect(forcedLight).toBe('rgb(247, 250, 252)');
  });

  test('a guest can switch language and land on the right URL', async ({ page }) => {
    await page.goto('/en');
    await page.click('.lang-switch a[hreflang="ar"]');

    await expect(page).toHaveURL(/\/ar$/);
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
  });

  test('every in-page anchor resolves to a section', async ({ page }) => {
    await page.goto('/en');

    const broken = await page.evaluate(() =>
      [...document.querySelectorAll('a[href^="#"]')]
        .map((a) => a.getAttribute('href'))
        .filter((h) => h && h !== '#' && !document.querySelector(h))
    );

    expect(broken).toEqual([]);
  });

  test('every image has alt text and a resolved source', async ({ page }) => {
    const failed = [];
    page.on('response', (r) => {
      if (r.request().resourceType() === 'image' && r.status() >= 400) failed.push(r.url());
    });

    await page.goto('/en');
    await page.waitForLoadState('networkidle');

    const missingAlt = await page.evaluate(() =>
      [...document.querySelectorAll('img')]
        .filter((img) => img.getAttribute('alt') === null)
        .map((img) => img.src)
    );

    expect(missingAlt).toEqual([]);
    expect(failed).toEqual([]);
  });

  test('the skip link is reachable on the first tab', async ({ page }) => {
    await page.goto('/en');
    await page.keyboard.press('Tab');

    const focused = await page.evaluate(() => document.activeElement?.className);
    expect(focused).toContain('skip-link');
  });

  test('the illustrative review buttons are not in the tab order', async ({ page }) => {
    // They show a decision made in the app. Focusable non-functional controls
    // promise something this page cannot do.
    await page.goto('/en');

    const focusable = await page.evaluate(() =>
      document.querySelectorAll('.review-btn button, .review-btn[tabindex], button.review-btn').length
    );

    expect(focusable).toBe(0);
  });
});
