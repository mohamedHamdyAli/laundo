<?php

namespace Tests\Feature\Dashboard;

use App\Models\Language;
use App\Services\CachingService;
use App\Services\languages\LanguageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «خليت العربي ديفولت والداش بورد متغيرتش».
 *
 * Marking a language default wrote the row correctly and changed nothing about
 * the panel, because **nothing consulted the row**. Four places decided the
 * panel's language independently and every one of them hardcoded English:
 * `SetLocale` did nothing without a session, `layouts/main` and `auth/login`
 * both wrote `?? 'en'`, `layouts/include` decided right-to-left from
 * `code == 'ar'`, and the topbar's own `CachingService::getDefaultLanguage()`
 * was literally `where('code', 'en')`.
 *
 * Two more defects sat behind that one: nothing demoted the previous default,
 * so a second `'true'` row was possible and `where(...)->first()` picked by id;
 * and nothing stopped the last default being removed, which throws out of
 * `getDefaultLanguage()` on every request.
 */
class PanelLanguageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    private function service(): LanguageService
    {
        return app(LanguageService::class);
    }

    /** Make a language the default the way the edit form does. */
    private function makeDefault(string $code): Language
    {
        $language = Language::where('code', $code)->firstOrFail();

        $this->service()->updateRecord([
            'id' => $language->id,
            'code' => $language->code,
            'name' => $language->name,
            'is_rtl' => $language->is_rtl,
            'default' => 'true',
        ]);

        Cache::flush();

        return $language->refresh();
    }

    #[Test]
    public function with_no_session_the_panel_speaks_the_default_language(): void
    {
        // The whole bug in one assertion: `SetLocale` used to leave the locale
        // at `config('app.locale')` when the session was empty.
        $this->assertSame('en', panelLanguageCode());

        $this->makeDefault('ar');

        $this->assertSame('ar', panelLanguageCode());
    }

    #[Test]
    public function making_a_language_default_demotes_every_other_one(): void
    {
        $this->makeDefault('ar');

        $this->assertSame(1, Language::where('default', 'true')->count());
        $this->assertSame('ar', Language::where('default', 'true')->value('code'));
        $this->assertSame('false', Language::where('code', 'en')->value('default'));
    }

    #[Test]
    public function two_languages_can_never_both_be_default(): void
    {
        // Flip back and forth: each promotion has to demote the last one, or
        // `getDefaultLanguage()`'s `first()` starts deciding by id order.
        foreach (['ar', 'en', 'ar', 'en'] as $code) {
            $this->makeDefault($code);

            $this->assertSame(
                1,
                Language::where('default', 'true')->count(),
                "promoting {$code} left more than one default"
            );
            $this->assertSame($code, getDefaultLanguage('code'));
        }
    }

    #[Test]
    public function right_to_left_comes_from_the_column_not_from_the_code(): void
    {
        $this->assertFalse(panelIsRtl());

        $this->makeDefault('ar');
        $this->assertTrue(panelIsRtl());

        // The real point: a language that is not Arabic but is right-to-left.
        // `code == 'ar'` rendered Hebrew and Urdu left-to-right.
        Language::create([
            'name' => 'עברית', 'name_en' => 'Hebrew', 'code' => 'he',
            'country_code' => 'IL', 'default' => 'false', 'is_rtl' => 'true',
            'app_scope' => 'user',
        ]);

        $this->makeDefault('he');

        $this->assertSame('he', panelLanguageCode());
        $this->assertTrue(panelIsRtl(), 'a non-Arabic RTL language must still lay out RTL');
    }

    #[Test]
    public function a_language_chosen_from_the_topbar_outranks_the_default(): void
    {
        $this->makeDefault('ar');

        // Somebody who picked a language has chosen; an owner changing the
        // platform default must not overrule them mid-session.
        session(['language' => Language::where('code', 'en')->first()]);

        $this->assertSame('en', panelLanguageCode());
        $this->assertFalse(panelIsRtl());
    }

    #[Test]
    public function a_session_holding_a_language_that_no_longer_exists_falls_back(): void
    {
        // A session can be days old and carry a row that has since been
        // deleted, or a stale `is_rtl`. It is re-read by code, not trusted.
        $ghost = Language::create([
            'name' => 'Gone', 'name_en' => 'Gone', 'code' => 'zz',
            'country_code' => 'ZZ', 'default' => 'false', 'is_rtl' => 'true',
            'app_scope' => 'user',
        ]);

        session(['language' => $ghost]);
        $ghost->delete();
        Cache::flush();

        $this->assertSame('en', panelLanguageCode(), 'a deleted language must not strand the panel');
        $this->assertFalse(panelIsRtl());
    }

    #[Test]
    public function the_last_default_language_cannot_be_removed(): void
    {
        $english = Language::where('code', 'en')->firstOrFail();

        // Zero defaults makes `getDefaultLanguage()` throw, and the locale
        // helpers call it on every request — so this does not degrade the
        // panel, it takes it down.
        try {
            $this->service()->updateRecord([
                'id' => $english->id,
                'code' => $english->code,
                'name' => $english->name,
                'is_rtl' => $english->is_rtl,
                'default' => 'false',
            ]);

            $this->fail('un-defaulting the only default language was allowed');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('default', $e->errors());
        }

        $this->assertSame('true', $english->refresh()->default);
    }

    #[Test]
    public function un_defaulting_is_fine_once_another_language_holds_it(): void
    {
        // The guard must not become a cage: demotion is legal, it just cannot
        // leave the platform with none.
        $this->makeDefault('ar');

        $this->assertSame('false', Language::where('code', 'en')->value('default'));
        $this->assertSame('ar', getDefaultLanguage('code'));
    }

    #[Test]
    public function the_topbar_reads_the_flagged_language_not_the_one_called_en(): void
    {
        // Was `Language::where('code', 'en')->first()`, so the switcher showed
        // EN whatever the default was.
        $this->assertSame('en', CachingService::getDefaultLanguage()->code);

        $this->makeDefault('ar');

        $this->assertSame('ar', CachingService::getDefaultLanguage()->code);
    }

    #[Test]
    public function editing_a_language_drops_the_topbar_cache_too(): void
    {
        // Two caches exist and `clearLanguageCache()` only ever cleared one, so
        // the switcher kept the old list and old flags for up to an hour.
        CachingService::getLanguages();
        $this->assertTrue(Cache::has(config('constants.CACHE.LANGUAGE')));

        $arabic = Language::where('code', 'ar')->firstOrFail();
        $this->service()->updateRecord([
            'id' => $arabic->id,
            'code' => $arabic->code,
            'name' => 'العربية المحدثة',
            'is_rtl' => 'true',
            'default' => 'false',
        ]);

        $this->assertFalse(
            Cache::has(config('constants.CACHE.LANGUAGE')),
            'the topbar cache survived a language edit'
        );
    }

    #[Test]
    public function the_panel_renders_right_to_left_when_the_default_is(): void
    {
        $this->makeDefault('ar');

        $response = $this->actingAs($this->superAdmin())->get(route('home'));

        $response->assertOk()
            // `<html lang dir>` and the RTL stylesheet both follow the row now.
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee('css/main/rtl.css', false);
    }

    #[Test]
    public function the_panel_renders_left_to_right_by_default(): void
    {
        $response = $this->actingAs($this->superAdmin())->get(route('home'));

        $response->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('dir="ltr"', false)
            ->assertSee('css/main/app.css', false);
    }
}
