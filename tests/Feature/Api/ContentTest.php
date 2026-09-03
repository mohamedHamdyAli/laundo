<?php

namespace Tests\Feature\Api;

use App\Modules\Banner\Models\banner;
use App\Modules\Coupon\Models\Coupon;
use App\Modules\Intro\Models\intro;
use App\Modules\JourneyStep\Models\JourneyStep;
use App\Modules\Offer\Models\Offer;
use App\Modules\Setting\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The content operations writes and the apps read.
 *
 * Three admin screens were producing content nothing could fetch: a banner could
 * be published and never seen, the onboarding slides had no source, and «الشروط
 * والأحكام» in the account screen had no endpoint behind it. That is the same
 * shape as a column added with no form field — complete from either end, dead in
 * the middle — so these tests check the whole path, not just that a route answers.
 *
 * The allow-list on /app-settings gets the most attention here, because it is the
 * one thing that can leak: the `settings` table mixes app content with
 * operational configuration, and nobody re-reviews an endpoint that already works.
 */
class ContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        // The settings map is cached for an hour; a test that writes a row and
        // reads a stale cache proves nothing.
        Cache::flush();
    }

    /** @param array<string, string> $translations */
    private function tr(array $translations): string
    {
        return json_encode($translations, JSON_UNESCAPED_UNICODE);
    }

    private function setting(string $key, string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::flush();
    }

    // ------------------------------------------------------------- banners

    #[Test]
    public function banners_return_only_the_active_ones(): void
    {
        banner::create([
            'image' => 'images/banners/a.png',
            'name' => $this->tr(['en' => 'Live', 'ar' => 'ظاهر']),
            'description' => $this->tr(['en' => 'x', 'ar' => 'س']),
            'status' => 'active',
        ]);

        banner::create([
            'image' => 'images/banners/b.png',
            'name' => $this->tr(['en' => 'Hidden', 'ar' => 'مخفي']),
            'description' => $this->tr(['en' => 'y', 'ar' => 'ص']),
            'status' => 'inactive',
        ]);

        $data = $this->getJson('/api/v1/banners')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('Live', $data[0]['title']);
    }

    #[Test]
    public function a_banner_with_a_service_target_carries_an_action(): void
    {
        $service = $this->seedCatalog()['service'];

        banner::create([
            'image' => 'images/banners/a.png',
            'name' => $this->tr(['en' => 'Winter bundle', 'ar' => 'عرض الشتاء']),
            'description' => $this->tr(['en' => 'Heavy fabrics', 'ar' => 'المفروشات الثقيلة']),
            'target_type' => 'service',
            'target_value' => (string) $service->id,
            'status' => 'active',
        ]);

        $data = $this->getJson('/api/v1/banners')->assertOk()->json('data.0');

        // Resolved server-side into a kind and a value, rather than shipping raw
        // columns and asking every client to agree on what they mean.
        $this->assertSame(
            ['type' => 'service', 'value' => (string) $service->id],
            $data['action']
        );
    }

    #[Test]
    public function an_informational_banner_carries_no_action_at_all(): void
    {
        banner::create([
            'image' => 'images/banners/a.png',
            'name' => $this->tr(['en' => 'Just a notice', 'ar' => 'مجرد إعلان']),
            'description' => $this->tr(['en' => 'no button', 'ar' => 'بدون زرار']),
            'target_type' => 'none',
            'target_value' => '',
            'status' => 'active',
        ]);

        $data = $this->getJson('/api/v1/banners')->assertOk()->json('data.0');

        // Null, not a kind of "none": a client checks for absence before drawing
        // the button, and «عرض التفاصيل» must not render with nowhere to go.
        $this->assertNull($data['action']);
    }

    #[Test]
    public function a_target_kind_with_no_value_is_treated_as_no_action(): void
    {
        // A row written before the columns existed, or one whose service was
        // deleted. It must render as a plain banner, not a dead button.
        banner::create([
            'image' => 'images/banners/a.png',
            'name' => $this->tr(['en' => 'Orphan', 'ar' => 'يتيم']),
            'description' => $this->tr(['en' => 'x', 'ar' => 'س']),
            'target_type' => 'service',
            'target_value' => '',
            'status' => 'active',
        ]);

        $this->assertNull($this->getJson('/api/v1/banners')->assertOk()->json('data.0.action'));
    }

    #[Test]
    public function an_unknown_target_kind_does_not_break_the_list(): void
    {
        $row = banner::create([
            'image' => 'images/banners/a.png',
            'name' => $this->tr(['en' => 'Renamed kind', 'ar' => 'نوع مُعاد تسميته']),
            'description' => $this->tr(['en' => 'x', 'ar' => 'س']),
            'status' => 'active',
        ]);

        // Written straight to the column, as a future rename would leave it.
        banner::withoutEvents(fn () => $row->forceFill(['target_type' => 'campaign'])->save());

        $data = $this->getJson('/api/v1/banners')->assertOk()->json('data.0');

        $this->assertNull($data['action']);
        $this->assertSame('Renamed kind', $data['title']);
    }

    #[Test]
    public function banners_follow_the_lang_header(): void
    {
        banner::create([
            'image' => 'images/banners/a.png',
            'name' => $this->tr(['en' => 'Winter bundle', 'ar' => 'عرض الشتاء']),
            'description' => $this->tr(['en' => 'Heavy fabrics', 'ar' => 'المفروشات الثقيلة']),
            'status' => 'active',
        ]);

        $this->assertSame(
            'عرض الشتاء',
            $this->getJson('/api/v1/banners', ['lang' => 'ar'])->assertOk()->json('data.0.title')
        );

        $this->assertSame(
            'Winter bundle',
            $this->getJson('/api/v1/banners', ['lang' => 'en'])->assertOk()->json('data.0.title')
        );
    }

    // -------------------------------------------------------------- intros

    #[Test]
    public function intros_come_back_in_the_order_operations_set(): void
    {
        foreach ([['Third', 3], ['First', 1], ['Second', 2]] as [$title, $order]) {
            intro::create([
                'image' => 'images/intros/a.png',
                'title' => $this->tr(['en' => $title, 'ar' => $title]),
                'description' => $this->tr(['en' => 'x', 'ar' => 'س']),
                'order' => $order,
                'status' => 'active',
            ]);
        }

        $titles = array_column(
            $this->getJson('/api/v1/intros')->assertOk()->json('data'),
            'title'
        );

        $this->assertSame(['First', 'Second', 'Third'], $titles);
    }

    #[Test]
    public function two_slides_sharing_an_order_number_still_come_back_in_a_fixed_order(): void
    {
        // Without the id tie-break these arrive in whatever sequence the database
        // feels like, and the first-run experience differs between two installs.
        $first = intro::create([
            'image' => 'images/intros/a.png',
            'title' => $this->tr(['en' => 'Alpha', 'ar' => 'ألفا']),
            'description' => $this->tr(['en' => 'x', 'ar' => 'س']),
            'order' => 1,
            'status' => 'active',
        ]);

        $second = intro::create([
            'image' => 'images/intros/b.png',
            'title' => $this->tr(['en' => 'Beta', 'ar' => 'بيتا']),
            'description' => $this->tr(['en' => 'y', 'ar' => 'ص']),
            'order' => 1,
            'status' => 'active',
        ]);

        $this->assertLessThan($second->id, $first->id);

        $titles = array_column($this->getJson('/api/v1/intros')->assertOk()->json('data'), 'title');
        $this->assertSame(['Alpha', 'Beta'], $titles);
    }

    #[Test]
    public function an_inactive_slide_never_reaches_onboarding(): void
    {
        intro::create([
            'image' => 'images/intros/a.png',
            'title' => $this->tr(['en' => 'Retired', 'ar' => 'متوقفة']),
            'description' => $this->tr(['en' => 'x', 'ar' => 'س']),
            'order' => 1,
            'status' => 'inactive',
        ]);

        $this->assertSame([], $this->getJson('/api/v1/intros')->assertOk()->json('data'));
    }

    // ------------------------------------------------------- app settings

    #[Test]
    public function app_settings_expose_the_contact_details_the_account_screen_needs(): void
    {
        $this->setting('App_Name', 'Laundo');
        $this->setting('Hotline', '19999');
        $this->setting('Whats_App', 'https://wa.me/201000000000');

        $data = $this->getJson('/api/v1/app-settings')->assertOk()->json('data');

        $this->assertSame('Laundo', $data['app_name']);
        $this->assertSame('19999', $data['hotline']);
        $this->assertSame('https://wa.me/201000000000', $data['whats_app']);
    }

    #[Test]
    public function app_settings_never_leak_operational_configuration(): void
    {
        // The reason the allow-list exists. `Tax` is a rate the server applies to
        // totals — a client that reads it is a client that can argue about the
        // maths — and `Country_Id` is an internal row id.
        $this->setting('Tax', '14');
        $this->setting('Country_Id', '1');
        $this->setting('Login_Cover', 'images/cover.png');

        $data = $this->getJson('/api/v1/app-settings')->assertOk()->json('data');

        $this->assertArrayNotHasKey('tax', $data);
        $this->assertArrayNotHasKey('country_id', $data);
        $this->assertArrayNotHasKey('login_cover', $data);

        // And nothing sneaks through under its original casing either.
        $this->assertArrayNotHasKey('Tax', $data);
    }

    #[Test]
    public function a_setting_that_was_never_filled_in_reads_as_null_rather_than_missing(): void
    {
        // An install with no WhatsApp number is normal. The key has to be present
        // so a client can test it, rather than absent so it crashes on lookup.
        $data = $this->getJson('/api/v1/app-settings')->assertOk()->json('data');

        $this->assertArrayHasKey('hotline', $data);
        $this->assertNull($data['hotline']);
    }

    #[Test]
    public function app_settings_do_not_ship_the_long_form_pages(): void
    {
        $this->setting('Terms', $this->tr(['en' => 'Long terms', 'ar' => 'شروط طويلة']));

        $data = $this->getJson('/api/v1/app-settings')->assertOk()->json('data');

        // Three walls of HTML on every launch, to populate a menu of three rows.
        $this->assertArrayNotHasKey('terms', $data);
        $this->assertSame(['about', 'privacy', 'terms'], $data['pages']);
    }

    // -------------------------------------------------------------- pages

    #[Test]
    public function a_page_returns_the_body_in_the_requested_language(): void
    {
        $this->setting('Terms', $this->tr(['en' => 'The English terms', 'ar' => 'الشروط بالعربية']));

        $this->assertSame(
            'الشروط بالعربية',
            $this->getJson('/api/v1/pages/terms', ['lang' => 'ar'])->assertOk()->json('data.body')
        );

        $this->assertSame(
            'The English terms',
            $this->getJson('/api/v1/pages/terms', ['lang' => 'en'])->assertOk()->json('data.body')
        );
    }

    #[Test]
    public function a_page_present_in_one_language_only_still_renders(): void
    {
        // A blank screen is worse than the wrong language: the customer cannot
        // tell whether the terms are missing or the app is broken.
        $this->setting('Privacy_Policy', $this->tr(['ar' => 'سياسة الخصوصية']));

        $this->assertSame(
            'سياسة الخصوصية',
            $this->getJson('/api/v1/pages/privacy', ['lang' => 'en'])->assertOk()->json('data.body')
        );
    }

    #[Test]
    public function a_page_written_before_the_field_was_translatable_still_renders(): void
    {
        $this->setting('About', 'A plain string, no JSON');

        $this->assertSame(
            'A plain string, no JSON',
            $this->getJson('/api/v1/pages/about')->assertOk()->json('data.body')
        );
    }

    #[Test]
    public function an_unset_page_returns_null_rather_than_an_error(): void
    {
        $this->getJson('/api/v1/pages/about')
            ->assertOk()
            ->assertJsonPath('data.body', null);
    }

    #[Test]
    public function an_unknown_page_is_not_found(): void
    {
        $this->getJson('/api/v1/pages/pricing')->assertNotFound();
    }

    #[Test]
    public function the_page_slug_is_not_case_sensitive(): void
    {
        $this->setting('Terms', $this->tr(['en' => 'T', 'ar' => 'ش']));

        $this->getJson('/api/v1/pages/TERMS')->assertOk()->assertJsonPath('data.slug', 'terms');
    }

    // ------------------------------------------------------------- offers

    /** @param array<string, mixed> $overrides */
    private function offer(array $overrides = []): Offer
    {
        return Offer::create(array_merge([
            'title' => $this->tr(['en' => 'Blanket wash bundle', 'ar' => 'باقة غسيل البطاطين']),
            'description' => $this->tr(['en' => 'Ready for winter', 'ar' => 'استعد للشتاء']),
            'status' => 'active',
            'sort_order' => 0,
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function coupon(array $overrides = []): Coupon
    {
        // `redemptions_count` is not fillable — it is a counter the redemption
        // path maintains, so `create()` drops it silently and an "exhausted"
        // coupon would quietly be a fresh one. Set after the fact.
        $redemptions = $overrides['redemptions_count'] ?? null;
        unset($overrides['redemptions_count']);

        $coupon = Coupon::create(array_merge([
            'code' => 'WINTER20',
            'name' => $this->tr(['en' => 'Winter', 'ar' => 'شتاء']),
            'type' => Coupon::PERCENTAGE,
            'value' => 20,
            'max_per_user' => 1,
            'status' => 'active',
        ], $overrides));

        if ($redemptions !== null) {
            $coupon->forceFill(['redemptions_count' => $redemptions])->save();
        }

        return $coupon;
    }

    #[Test]
    public function offers_return_only_the_live_ones(): void
    {
        $this->offer(['title' => $this->tr(['en' => 'Running'])]);
        $this->offer(['title' => $this->tr(['en' => 'Switched off']), 'status' => 'inactive']);
        $this->offer(['title' => $this->tr(['en' => 'Not yet']), 'starts_at' => now()->addDay()]);
        $this->offer(['title' => $this->tr(['en' => 'Over']), 'ends_at' => now()->subDay()]);

        $data = $this->getJson('/api/v1/offers')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('Running', $data[0]['title']);
    }

    #[Test]
    public function an_offer_with_no_window_runs_until_switched_off(): void
    {
        $this->offer();

        $this->getJson('/api/v1/offers')->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function the_badge_is_derived_from_the_coupon(): void
    {
        $this->offer(['coupon_id' => $this->coupon()->id]);

        // «20%», not «20.00%» — the decimal:2 cast reads back with the zeros.
        $this->assertSame('20%', $this->getJson('/api/v1/offers')->json('data.0.badge'));
    }

    #[Test]
    public function a_fixed_coupon_badges_as_money_not_a_percentage(): void
    {
        $this->offer(['coupon_id' => $this->coupon(['type' => Coupon::FIXED, 'value' => 15])->id]);

        $badge = $this->getJson('/api/v1/offers')->json('data.0.badge');

        $this->assertNotSame('15%', $badge);
        $this->assertStringContainsString('15', (string) $badge);
    }

    #[Test]
    public function an_offer_without_a_coupon_has_no_badge(): void
    {
        $this->offer();

        $this->assertNull($this->getJson('/api/v1/offers')->json('data.0.badge'));
    }

    /**
     * The case the derived badge exists to prevent: a card advertising a
     * discount the checkout would refuse. Each of these is a coupon
     * `CouponService::validate()` rejects, so none may show a badge — and the
     * offer itself still appears, because the card is not the discount.
     */
    #[Test]
    public function a_coupon_that_would_be_refused_shows_no_badge(): void
    {
        $cases = [
            'inactive' => ['status' => 'inactive'],
            'expired' => ['ends_at' => now()->subDay()],
            'not started' => ['starts_at' => now()->addDay()],
            'exhausted' => ['max_redemptions' => 5, 'redemptions_count' => 5],
        ];

        foreach ($cases as $label => $overrides) {
            Offer::query()->delete();
            Coupon::query()->delete();

            $this->offer(['coupon_id' => $this->coupon($overrides)->id]);

            $data = $this->getJson('/api/v1/offers')->assertOk()->json('data');

            $this->assertCount(1, $data, "the offer itself should still show: $label");
            $this->assertNull($data[0]['badge'], "a $label coupon must not be advertised");
        }
    }

    #[Test]
    public function sort_order_decides_the_sequence_and_id_breaks_a_tie(): void
    {
        $this->offer(['title' => $this->tr(['en' => 'Third']), 'sort_order' => 5]);
        $first = $this->offer(['title' => $this->tr(['en' => 'First']), 'sort_order' => 1]);
        $this->offer(['title' => $this->tr(['en' => 'Second']), 'sort_order' => 1]);

        $data = $this->getJson('/api/v1/offers')->assertOk()->json('data');

        // Two rows share `sort_order` 1, so the lower id comes first — without
        // the `id` tie-break their order would be whatever MySQL returned.
        $this->assertSame(['First', 'Second', 'Third'], collect($data)->pluck('title')->all());
        $this->assertSame($first->id, $data[0]['id']);
    }

    #[Test]
    public function the_action_is_null_until_there_is_somewhere_to_go(): void
    {
        $this->offer();
        $this->assertNull($this->getJson('/api/v1/offers')->json('data.0.action'));

        Offer::query()->delete();
        $this->offer(['target_type' => 'coupon', 'target_value' => 'WINTER20']);

        $this->assertSame(
            ['type' => 'coupon', 'value' => 'WINTER20'],
            $this->getJson('/api/v1/offers')->json('data.0.action')
        );
    }

    #[Test]
    public function the_offers_list_does_not_query_per_row(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->offer(['coupon_id' => $this->coupon(['code' => "CODE$i"])->id]);
        }

        DB::enableQueryLog();
        $this->getJson('/api/v1/offers')->assertOk()->assertJsonCount(5, 'data');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // The offers, their coupons eagerly, and the language lookups the locale
        // helpers make. Five offers must not mean five coupon queries.
        $this->assertLessThan(8, $queries, "the badge should not cost a query per offer; ran $queries");
    }

    #[Test]
    public function banners_follow_the_order_operations_set(): void
    {
        banner::create(['name' => $this->tr(['en' => 'B']), 'status' => 'active', 'sort_order' => 2]);
        banner::create(['name' => $this->tr(['en' => 'A']), 'status' => 'active', 'sort_order' => 1]);

        $names = collect($this->getJson('/api/v1/banners')->json('data'))->pluck('title')->all();

        // Was `latest('id')`, which put the newest first and left operations no
        // way to reorder the carousel.
        $this->assertSame(['A', 'B'], $names);
    }

    // ------------------------------------------------------- journey steps

    /** @param array<string, mixed> $overrides */
    private function step(array $overrides = []): JourneyStep
    {
        return JourneyStep::create(array_merge([
            'title' => $this->tr(['en' => 'Pick a time', 'ar' => 'حدد الموعد والعنوان']),
            'description' => $this->tr(['en' => 'Choose when we collect', 'ar' => 'اختر وقت ومكان الاستلام']),
            'status' => 'active',
            'sort_order' => 0,
        ], $overrides));
    }

    #[Test]
    public function journey_steps_return_only_the_active_ones(): void
    {
        $this->step(['title' => $this->tr(['en' => 'Shown'])]);
        $this->step(['title' => $this->tr(['en' => 'Hidden']), 'status' => 'inactive']);

        $data = $this->getJson('/api/v1/journey-steps')->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('Shown', $data[0]['title']);
    }

    #[Test]
    public function journey_steps_come_back_in_the_order_operations_set(): void
    {
        $this->step(['title' => $this->tr(['en' => 'Third']), 'sort_order' => 30]);
        $first = $this->step(['title' => $this->tr(['en' => 'First']), 'sort_order' => 10]);
        $this->step(['title' => $this->tr(['en' => 'Second']), 'sort_order' => 10]);

        $data = $this->getJson('/api/v1/journey-steps')->assertOk()->json('data');

        // Two share `sort_order` 10, so the lower id breaks the tie — the app
        // numbers the array it is given, and a sequence that reshuffles between
        // visits would renumber the cards.
        $this->assertSame(['First', 'Second', 'Third'], collect($data)->pluck('title')->all());
        $this->assertSame($first->id, $data[0]['id']);
    }

    /**
     * The «1 · 2 · 3» beside each card is the position, so it must not be in
     * the payload — sending it as a field is how a «3» arrives second.
     */
    #[Test]
    public function a_journey_step_does_not_carry_its_own_number(): void
    {
        $this->step();

        $keys = array_keys($this->getJson('/api/v1/journey-steps')->json('data.0'));

        $this->assertSame(['id', 'title', 'description', 'image'], $keys);
    }

    #[Test]
    public function a_journey_step_falls_back_to_a_language_that_has_copy(): void
    {
        // Arabic only, which is how the designed copy exists.
        $this->step(['title' => $this->tr(['ar' => 'حدد الموعد والعنوان'])]);

        $english = $this->getJson('/api/v1/journey-steps', ['lang' => 'en'])->json('data.0.title');

        $this->assertSame('حدد الموعد والعنوان', $english);
    }

    // ------------------------------------------------------------ currency

    /**
     * The apps format their own prices, so they need the same currency the
     * panel is using. Resolved rather than raw, for the reason the logo beside
     * it is: an unset or half-typed setting would hand the column straight
     * over, and the apps would render an empty currency while every screen in
     * the panel showed EGP.
     */
    #[Test]
    public function app_settings_carry_the_resolved_currency(): void
    {
        $this->setting('Currency', 'SAR');

        $this->assertSame('SAR', $this->getJson('/api/v1/app-settings')->assertOk()->json('data.currency'));
    }

    #[Test]
    public function an_unset_currency_reaches_the_apps_as_egp_not_empty(): void
    {
        Setting::where('key', 'Currency')->delete();
        Cache::flush();

        $this->assertSame('EGP', $this->getJson('/api/v1/app-settings')->assertOk()->json('data.currency'));
    }

    #[Test]
    public function a_malformed_currency_reaches_the_apps_as_egp(): void
    {
        // What a half-finished edit in the panel leaves behind.
        $this->setting('Currency', 'EG');

        $this->assertSame('EGP', $this->getJson('/api/v1/app-settings')->assertOk()->json('data.currency'));
    }

    // --------------------------------------------------------------- open

    #[Test]
    public function all_four_endpoints_are_reachable_without_a_token(): void
    {
        // Onboarding runs before an account exists and guest mode browses the home
        // screen. A token here would hide the content from its own audience.
        foreach ([
            '/api/v1/banners', '/api/v1/intros', '/api/v1/offers',
            '/api/v1/journey-steps', '/api/v1/app-settings', '/api/v1/pages/about',
        ] as $url) {
            $this->getJson($url)->assertOk();
        }
    }
}
