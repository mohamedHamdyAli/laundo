<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Laundry\Models\Laundry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dashboard data columns follow the panel language.
 *
 * The convention from the first phase was the opposite: `getLocalizedValueDashboard`
 * always rendered the *default* language, so an Arabic panel flipped its layout and
 * kept English record names. The owner reversed that.
 *
 * What makes the reversal safe is the fallback chain, and that is what most of
 * these tests are about. A laundry whose Arabic name was never filled in must not
 * read "No Data Found" in an Arabic panel — that row would be unsearchable,
 * unreadable, and look exactly like data loss.
 */
class LocalisedColumnsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    /** @param array<string, string> $names */
    private function laundry(array $names): Laundry
    {
        return Laundry::withoutGlobalScopes()->create([
            'name' => json_encode($names, JSON_UNESCAPED_UNICODE),
            'phone' => '0101'.random_int(1000000, 9999999),
            'email' => uniqid('l').'@test.local',
            'status' => 'active',
        ]);
    }

    #[Test]
    public function an_arabic_panel_shows_the_arabic_value(): void
    {
        $laundry = $this->laundry(['en' => 'Bright Wash', 'ar' => 'الغسيل اللامع']);

        app()->setLocale('ar');

        $this->assertSame('الغسيل اللامع', getLocalizedValueDashboard($laundry, 'name'));
    }

    #[Test]
    public function an_english_panel_shows_the_english_value(): void
    {
        $laundry = $this->laundry(['en' => 'Bright Wash', 'ar' => 'الغسيل اللامع']);

        app()->setLocale('en');

        $this->assertSame('Bright Wash', getLocalizedValueDashboard($laundry, 'name'));
    }

    #[Test]
    public function a_value_missing_in_the_panel_language_falls_back_to_the_default(): void
    {
        // English is the default in the harness.
        $laundry = $this->laundry(['en' => 'Bright Wash', 'ar' => '']);

        app()->setLocale('ar');

        // Not "No Data Found". A row with no readable name is worse than a row in
        // the wrong language.
        $this->assertSame('Bright Wash', getLocalizedValueDashboard($laundry, 'name'));
    }

    #[Test]
    public function a_missing_key_altogether_falls_back(): void
    {
        $laundry = $this->laundry(['en' => 'Bright Wash']);

        app()->setLocale('ar');

        $this->assertSame('Bright Wash', getLocalizedValueDashboard($laundry, 'name'));
    }

    #[Test]
    public function a_value_present_only_in_a_third_language_is_still_shown(): void
    {
        // Neither the panel language nor the default. Anything rather than nothing:
        // the operator can still read the row and search for it.
        $laundry = $this->laundry(['fr' => 'Lavage Brillant']);

        app()->setLocale('ar');

        $this->assertSame('Lavage Brillant', getLocalizedValueDashboard($laundry, 'name'));
    }

    #[Test]
    public function a_genuinely_empty_value_says_so(): void
    {
        $laundry = $this->laundry(['en' => '', 'ar' => '']);

        app()->setLocale('ar');

        // Only when there is truly nothing to show.
        $this->assertSame('No Data Found', getLocalizedValueDashboard($laundry, 'name'));
    }

    #[Test]
    public function the_api_helper_shares_the_same_fallback_chain(): void
    {
        // It used to end in a hardcoded `?? ->ar`, which made Arabic the last
        // resort even on an install whose default language is English.
        $laundry = $this->laundry(['en' => 'Bright Wash']);

        app()->setLocale('ar');

        $this->assertSame('Bright Wash', getLocalizedValue($laundry, 'name'));
    }

    #[Test]
    public function the_laundry_list_renders_arabic_when_the_panel_is_arabic(): void
    {
        $this->laundry(['en' => 'Bright Wash', 'ar' => 'الغسيل اللامع']);

        app()->setLocale('ar');

        $this->actingAs($this->superAdmin())
            ->get('/admin/laundry')
            ->assertOk()
            ->assertSee('الغسيل اللامع', false);
    }
}
