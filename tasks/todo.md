# Let a signed-in user see the landing page

Today bare `/` redirects anyone with a session to `/admin/home`, so an operator,
a laundry owner or a staff member cannot look at the marketing site at all
without signing out. `/ar` and `/en` already let them through — only `/` does
this — which makes the behaviour inconsistent as well as unhelpful.

Replace the redirect with a bar across the top of the landing page offering the
way in, pointed at the panel the signer-in actually has.

## Plan

- [x] `User::canReachPanel()` — one definition of "may reach /admin", used by
      `EnsureDashboardRole` **and** the bar. Two copies would eventually offer a
      button that 403s.
- [x] Drop the redirect in `LandingController::index()`.
- [x] `landing/partials/_account_bar.blade.php` — full-width, above the sticky
      header, scrolls away. Name, role, and a button to the panel.
- [x] Render it from `layouts.landing`, so the legal pages get it too.
- [x] `landing.css` — theme-aware tokens, logical properties (one stylesheet
      serves both directions), and it must not break at 400px.
- [x] Web File keys + `laundo:sync-web-lang`. No copy hardcoded in a Blade file.
- [x] Rewrite the two tests that pin the redirect; add tests for the bar,
      including that a customer is not offered a panel they cannot open.

## Decisions

- **Both panel role types land on `/admin/home`.** That *is* each one's own
  dashboard — the home page renders its panels from the viewer's permissions,
  and a laundry owner sees their half of it. There is no second URL to send
  anyone to.
- **An `app` role (customer, driver) gets no bar.** They have no panel, so a
  «go to your dashboard» button would be a 403 dressed as an invitation.
- Not sticky. The header already is, and two stacked sticky bars eat a phone
  screen.

## Review

Done and driven in a real browser, signed in as each account type.

- Super admin and laundry owner both get the bar; the owner's reads «Signed in
  as Owner A · Laundry Owner», which is the point of the role chip — on a shared
  browser it answers *whose* dashboard is about to open.
- The redirect is gone: `/` stays on `/` for a signed-in user.
- RTL mirrors with no extra rules, because the bar is built from logical
  properties and `.btn-icon` was already flipped by the stylesheet's RTL block.
- Dark mode needed the same hairline `.band--navy` gets — without it the navy
  strip and the dark header ran together.

Two things the browser caught that the tests could not:

- The button label wrapped to two lines and took the strip from 54px to 108px.
  `flex: none` + `white-space: nowrap`; the sentence beside it is the half that
  may wrap.
- The arrow rendered at zero width. `.btn-icon` had a hover transform and an RTL
  flip but no size — nothing in the views had ever rendered one, so the gap had
  never shown. Sized on the class.

Not chased, and not caused here: `staffa@test.local` and `customer@test.local`
do not accept the password `tests/Browser/helpers.js` lists for them, so the
customer case could not be walked in the browser. Both accounts are `active`, so
it is the dev database's passwords rather than the sign-in gate, and
`a_customer_is_not_offered_a_panel_they_cannot_open` covers the behaviour through
`actingAs()`.
