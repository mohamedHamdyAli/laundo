<?php

namespace Tests\Feature\Api;

use App\Services\languages\AppStrings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `GET /translations/app?app=user|driver` — each app's own words.
 *
 * The two apps bundle different files with different keys, so the caller says
 * which it is, and gets that file nested, as it bundles it. A key the language
 * has not translated comes back in English rather than as a hole.
 *
 * Reads the real shipped files (the app teams'); what an operator typed goes
 * to a directory of this test's own, so nothing here changes what a running
 * app is served.
 */
class AppStringsTest extends TestCase
{
    use RefreshDatabase;

    private string $store;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seedCore();

        $this->store = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laundo-app-strings-'.uniqid();
        config(['app.app_strings_overrides_path' => $this->store]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->store);

        parent::tearDown();
    }

    /** @param array<string, string> $strings dotted key => words */
    private function override(string $app, string $code, array $strings): void
    {
        File::ensureDirectoryExists($this->store);
        File::put($this->store.DIRECTORY_SEPARATOR."{$code}_app_{$app}.json", json_encode($strings, JSON_UNESCAPED_UNICODE));
    }

    // ---------------------------------------------------------------------

    #[Test]
    public function each_app_gets_its_own_words(): void
    {
        $driver = $this->getJson('/api/v1/translations/app?app=driver&code=ar')
            ->assertOk()
            ->assertJsonPath('data.app', 'driver')
            ->assertJsonPath('data.code', 'ar')
            ->json('data.strings');

        $this->assertSame('تسجيل دخول السائق', $driver['login']['title']);
        $this->assertArrayNotHasKey('auth', $driver);

        $user = $this->getJson('/api/v1/translations/app?app=user&code=ar')
            ->assertOk()
            ->assertJsonPath('data.app', 'user')
            ->json('data.strings');

        $this->assertSame('تسجيل الدخول', $user['auth']['login']);
        $this->assertArrayNotHasKey('leg_state', $user);
    }

    #[Test]
    public function the_words_come_nested_as_the_app_bundles_them(): void
    {
        $strings = $this->getJson('/api/v1/translations/app?app=driver&code=ar')->assertOk()->json('data.strings');

        // The plural map is an object, with Arabic's own forms in it.
        $this->assertEqualsCanonicalizing(['zero', 'one', 'two', 'few', 'many', 'other'], array_keys($strings['tasks']['count']));
        $this->assertSame('{} مهام', $strings['tasks']['count']['few']);
        // A newline in a message survives the trip.
        $this->assertStringContainsString("\n", $strings['details']['collected_confirm_message']);
    }

    /** @return array<string, array{0: string}> */
    public static function notAnApp(): array
    {
        return [
            'missing' => [''],
            'unknown' => ['&app=vendor'],
            'the wrong case' => ['&app=Driver'],
            'a list' => ['&app[]=user'],
        ];
    }

    #[Test]
    #[DataProvider('notAnApp')]
    public function the_caller_must_say_which_app_it_is(string $query): void
    {
        $this->getJson('/api/v1/translations/app?code=ar'.$query)
            ->assertStatus(422)
            ->assertJsonPath('errors.app.0', __('Say which app is asking: app=user or app=driver.'));
    }

    #[Test]
    public function a_key_the_language_lacks_comes_back_in_english(): void
    {
        // A language with no file of its own at all.
        $strings = $this->getJson('/api/v1/translations/app?app=user&code=fr')->assertOk()->json('data.strings');
        $this->assertSame(AppStrings::shipped('user', 'en'), $strings);

        // And one that has translated a single key.
        $this->override('user', 'fr', ['auth.login' => 'Connexion']);

        $strings = $this->getJson('/api/v1/translations/app?app=user&code=fr')->assertOk()->json('data.strings');
        $this->assertSame('Connexion', $strings['auth']['login']);
        $this->assertSame('Create account', $strings['auth']['register']);
    }

    #[Test]
    public function every_key_english_has_reaches_arabic_too(): void
    {
        foreach (AppStrings::APPS as $app) {
            $strings = $this->getJson("/api/v1/translations/app?app={$app}&code=ar")->assertOk()->json('data.strings');

            $this->assertSame(
                [],
                array_values(array_diff(array_keys(Arr::dot(AppStrings::shipped($app, 'en'))), array_keys(Arr::dot($strings)))),
                "{$app}: keys English has and Arabic is served without",
            );
        }
    }

    #[Test]
    public function an_operators_words_are_what_the_app_is_served(): void
    {
        $this->override('driver', 'ar', ['login.title' => 'دخول المندوب']);
        $this->override('driver', 'en', ['login.title' => 'Courier sign in', 'nav.home' => 'Start']);

        $this->getJson('/api/v1/translations/app?app=driver&code=ar')
            ->assertOk()
            ->assertJsonPath('data.strings.login.title', 'دخول المندوب')
            // English's edit does not reach a language that has its own words.
            ->assertJsonPath('data.strings.nav.home', 'الرئيسية');

        $this->getJson('/api/v1/translations/app?app=driver&code=en')
            ->assertOk()
            ->assertJsonPath('data.strings.login.title', 'Courier sign in');

        // …but it does reach one that has none.
        $this->getJson('/api/v1/translations/app?app=driver&code=fr')
            ->assertOk()
            ->assertJsonPath('data.strings.nav.home', 'Start');
    }

    #[Test]
    public function the_language_defaults_to_the_lang_header(): void
    {
        $this->getJson('/api/v1/translations/app?app=driver', $this->apiHeaders('ar'))
            ->assertOk()
            ->assertJsonPath('data.code', 'ar')
            ->assertJsonPath('data.strings.nav.home', 'الرئيسية');
    }

    #[Test]
    public function a_code_that_is_not_a_language_code_reads_no_file(): void
    {
        // The code becomes a file name; one that walks out of the directory is
        // not a language, and gets the English set rather than anything else.
        foreach (['../../.env', '..\\..\\.env', 'ar/../en', 'ar%00'] as $code) {
            $this->getJson('/api/v1/translations/app?app=user&code='.urlencode($code))
                ->assertOk()
                ->assertJsonPath('data.strings.auth.login', 'Sign in');
        }

        $this->assertNull(AppStrings::shippedPath('user', '../../.env'));
        $this->assertNull(AppStrings::overridesPath('user', '../../.env'));
    }

    #[Test]
    public function it_is_public_because_an_app_needs_its_words_before_signing_in(): void
    {
        $this->getJson('/api/v1/translations/app?app=user')->assertOk();
        $this->getJson('/api/v1/translations/app?app=driver')->assertOk();
    }

    #[Test]
    public function the_shipped_arabic_brings_no_placeholder_the_app_will_not_fill(): void
    {
        // The app teams' own files, checked by the rule the screen saves under —
        // so a file the screen would refuse is not shipped either.
        $problems = [];

        foreach (AppStrings::APPS as $app) {
            $english = Arr::dot(AppStrings::shipped($app, 'en'));

            foreach (Arr::dot(AppStrings::shipped($app, 'ar')) as $key => $value) {
                $unknown = AppStrings::unknownPlaceholders((string) ($english[$key] ?? $value), (string) $value);

                if ($unknown !== []) {
                    $problems[] = "{$app}: {$key} — ".implode(' ', $unknown);
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }
}
