import { test, expect } from '@playwright/test';

/**
 * «سجّل مغسلتك» keeps what the applicant typed.
 *
 * The worst case for a full-page validation bounce in the whole codebase: a
 * file, two passwords and a map pin, none of which `old()` can carry. One
 * mistyped phone handed back an empty logo box, two empty password fields and
 * an unplaced pin — on the page whose entire job is signing up new laundries.
 *
 * Only a browser can prove this. PHPUnit can assert the 422 and the markup; it
 * cannot assert that the page did not navigate and the boxes still hold text.
 */
/**
 * Every field the browser itself insists on, so the submit actually leaves the
 * page. `required` is a real gate here: a form with an empty required box never
 * posts, so the server's 422 — the thing this spec is about — is only reachable
 * once native validation is satisfied.
 *
 * A unique suffix on the phone and email because the browser suite runs against
 * the development database and these columns are unique.
 */
async function fillEverything(page) {
  const n = String(Date.now()).slice(-7);

  await page.fill('#name-default', 'Sparkle Laundry');
  await page.fill('#phone', `+2010${n}`);
  await page.fill('#email', `sparkle${n}@example.test`);
  await page.selectOption('#city_id', { index: 1 });
  await page.fill('#owner_name', 'Rania');
  await page.fill('#owner_phone', `+2011${n}`);
  await page.fill('#owner_email', `rania${n}@example.test`);
  await page.fill('#owner_password', 'secret123');
  await page.fill('#owner_password_confirmation', 'secret123');
  await page.check('input[name="accepts_terms"]');
}

test.describe('Laundry registration validation', () => {
  test('a failed submit paints the field and does not reload the page', async ({ page }) => {
    await page.goto('/laundry/register');

    await fillEverything(page);

    // One mistyped phone — the report that started all of this. It passes the
    // browser's own `required` check and fails the server's E.164 rule, which is
    // the only shape of failure that can reach a 422: a natively-required field
    // left empty never posts at all.
    await page.fill('#phone', '01012345677');

    // The page must not navigate. A reload is the whole bug.
    let navigated = false;
    page.on('framenavigated', (f) => { if (f === page.mainFrame()) navigated = true; });

    const response = page.waitForResponse(
      (r) => r.url().includes('/laundry/register') && r.request().method() === 'POST'
    );
    await page.locator('.auth-submit').click();
    expect((await response).status()).toBe(422);

    // Painted beside a field, not only announced in a banner at the top.
    await expect(page.locator('.js-field-error').first()).toBeVisible();
    expect(await page.locator('.js-field-error').count()).toBeGreaterThan(0);

    expect(navigated, 'the form reloaded the page instead of posting in the background').toBe(false);

    // And what was typed is still there — the point of the whole exercise.
    await expect(page.locator('#name-default')).toHaveValue('Sparkle Laundry');
    await expect(page.locator('#phone')).toHaveValue('01012345677');

    // The two that `old()` can never carry, and the reason this was worth
    // doing: before the background submit, a failed validation handed the
    // applicant back two empty password boxes.
    await expect(page.locator('#owner_password')).toHaveValue('secret123');
    await expect(page.locator('#owner_password_confirmation')).toHaveValue('secret123');
  });

  test('the message is styled, not raw unstyled text', async ({ page }) => {
    await page.goto('/laundry/register');
    await fillEverything(page);
    await page.fill('#phone', '01012345677');

    const response = page.waitForResponse(
      (r) => r.url().includes('/laundry/register') && r.request().method() === 'POST'
    );
    await page.locator('.auth-submit').click();
    await response;

    // `auth-card.css` maps the script's `.js-field-error` onto the card's own
    // error colour. Without that mapping the messages render as default black
    // body text and read as help, not as a fault.
    const colour = await page.locator('.js-field-error').first().evaluate(
      (el) => getComputedStyle(el).color
    );

    expect(colour).not.toBe('rgb(0, 0, 0)');
  });

  test('the invalid field is outlined', async ({ page }) => {
    await page.goto('/laundry/register');
    await fillEverything(page);
    await page.fill('#phone', '01012345677');

    const response = page.waitForResponse(
      (r) => r.url().includes('/laundry/register') && r.request().method() === 'POST'
    );
    await page.locator('.auth-submit').click();
    await response;

    // `.is-invalid` is what the script paints; `.has-error` is what Blade paints
    // on a server render. auth-card.css gives them one rule, so the field looks
    // the same whichever path marked it.
    const field = page.locator('.auth-input.is-invalid, .auth-select.is-invalid').first();
    await expect(field).toBeVisible();

    const border = await field.evaluate((el) => getComputedStyle(el).borderColor);
    expect(border).not.toBe('rgb(0, 0, 0)');
  });
});
