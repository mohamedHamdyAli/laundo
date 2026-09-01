<?php

namespace Tests\Feature\Api;

use App\Modules\Banner\Models\banner;
use App\Modules\Intro\Models\intro;
use App\Modules\Setting\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    // --------------------------------------------------------------- open

    #[Test]
    public function all_four_endpoints_are_reachable_without_a_token(): void
    {
        // Onboarding runs before an account exists and guest mode browses the home
        // screen. A token here would hide the content from its own audience.
        foreach (['/api/v1/banners', '/api/v1/intros', '/api/v1/app-settings', '/api/v1/pages/about'] as $url) {
            $this->getJson($url)->assertOk();
        }
    }
}
