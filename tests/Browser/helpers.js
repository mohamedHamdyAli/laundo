/**
 * Shared helpers for the browser tests.
 *
 * Credentials come from DevFixturesSeeder, which refuses to run outside
 * local/testing, so these accounts only ever exist on a developer machine:
 *
 *   php artisan db:seed --class=DevFixturesSeeder
 */
export const ACCOUNTS = {
  superAdmin: { email: 'admin@admin.com', password: 'password' },
  ownerA: { email: 'ownera@test.local', password: 'password' },
  ownerB: { email: 'ownerb@test.local', password: 'password' },
  staffA: { email: 'staffa@test.local', password: 'password' },
  customer: { email: 'customer@test.local', password: 'password' },
};

/**
 * Signs in through the real form rather than by forging a session, so the login
 * page itself is exercised on every test.
 */
export async function login(page, account) {
  await page.goto('/login');
  await page.fill('input[name="email"]', account.email);
  await page.fill('input[name="password"]', account.password);
  await page.click('button[type="submit"]');
  await page.waitForLoadState('networkidle');
}

/**
 * The visible sidebar entries, as a plain array of labels.
 *
 * Reads the rendered menu rather than the config, because what matters is what
 * a person actually sees.
 */
export async function sidebarLabels(page) {
  const texts = await page.locator('#sidebar a, aside a, .sidebar a').allTextContents();

  return texts
    .map((t) => t.replace(/\s+/g, ' ').trim())
    .filter((t) => t.length > 0 && t.length < 40);
}

/** True when the document is laid out right-to-left. */
export async function isRtl(page) {
  return page.evaluate(() => {
    const html = document.documentElement;
    return html.getAttribute('dir') === 'rtl'
      || getComputedStyle(html).direction === 'rtl'
      || getComputedStyle(document.body).direction === 'rtl';
  });
}
