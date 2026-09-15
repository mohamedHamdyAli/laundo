<?php

namespace Tests\Feature\Dashboard;

use App\Modules\City\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «سجّل مغسلتك» keeps what the applicant typed.
 *
 * This form is the worst case for a full-page validation bounce, worse than any
 * of the 41 dashboard forms the same script already serves: it holds a **file**
 * (the logo), **two passwords**, and a **map pin**, and `old()` can carry none
 * of them. One mistyped phone number handed the applicant back an empty logo
 * box, two empty password fields and an unplaced pin — so they retyped the
 * whole form, or gave up, which on a page whose entire job is signing up new
 * laundries is the expensive outcome.
 *
 * Nothing on the server changed. Laravel already answers a request that wants
 * JSON with `422 {message, errors}` instead of a redirect; the form is now
 * submitted in the background and the messages are painted where they belong.
 */
class LaundryRegisterValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->seedGeo();
    }

    #[Test]
    public function the_form_is_wired_to_the_background_submit(): void
    {
        $html = $this->get(route('laundry.register'))->assertOk()->getContent();

        // The hook and the script. Either one alone is a form that posts the
        // old way while looking like it should not.
        $this->assertStringContainsString('needs-validation', $html);
        $this->assertStringContainsString('js/custom/form-validation.js', $html);
    }

    #[Test]
    public function the_script_is_fingerprinted_by_mtime(): void
    {
        // `assetVersion()` takes a path relative to `assets/`. Given one in
        // `asset()`'s shape it silently returns `app()->version()` — a constant
        // — and the file is never busted again. That has already shipped once.
        $html = $this->get(route('laundry.register'))->getContent();

        preg_match('/form-validation\.js\?v=([^"]+)/', $html, $m);

        $this->assertMatchesRegularExpression('/^\d{9,}$/', $m[1] ?? '',
            'form-validation.js is stamped with «'.($m[1] ?? 'nothing').'» rather than an mtime');
    }

    #[Test]
    public function a_failed_validation_answers_json_rather_than_a_redirect(): void
    {
        // What the script relies on. No controller was changed to make this
        // work and none should need to be.
        $this->postJson(route('laundry.register.store'), [])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors']);
    }

    #[Test]
    public function the_errors_are_keyed_the_way_the_script_looks_them_up(): void
    {
        // `findField()` turns `owner_email` into `[name="owner_email"]` and
        // `name.en` into `[name="name[en]"]`. A key it cannot map falls through
        // to the banner, which is the behaviour this form had for everything.
        $errors = $this->postJson(route('laundry.register.store'), [])
            ->assertStatus(422)
            ->json('errors');

        foreach (['phone', 'city_id', 'owner_name', 'owner_email', 'owner_phone', 'owner_password'] as $field) {
            $this->assertArrayHasKey($field, $errors);
        }
    }

    #[Test]
    public function the_translatable_name_reports_under_its_bare_key(): void
    {
        // «a name in at least one language» can only fail as `name`, never as
        // `name.en` — no single language is at fault. The script matches that by
        // prefix so it still lands beside the English box rather than in the
        // banner.
        $errors = $this->postJson(route('laundry.register.store'), [])->json('errors');

        $this->assertArrayHasKey('name', $errors);
    }

    #[Test]
    public function a_valid_application_still_goes_through(): void
    {
        // The background submit must not change what a good form does.
        $city = City::withoutGlobalScopes()->first();

        $this->postJson(route('laundry.register.store'), [
            'name' => ['en' => 'Sparkle', 'ar' => 'سباركل'],
            'phone' => '+201012345699',
            'city_id' => $city->id,
            'owner_name' => 'Rania',
            'owner_email' => 'rania@example.test',
            'owner_phone' => '+201012345698',
            'owner_password' => 'secret123',
            'owner_password_confirmation' => 'secret123',
            // Required, and easy to forget: delivery is priced by distance from
            // this pin, so a laundry without one cannot be given an order even
            // once it is approved.
            'lat' => 30.0444,
            'lng' => 31.2357,
            'accepts_terms' => 1,
            // A 302 to «تم التقديم», not a 200: success is still a redirect and
            // the script follows it, which is the half of the behaviour that
            // must not change. Only the 422 is handled differently now.
        ])->assertRedirect(route('laundry.applied'));

        $this->assertDatabaseHas('users', ['email' => 'rania@example.test']);
    }
}
