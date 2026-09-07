<?php

namespace Tests\Feature\Landing;

use App\Models\Language;
use App\Modules\City\Models\City;
use App\Modules\Coupon\Models\Coupon;
use App\Modules\Faq\Models\Faq;
use App\Modules\JourneyStep\Models\JourneyStep;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Offer\Models\Offer;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Setting\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The public landing page.
 *
 * The assertions worth explaining are the ones about what is **absent**. This
 * database holds `Laundry A`, coupon `SMOKE10`, three `PWTEST…` coupons,
 * `App_Name = BaseCode`, Latin filler in `About` and seven social settings
 * pointing at their networks' front pages — all of it renders perfectly happily
 * on an admin screen and none of it may reach a public page. Those are the
 * tests most likely to catch a future regression, because the failure mode is
 * a page that looks completely fine.
 */
class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seedCore();
    }

    // ---------------------------------------------------------------------
    // Routing
    // ---------------------------------------------------------------------

    #[Test]
    public function a_guest_gets_the_landing_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(webText('landing.hero.title'), false)
            ->assertSee(webText('landing.hero.cta_secondary'), false);
    }

    #[Test]
    public function a_signed_in_dashboard_user_still_goes_to_the_panel(): void
    {
        // The behaviour the old `/` closure had, and the reason it survives:
        // somebody with a session wants their panel, not the sales pitch.
        $this->actingAs($this->superAdmin())
            ->get('/')
            ->assertRedirect('/admin/home');
    }

    #[Test]
    public function the_localised_addresses_pin_their_language(): void
    {
        $this->get('/ar')
            ->assertOk()
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false);

        $this->get('/en')
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('dir="ltr"', false);
    }

    #[Test]
    public function a_locale_route_is_pinned_even_for_a_signed_in_user(): void
    {
        // `/ar` is an address, not a preference. Only bare `/` redirects.
        $this->actingAs($this->superAdmin())
            ->get('/ar')
            ->assertOk()
            ->assertSee('lang="ar"', false);
    }

    #[Test]
    public function a_two_letter_code_that_is_not_a_language_is_a_404(): void
    {
        // Not a soft fallback: serving `/zz` the default language would publish
        // the same page under an unbounded set of addresses.
        $this->get('/zz')->assertNotFound();
    }

    #[Test]
    public function the_health_check_is_not_shadowed_by_the_locale_route(): void
    {
        // `/up` is two lowercase letters and `{locale}` matches two lowercase
        // letters. Deployment tooling watches this endpoint.
        $this->get('/up')->assertOk();
    }

    #[Test]
    public function the_login_form_keeps_its_own_address(): void
    {
        $this->get('/login')->assertOk();
    }

    // ---------------------------------------------------------------------
    // Language switching
    // ---------------------------------------------------------------------

    #[Test]
    public function a_guest_can_switch_language(): void
    {
        $this->get('/locale/ar')
            ->assertRedirect('/ar')
            ->assertSessionHas('language');

        $this->get('/')
            ->assertOk()
            ->assertSee('lang="ar"', false);
    }

    #[Test]
    public function switching_language_returns_to_the_same_page(): void
    {
        $this->get('/locale/ar?to=/terms')->assertRedirect('/ar/terms');
    }

    #[Test]
    public function an_unknown_language_code_does_not_break_the_switch(): void
    {
        $this->get('/locale/zz')->assertRedirect('/');
    }

    #[Test]
    public function a_guest_with_no_session_gets_the_language_their_browser_asks_for(): void
    {
        // English holds the `default` flag on this install while every word of
        // the product's content is Arabic, so a browser asking for Arabic must
        // not be handed English.
        $this->get('/', ['Accept-Language' => 'ar-EG,ar;q=0.9,en;q=0.5'])
            ->assertOk()
            ->assertSee('lang="ar"', false);
    }

    #[Test]
    public function a_chosen_language_outranks_the_browsers_header(): void
    {
        // Somebody who picked has chosen. The header must not overrule them on
        // the next page load.
        $this->withSession(['language' => Language::where('code', 'en')->first()])
            ->get('/', ['Accept-Language' => 'ar-EG,ar;q=0.9'])
            ->assertOk()
            ->assertSee('lang="en"', false);
    }

    #[Test]
    public function header_detection_does_not_apply_to_a_signed_in_user(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/ar', ['Accept-Language' => 'en-US,en;q=0.9'])
            ->assertOk()
            ->assertSee('lang="ar"', false);
    }

    // ---------------------------------------------------------------------
    // Structure, SEO and accessibility
    // ---------------------------------------------------------------------

    #[Test]
    public function the_page_has_exactly_one_h1(): void
    {
        $html = $this->get('/en')->assertOk()->getContent();

        $this->assertSame(1, preg_match_all('/<h1[\s>]/i', (string) $html));
    }

    #[Test]
    public function the_heading_hierarchy_skips_no_level(): void
    {
        $html = (string) $this->get('/en')->assertOk()->getContent();

        preg_match_all('/<h([1-6])[\s>]/i', $html, $matches);
        $levels = array_map('intval', $matches[1]);

        $this->assertNotEmpty($levels);
        $this->assertSame(1, $levels[0], 'the first heading should be the h1');

        $seen = array_unique($levels);
        sort($seen);

        // 1, 2, 3 — contiguous from 1, with nothing jumped over.
        $this->assertSame(range(1, count($seen)), $seen, 'heading levels skip a step: '.implode(',', $seen));
    }

    #[Test]
    public function the_seo_metadata_is_present_and_localised(): void
    {
        $en = (string) $this->get('/en')->getContent();

        $this->assertStringContainsString('<title>'.webText('landing.seo.title'), $en);
        $this->assertStringContainsString('name="description"', $en);
        $this->assertStringContainsString('rel="canonical" href="'.url('/en').'"', $en);
        $this->assertStringContainsString('property="og:image"', $en);
        $this->assertStringContainsString('content="en_US"', $en);
        $this->assertStringContainsString('name="twitter:card"', $en);

        // One alternate per language, plus x-default.
        $this->assertStringContainsString('hreflang="en" href="'.url('/en').'"', $en);
        $this->assertStringContainsString('hreflang="ar" href="'.url('/ar').'"', $en);
        $this->assertStringContainsString('hreflang="x-default" href="'.url('/').'"', $en);
    }

    #[Test]
    public function the_arabic_page_carries_its_own_metadata(): void
    {
        app()->setLocale('ar');
        $expected = webText('landing.seo.title');
        app()->setLocale('en');

        $ar = (string) $this->get('/ar')->getContent();

        $this->assertStringContainsString($expected, $ar);
        $this->assertStringContainsString('content="ar_EG"', $ar);
        $this->assertStringContainsString('rel="canonical" href="'.url('/ar').'"', $ar);
    }

    #[Test]
    public function the_structured_data_is_organisation_and_faq_only(): void
    {
        $html = (string) $this->get('/en')->getContent();

        $this->assertStringContainsString('"@type":"Organization"', $html);
        $this->assertStringContainsString('"@type":"FAQPage"', $html);

        // Explicitly refused: no address is configured, prices move, and
        // `order_ratings` holds zero rows — so any of these would be invented.
        foreach (['LocalBusiness', 'aggregateRating', 'reviewCount', 'AggregateOffer'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html, "{$forbidden} must not appear in the structured data");
        }
    }

    #[Test]
    public function a_skip_link_is_the_first_focusable_thing_on_the_page(): void
    {
        $html = (string) $this->get('/en')->getContent();

        $this->assertStringContainsString('class="skip-link" href="#main"', $html);
        $this->assertStringContainsString('id="main"', $html);
    }

    // ---------------------------------------------------------------------
    // Performance: the admin ecosystem must not follow the page out
    // ---------------------------------------------------------------------

    #[Test]
    public function the_landing_page_loads_none_of_the_admin_assets(): void
    {
        // app.css is 399 KB, theme.css 93 KB and bootstrap-icons another 110 KB
        // of webfont. Loading any of them here would undo the entire reason
        // this page has its own layout.
        $html = (string) $this->get('/en')->getContent();

        foreach ([
            'css/main/app.css',
            'css/main/rtl.css',
            'css/theme.css',
            'css/custom.css',
            'jquery.min.js',
            'apexcharts',
            'select2',
            'filepond',
            'tinymce',
            'sweetalert2',
            'bootstrap.min.js',
        ] as $asset) {
            $this->assertStringNotContainsString($asset, $html, "the landing page must not load {$asset}");
        }

        $this->assertStringContainsString('assets/css/landing.css', $html);
        $this->assertStringContainsString('assets/js/landing.js', $html);
    }

    #[Test]
    public function the_landing_assets_are_fingerprinted(): void
    {
        // Served straight out of public/ with no build step, so a deploy that
        // changes them has nothing else to invalidate a visitor's cache.
        $html = (string) $this->get('/en')->getContent();

        $this->assertMatchesRegularExpression('#assets/css/landing\.css\?v=\d+#', $html);
        $this->assertMatchesRegularExpression('#assets/js/landing\.js\?v=\d+#', $html);
    }

    #[Test]
    public function only_the_active_languages_font_is_preloaded(): void
    {
        $ar = (string) $this->get('/ar')->getContent();
        $en = (string) $this->get('/en')->getContent();

        // Preloading a family the page will not paint a glyph from wastes the
        // request, and `unicode-range` is what decides which one that is: the
        // Arabic page paints Arabic from IBM Plex and the English page paints
        // Latin from Nunito.
        $this->assertStringContainsString('ibm-plex-sans-arabic-arabic-400-normal.woff2', $ar);
        $this->assertStringNotContainsString('nunito-latin-400-normal.woff2', $ar);

        $this->assertStringContainsString('nunito-latin-400-normal.woff2', $en);
        $this->assertStringNotContainsString('ibm-plex-sans-arabic-arabic-400-normal.woff2', $en);
    }

    // ---------------------------------------------------------------------
    // No development data on a public page
    // ---------------------------------------------------------------------

    #[Test]
    public function no_test_or_seed_data_reaches_the_page(): void
    {
        $this->seedGeo();
        $this->seedCatalog();

        // Exactly the rows this install really carries.
        Laundry::withoutGlobalScopes()->create([
            'name' => json_encode(['en' => 'Laundry A', 'ar' => 'مغسلة A'], JSON_UNESCAPED_UNICODE),
            'phone' => '+201000000001', 'city_id' => 1, 'status' => 'active',
        ]);

        Setting::create(['key' => 'App_Name', 'value' => 'BaseCode']);
        Setting::create(['key' => 'Email', 'value' => 'nahrPhpTeam@nahrPhpTeam.com']);
        Setting::create(['key' => 'Facebook_Url', 'value' => 'http://facebook.com/']);
        Setting::create(['key' => 'Whats_App', 'value' => 'http://whatsapp.com/']);
        Setting::create(['key' => 'About', 'value' => json_encode([
            'en' => 'It is in fact part of the Latin gibberish that printers use.',
        ])]);
        Cache::flush();

        foreach (['/en', '/ar'] as $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();

            // Word-bounded: "the laundry actually found" is prose, not a leak.
            $this->assertDoesNotMatchRegularExpression('/\bLaundry [AB]\b/', $html, "{$url} leaks a fixture laundry");
            $this->assertDoesNotMatchRegularExpression('/\bمغسلة [AB]\b/u', $html, "{$url} leaks a fixture laundry");

            foreach ([
                'SMOKE10',
                'PWTEST',
                'BaseCode',
                'Latin gibberish',
                'nahrPhpTeam',
                'facebook.com',
                'whatsapp.com',
                'gmail.com',
                'logo1.png',
                'no_image_available',
            ] as $needle) {
                $this->assertStringNotContainsString($needle, $html, "{$url} leaks '{$needle}'");
            }
        }
    }

    #[Test]
    public function a_real_contact_setting_does_render(): void
    {
        // The filter must refuse placeholders without refusing everything —
        // otherwise it is indistinguishable from a footer that never worked.
        Setting::create(['key' => 'Email', 'value' => 'hello@laundo.example']);
        Setting::create(['key' => 'Hotline', 'value' => '+20 100 555 0000']);
        Cache::flush();

        $html = (string) $this->get('/en')->getContent();

        $this->assertStringContainsString('hello@laundo.example', $html);
        $this->assertStringContainsString('+20 100 555 0000', $html);
        // Spaces are for reading, not for dialling.
        $this->assertStringContainsString('tel:+201005550000', $html);
    }

    #[Test]
    public function a_real_social_profile_does_render(): void
    {
        Setting::create(['key' => 'Instagram_Url', 'value' => 'https://instagram.com/laundoeg']);
        Cache::flush();

        $html = (string) $this->get('/en')->getContent();

        $this->assertStringContainsString('https://instagram.com/laundoeg', $html);
        $this->assertStringContainsString('Instagram', $html);
        // And it reaches the structured data, which is the only place sameAs
        // should ever come from.
        $this->assertStringContainsString('"sameAs"', $html);
    }

    #[Test]
    public function the_primary_cta_falls_back_to_the_prices_rather_than_a_placeholder(): void
    {
        Setting::create(['key' => 'Whats_App', 'value' => 'http://whatsapp.com/']);
        Cache::flush();

        $html = (string) $this->get('/en')->getContent();

        $this->assertStringNotContainsString('whatsapp.com', $html);
        $this->assertStringContainsString('href="#prices"', $html);
    }

    #[Test]
    public function a_configured_store_link_becomes_the_primary_cta(): void
    {
        Setting::create(['key' => 'App_Store_Url', 'value' => 'https://apps.apple.com/app/laundo/id123']);
        Setting::create(['key' => 'Play_Store_Url', 'value' => 'https://play.google.com/store/apps/details?id=com.laundo']);
        Cache::flush();

        $html = (string) $this->get('/en')->getContent();

        $this->assertStringContainsString('https://apps.apple.com/app/laundo/id123', $html);
        $this->assertStringContainsString('App Store', $html);
        $this->assertStringContainsString('Google Play', $html);
    }

    // ---------------------------------------------------------------------
    // Real data in, real data out
    // ---------------------------------------------------------------------

    #[Test]
    public function the_price_grid_comes_from_item_prices(): void
    {
        $this->seedCatalog();
        Cache::flush();

        $html = (string) $this->get('/en')->getContent();

        $this->assertStringContainsString('Shirt', $html);
        $this->assertStringContainsString(moneyFormat(17), $html);
        $this->assertStringContainsString(moneyFormat(23), $html);
    }

    #[Test]
    public function the_review_example_is_arithmetically_correct(): void
    {
        $this->seedCatalog();
        Cache::flush();

        $html = (string) $this->get('/en')->getContent();

        // seedCatalog puts both items in one category and the example takes one
        // item per category, so the basket is 3 shirts at 17 and the laundry
        // finds a fourth.
        $this->assertStringContainsString(moneyFormat(51), $html, 'the estimate subtotal');
        $this->assertStringContainsString(moneyFormat(68), $html, 'the counted subtotal');
        $this->assertStringContainsString(moneyFormat(17), $html, 'the difference');

        // And the markup carries the final figures, so a reduced-motion reader
        // is never shown the estimate as if it were the price.
        $this->assertMatchesRegularExpression('/data-count-from="3"\s+data-count-to="4"/', $html);
    }

    #[Test]
    public function the_review_card_is_omitted_when_nothing_is_priced(): void
    {
        // No catalogue at all: the hero drops the card rather than rendering an
        // empty one or dividing by zero.
        $html = (string) $this->get('/en')->assertOk()->getContent();

        $this->assertStringNotContainsString('data-review-card', $html);
    }

    #[Test]
    public function coverage_lists_only_cities_that_have_an_active_zone(): void
    {
        $geo = $this->seedGeo();

        City::create([
            'name' => json_encode(['en' => 'Aswan', 'ar' => 'أسوان'], JSON_UNESCAPED_UNICODE),
            'country_id' => $geo['country']->id, 'status' => 'active',
        ]);
        Cache::flush();

        $html = (string) $this->get('/en')->getContent();

        $this->assertStringContainsString('Cairo', $html);
        $this->assertStringContainsString('Nasr City', $html);
        // 27 governorates are seeded on the real install and two have coverage.
        // A city with no zone would promise an area nobody can order from.
        $this->assertStringNotContainsString('Aswan', $html);
    }

    #[Test]
    public function the_order_steps_come_from_the_journey_step_screen(): void
    {
        // Three real Arabic rows with a CRUD screen behind them that nothing on
        // the web was reading — the "a screen producing content nothing can
        // fetch" shape banners, intros and the static pages all had.
        JourneyStep::create([
            'title' => json_encode(['en' => 'Pick a time', 'ar' => 'حدد الموعد'], JSON_UNESCAPED_UNICODE),
            'description' => json_encode(['en' => 'When suits you', 'ar' => 'الوقت المناسب لك'], JSON_UNESCAPED_UNICODE),
            'sort_order' => 1,
            'status' => 'active',
        ]);
        JourneyStep::create([
            'title' => json_encode(['en' => 'Hidden step', 'ar' => 'خطوة مخفية'], JSON_UNESCAPED_UNICODE),
            'sort_order' => 2,
            'status' => 'inactive',
        ]);
        Cache::flush();

        $html = (string) $this->get('/en')->assertOk()->getContent();

        $this->assertStringContainsString('Pick a time', $html);
        $this->assertStringContainsString('When suits you', $html);
        $this->assertStringNotContainsString('Hidden step', $html);
    }

    #[Test]
    public function a_live_offer_renders_and_a_test_coupon_badge_does_not(): void
    {
        // The install's one offer links to `SMOKE10`, and `Offer::badge()`
        // renders the linked coupon's discount — so an unguarded render would
        // advertise a smoke-test coupon on the front page as a code a customer
        // could try. The offer's own copy is real and still shows.
        $coupon = Coupon::create([
            'code' => 'SMOKE10',
            'type' => 'fixed',
            'value' => 10,
            'status' => 'active',
        ]);

        Offer::create([
            'title' => json_encode(['en' => 'Blanket bundle', 'ar' => 'باقة البطاطين'], JSON_UNESCAPED_UNICODE),
            'description' => json_encode(['en' => 'Ready for winter', 'ar' => 'استعد للشتاء'], JSON_UNESCAPED_UNICODE),
            'coupon_id' => $coupon->id,
            'target_type' => 'none',
            'sort_order' => 1,
            'status' => 'active',
        ]);
        Cache::flush();

        $html = (string) $this->get('/en')->assertOk()->getContent();

        $this->assertStringContainsString('Blanket bundle', $html);
        $this->assertStringContainsString('Ready for winter', $html);
        $this->assertStringNotContainsString('SMOKE10', $html);
        $this->assertStringNotContainsString('offer-badge', $html);
    }

    #[Test]
    public function a_real_coupon_does_get_its_badge(): void
    {
        // The guard must refuse test codes without refusing every code —
        // otherwise it is indistinguishable from a badge that never worked.
        $coupon = Coupon::create([
            'code' => 'WELCOME20',
            'type' => 'fixed',
            'value' => 20,
            'status' => 'active',
        ]);

        Offer::create([
            'title' => json_encode(['en' => 'Welcome offer', 'ar' => 'عرض الترحيب'], JSON_UNESCAPED_UNICODE),
            'coupon_id' => $coupon->id,
            'target_type' => 'none',
            'sort_order' => 1,
            'status' => 'active',
        ]);
        Cache::flush();

        $html = (string) $this->get('/en')->assertOk()->getContent();

        $this->assertStringContainsString('Welcome offer', $html);
        $this->assertStringContainsString('offer-badge', $html);
    }

    #[Test]
    public function the_offers_section_is_absent_between_campaigns(): void
    {
        $html = (string) $this->get('/en')->assertOk()->getContent();

        $this->assertStringNotContainsString('offer-grid', $html);
    }

    #[Test]
    public function a_payload_shape_change_does_not_serve_a_stale_cached_array(): void
    {
        // Found the hard way: adding two keys to `pageData()` left the previous
        // hour's cached array in place and the view died on an undefined
        // variable. Locally that is one cache:clear; live it is a 500 on the
        // front page for up to an hour after every deploy that adds a key.
        $this->get('/en')->assertOk();

        touch(base_path('app/Services/Landing/LandingContentService.php'));

        $this->get('/en')->assertOk();
    }

    #[Test]
    public function the_timeline_comes_from_the_order_state_machine(): void
    {
        $html = (string) $this->get('/en')->getContent();

        foreach (OrderStatus::trackingSteps() as $step) {
            $this->assertStringContainsString(__($step->label()), $html);
        }

        // A detour, not a milestone — and drawing it would make the line grow
        // when something goes wrong.
        $this->assertStringNotContainsString(__(OrderStatus::ReviewDisputed->label()), $html);
    }

    #[Test]
    public function payment_copy_names_cash_and_nothing_a_gateway_would_be_needed_for(): void
    {
        // The only gateway in the codebase is FakeGateway. Card, e-wallet and
        // InstaPay are enum cases the app draws and nothing can charge.
        //
        // The catalogue is seeded because the payment note lives inside the
        // prices section, which hides itself when nothing is priced — a
        // "Prices" heading over an empty table is worse than no section.
        $this->seedCatalog();
        Cache::flush();

        $html = (string) $this->get('/en')->getContent();

        foreach (['InstaPay', 'Bank card', 'E-wallet'] as $unavailable) {
            $this->assertStringNotContainsString($unavailable, $html);
        }

        $this->assertStringContainsString(webText('landing.prices.payment_body'), $html);
    }

    // ---------------------------------------------------------------------
    // Empty tables degrade rather than break
    // ---------------------------------------------------------------------

    #[Test]
    public function the_faq_falls_back_to_web_file_copy_when_the_table_is_empty(): void
    {
        $this->assertSame(0, Faq::count());

        $html = (string) $this->get('/en')->getContent();

        $this->assertStringContainsString(webText('landing.faq.q1'), $html);
        $this->assertStringContainsString(webText('landing.faq.a1'), $html);
    }

    #[Test]
    public function real_faq_rows_take_over_from_the_fallback(): void
    {
        Faq::create([
            'question' => json_encode(['en' => 'Do you collect on Fridays?', 'ar' => 'بتستلموا الجمعة؟'], JSON_UNESCAPED_UNICODE),
            'answer' => json_encode(['en' => 'Yes, every day.', 'ar' => 'أيوه، كل يوم.'], JSON_UNESCAPED_UNICODE),
            'audience' => 'customer', 'order' => 1, 'status' => 'active',
        ]);
        Cache::flush();

        $html = (string) $this->get('/en')->getContent();

        $this->assertStringContainsString('Do you collect on Fridays?', $html);
        $this->assertStringNotContainsString(webText('landing.faq.q1'), $html);
    }

    #[Test]
    public function a_driver_only_question_stays_out_of_the_customer_faq(): void
    {
        Faq::create([
            'question' => json_encode(['en' => 'When do I get paid?', 'ar' => 'بتقبض امتى؟'], JSON_UNESCAPED_UNICODE),
            'answer' => json_encode(['en' => 'Weekly.', 'ar' => 'كل أسبوع.'], JSON_UNESCAPED_UNICODE),
            'audience' => 'driver', 'order' => 1, 'status' => 'active',
        ]);
        Cache::flush();

        $html = (string) $this->get('/en')->getContent();

        $this->assertStringNotContainsString('When do I get paid?', $html);
    }

    // ---------------------------------------------------------------------
    // Legal pages
    // ---------------------------------------------------------------------

    #[Test]
    public function the_legal_pages_render_the_stored_settings(): void
    {
        Setting::create(['key' => 'Terms', 'value' => json_encode([
            'en' => 'These are the terms.', 'ar' => 'دي الشروط.',
        ], JSON_UNESCAPED_UNICODE)]);
        Cache::flush();

        $this->get('/en/terms')->assertOk()->assertSee('These are the terms.', false);
        $this->get('/ar/terms')->assertOk()->assertSee('دي الشروط.', false);
        $this->get('/terms')->assertOk();
    }

    #[Test]
    public function a_legal_page_with_no_stored_copy_says_so(): void
    {
        $this->get('/en/privacy')
            ->assertOk()
            ->assertSee(webText('landing.legal.empty'), false);
    }

    #[Test]
    public function an_unknown_legal_page_is_a_404(): void
    {
        $this->get('/en/cookies')->assertNotFound();
    }
}
