<?php

namespace Tests\Feature\Landing;

use App\Modules\Setting\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `App_Store_Url` and `Play_Store_Url`, end to end.
 *
 * `lessons.md` twice over: "validation is where features go to die quietly" —
 * `coupon_code` and `payment_method` were both correct in the service, correct
 * in the pricing and inert because the FormRequest never let the field through
 * — and "a column added without a way to set it is a column that is always
 * null". `landingCtaTarget()` reads these two, so the rule and the form field
 * are part of the change, not follow-up.
 */
class StoreLinkSettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seedCore();
    }

    #[Test]
    public function the_settings_form_offers_both_fields(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.generalSetting.viewGeneralSetting'))
            ->assertOk()
            ->assertSee('name="App_Store_Url"', false)
            ->assertSee('name="Play_Store_Url"', false);
    }

    #[Test]
    public function the_form_stores_what_it_is_given(): void
    {
        // The half that dies quietly: a rule missing here means the service is
        // correct and the feature is inert, with no error anywhere.
        $this->actingAs($this->superAdmin())
            ->put(route('admin.generalSetting.updateGeneralSetting'), [
                'App_Store_Url' => 'https://apps.apple.com/eg/app/laundo/id1',
                'Play_Store_Url' => 'https://play.google.com/store/apps/details?id=com.laundo',
            ])
            ->assertRedirect();

        Cache::flush();

        $this->assertSame(
            'https://apps.apple.com/eg/app/laundo/id1',
            Setting::where('key', 'App_Store_Url')->value('value')
        );
        $this->assertSame(
            'https://play.google.com/store/apps/details?id=com.laundo',
            Setting::where('key', 'Play_Store_Url')->value('value')
        );
    }

    #[Test]
    public function a_stored_link_reaches_the_landing_pages_button(): void
    {
        Setting::create(['key' => 'App_Store_Url', 'value' => 'https://apps.apple.com/eg/app/laundo/id1']);
        Cache::flush();

        $target = landingCtaTarget();

        $this->assertSame('store', $target['kind']);
        $this->assertSame('https://apps.apple.com/eg/app/laundo/id1', $target['href']);
    }

    #[Test]
    public function a_non_url_is_refused(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.generalSetting.updateGeneralSetting'), [
                'App_Store_Url' => 'not-a-url',
            ])
            ->assertSessionHasErrors('App_Store_Url');
    }
}
