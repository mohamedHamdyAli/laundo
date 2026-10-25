<?php

namespace Tests\Feature\Dashboard;

use App\Models\ActivityLog;
use App\Models\Language;
use App\Models\Role;
use App\Modules\User\Models\User;
use App\Services\languages\AppStrings;
use App\Services\languages\LanguageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «نصوص تطبيق العميل / المندوب» — each app's words, edited per language.
 *
 * The test that matters is the first one: words saved on the screen are the
 * words the app is served. A screen that saves a file the endpoint does not
 * read would pass every other test here.
 *
 * The store is pointed at a directory of this test's own, and the shipped
 * files are checked untouched, so nothing here changes what a running app is
 * served.
 */
class AppStringsEditorTest extends TestCase
{
    use RefreshDatabase;

    private Language $arabic;

    private string $store;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seedCore();

        $this->store = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laundo-app-strings-'.uniqid();
        config(['app.app_strings_overrides_path' => $this->store]);

        $this->arabic = Language::where('code', 'ar')->firstOrFail();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->store);

        parent::tearDown();
    }

    private function save(array $strings, string $app = 'driver', ?Language $language = null): TestResponse
    {
        return $this->actingAs($this->superAdmin())->post(
            route('admin.language.app.update', [($language ?? $this->arabic)->id, $app]),
            ['strings' => $strings, 'complete' => '1'],
        );
    }

    private function served(string $app = 'driver', string $code = 'ar'): array
    {
        return $this->getJson("/api/v1/translations/app?app={$app}&code={$code}")->assertOk()->json('data.strings');
    }

    private function storeFile(string $app = 'driver', string $code = 'ar'): string
    {
        return $this->store.DIRECTORY_SEPARATOR."{$code}_app_{$app}.json";
    }

    private function panelUserWith(array $permissions, string $phone): User
    {
        $this->grant('admin', $permissions);

        return User::create([
            'name' => 'Operator', 'phone' => $phone, 'password' => 'password', 'status' => 'active',
            'role_id' => Role::where('slug', 'admin')->value('id'), 'phone_verified_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------------

    #[Test]
    public function saved_words_are_what_the_app_is_served(): void
    {
        $this->save(['login.title' => 'دخول المندوب', 'tasks.count.few' => '{} مهمات'])
            ->assertRedirect(route('admin.language.app', [$this->arabic->id, 'driver']))
            ->assertSessionHasNoErrors();

        $strings = $this->served();

        $this->assertSame('دخول المندوب', $strings['login']['title']);
        $this->assertSame('{} مهمات', $strings['tasks']['count']['few']);
        // Only the app it was saved for.
        $this->assertSame('تسجيل الدخول', $this->served('user')['auth']['login']);
    }

    #[Test]
    public function the_shipped_files_are_never_written(): void
    {
        $shipped = [];
        foreach (glob(lang_path('*_app_*.json')) as $file) {
            $shipped[$file] = md5_file($file);
        }

        $this->save(['login.title' => 'دخول المندوب']);
        $this->save(['auth.login' => 'Log in'], 'user', Language::where('code', 'en')->firstOrFail());

        foreach ($shipped as $file => $hash) {
            $this->assertSame($hash, md5_file($file), basename($file).' was written');
        }

        $this->assertFileExists($this->storeFile());
        $this->assertFileExists($this->storeFile('user', 'en'));

        $config = require base_path('config/app.php');
        $this->assertStringStartsWith(storage_path(), $config['app_strings_overrides_path']);
    }

    #[Test]
    public function the_screen_lists_every_key_with_the_shipped_words_as_placeholders(): void
    {
        $this->save(['login.title' => 'دخول المندوب']);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.language.app', [$this->arabic->id, 'driver']))
            ->assertOk()
            ->assertSee('name="strings[login.title]"', false)
            ->assertSee('value="دخول المندوب"', false)
            ->assertSee('placeholder="تسجيل دخول السائق"', false)
            // Arabic's own plural forms are editable too.
            ->assertSee('name="strings[tasks.count.two]"', false)
            // A message written across lines gets a box that keeps the line.
            ->assertSee('<textarea id="a-details-collected_confirm_message"', false)
            // The customer app's keys are not on the driver app's screen.
            ->assertDontSee('strings[auth.login]', false);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.language.app', [$this->arabic->id, 'user']))
            ->assertOk()
            ->assertSee('name="strings[auth.login]"', false);
    }

    #[Test]
    public function an_emptied_box_puts_the_shipped_words_back(): void
    {
        $this->save(['login.title' => 'دخول المندوب', 'nav.home' => 'البيت']);
        $this->save(['login.title' => '']);

        $strings = $this->served();
        $this->assertSame('تسجيل دخول السائق', $strings['login']['title']);
        $this->assertSame('البيت', $strings['nav']['home']);

        $this->save(['nav.home' => '']);
        $this->assertFileDoesNotExist($this->storeFile());
    }

    #[Test]
    public function the_shipped_words_typed_again_are_not_stored(): void
    {
        $this->save(['login.title' => 'تسجيل دخول السائق']);

        $this->assertFileDoesNotExist($this->storeFile());
    }

    #[Test]
    public function a_key_the_form_did_not_send_keeps_its_words(): void
    {
        // PHP stops reading a form at max_input_vars; a form cut short must
        // not read as «reset everything after this».
        $this->save(['login.title' => 'دخول المندوب']);
        $this->save(['nav.home' => 'البيت']);

        $this->assertSame(['login.title' => 'دخول المندوب', 'nav.home' => 'البيت'], AppStrings::overrides('driver', 'ar'));
    }

    #[Test]
    public function a_placeholder_the_app_will_not_fill_refuses_the_whole_save(): void
    {
        $this->save([
            'login.title' => 'دخول المندوب',
            'home.greeting' => 'أهلاً {name} 👋',
        ])->assertSessionHasErrors('strings[home.greeting]');

        // All or nothing: the good row was not saved either.
        $this->assertFileDoesNotExist($this->storeFile());

        // An extra positional one is refused the same way.
        $this->save(['order.building' => 'مبنى {} دور {}'])->assertSessionHasErrors('strings[order.building]');

        // Fewer is fine — Arabic drops the number from a dual.
        $this->save(['forgot.code_digit' => 'رقم {}'])->assertSessionHasNoErrors();
        $this->assertSame('رقم {}', $this->served()['forgot']['code_digit']);

        // A named one English uses is kept.
        $this->save(['address.savedInZone' => 'اتحفظ في {zone}'], 'user')->assertSessionHasNoErrors();
    }

    #[Test]
    public function only_keys_the_app_has_are_saved(): void
    {
        $this->save([
            'login.title' => 'دخول المندوب',
            'login.invented' => 'مفتاح من عندي',
            'auth.login' => 'مفتاح تطبيق العميل',
            'nav' => ['home' => 'مصفوفة'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['login.title' => 'دخول المندوب'], AppStrings::overrides('driver', 'ar'));
    }

    #[Test]
    public function a_line_break_is_kept_as_one(): void
    {
        $this->save(['details.collected_confirm_message' => "استلمت {}؟\r\nالمطلوب: {}"]);

        $this->assertSame("استلمت {}؟\nالمطلوب: {}", $this->served()['details']['collected_confirm_message']);
    }

    #[Test]
    public function a_language_without_a_file_of_its_own_is_edited_over_english(): void
    {
        $french = Language::create([
            'name' => 'Français', 'name_en' => 'French', 'code' => 'fr',
            'country_code' => 'FR', 'default' => 'false', 'is_rtl' => 'false', 'app_scope' => 'user',
        ]);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.language.app', [$french->id, 'user']))
            ->assertOk()
            ->assertSee('placeholder="Sign in"', false);

        $this->save(['auth.login' => 'Connexion'], 'user', $french)->assertSessionHasNoErrors();
        $this->assertSame('Connexion', $this->served('user', 'fr')['auth']['login']);
        $this->assertFileDoesNotExist(lang_path('fr_app_user.json'));

        // Deleting the language takes what was typed for it with it.
        app(LanguageService::class)->deleteRecord($french->id);
        $this->assertFileDoesNotExist($this->storeFile('user', 'fr'));
        $this->assertFileExists(lang_path('en_app_user.json'));
    }

    #[Test]
    public function the_download_is_the_file_the_app_is_served(): void
    {
        $this->save(['login.title' => 'دخول المندوب']);

        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.language.app.download', [$this->arabic->id, 'driver']))
            ->assertOk()
            ->assertDownload('ar_app_driver.json');

        $this->assertSame($this->served(), json_decode($response->streamedContent(), true));
    }

    #[Test]
    public function a_change_is_in_the_activity_log_as_the_words_before_and_after(): void
    {
        $this->save(['login.title' => 'دخول المندوب']);
        $this->save(['login.title' => '']);

        $rows = ActivityLog::where('subject_type', $this->arabic->getMorphClass())
            ->where('subject_id', $this->arabic->id)
            ->where('event', 'updated')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame(['old' => 'تسجيل دخول السائق', 'new' => 'دخول المندوب'], $rows[0]->diff['app_driver.login.title']);
        // A reset reads as the words it went back to.
        $this->assertSame('تسجيل دخول السائق', $rows[1]->diff['app_driver.login.title']['new']);

        app()->setLocale('ar');
        $this->assertSame(__('Driver app texts').' — login.title', ActivityLog::fieldLabel('app_driver.login.title'));
    }

    #[Test]
    public function editing_needs_language_update(): void
    {
        $viewer = $this->panelUserWith(['language.view'], '+201000000077');

        $this->actingAs($viewer)->get(route('admin.language.app', [$this->arabic->id, 'driver']))->assertForbidden();
        $this->actingAs($viewer)
            ->post(route('admin.language.app.update', [$this->arabic->id, 'driver']), ['strings' => ['login.title' => 'x']])
            ->assertForbidden();
        $this->assertFileDoesNotExist($this->storeFile());

        // Reading it out is a view.
        $this->actingAs($viewer)->get(route('admin.language.app.download', [$this->arabic->id, 'driver']))->assertOk();

        $editor = $this->panelUserWith(['language.view', 'language.update'], '+201000000078');
        $this->actingAs($editor)->get(route('admin.language.app', [$this->arabic->id, 'driver']))->assertOk();
    }

    #[Test]
    public function an_app_nobody_ships_has_no_screen(): void
    {
        $this->actingAs($this->superAdmin())->get("/admin/language/app/{$this->arabic->id}/vendor")->assertNotFound();
        $this->actingAs($this->superAdmin())->post("/admin/language/app/update/{$this->arabic->id}/vendor")->assertNotFound();
    }

    #[Test]
    public function a_form_cut_short_is_refused_rather_than_half_saved(): void
    {
        // What PHP hands over when a form is longer than max_input_vars: the
        // boxes up to the cut, and not the field that closes the form.
        $this->actingAs($this->superAdmin())
            ->post(route('admin.language.app.update', [$this->arabic->id, 'driver']), ['strings' => ['login.title' => 'دخول المندوب']])
            ->assertSessionHasErrors('strings');

        $this->assertFileDoesNotExist($this->storeFile());
    }

    #[Test]
    public function a_refusal_is_listed_on_the_page_a_plain_post_returns_to(): void
    {
        $this->actingAs($this->superAdmin())
            ->from(route('admin.language.app', [$this->arabic->id, 'driver']))
            ->followingRedirects()
            ->post(route('admin.language.app.update', [$this->arabic->id, 'driver']), [
                'strings' => ['home.greeting' => 'أهلاً {name}'], 'complete' => '1',
            ])
            ->assertOk()
            ->assertSee('alert-danger', false)
            ->assertSee('{name}');
    }

    #[Test]
    public function a_language_whose_code_changes_keeps_its_words(): void
    {
        $french = Language::create([
            'name' => 'Français', 'name_en' => 'French', 'code' => 'fr',
            'country_code' => 'FR', 'default' => 'false', 'is_rtl' => 'false', 'app_scope' => 'user',
        ]);
        $this->save(['auth.login' => 'Connexion'], 'user', $french);

        $this->actingAs($this->superAdmin())->put(route('admin.language.update', $french->id), [
            'name' => 'Français', 'name_en' => 'French', 'code' => 'fr-ca',
            'country_code' => 'CA', 'is_rtl' => 'false', 'default' => 'false',
        ])->assertSessionHasNoErrors();

        $this->assertFileDoesNotExist($this->storeFile('user', 'fr'));
        $this->assertSame('Connexion', $this->served('user', 'fr-ca')['auth']['login']);
        // And the old code does not keep answering with them.
        $this->assertSame('Sign in', $this->served('user', 'fr')['auth']['login']);
    }

    #[Test]
    public function a_regional_code_is_served_its_base_language(): void
    {
        $this->save(['nav.home' => 'البيت']);

        $strings = $this->served('driver', 'ar-EG');

        $this->assertSame('تسجيل دخول السائق', $strings['login']['title']);
        $this->assertSame('البيت', $strings['nav']['home']);
    }

    #[Test]
    public function the_default_shown_is_what_the_app_would_be_served(): void
    {
        // English's edit reaches a language with no words of its own for the
        // key, so the screen must show it as what a reset brings back.
        $this->save(['auth.login' => 'Log in'], 'user', Language::where('code', 'en')->firstOrFail());

        $french = Language::create([
            'name' => 'Français', 'name_en' => 'French', 'code' => 'fr',
            'country_code' => 'FR', 'default' => 'false', 'is_rtl' => 'false', 'app_scope' => 'user',
        ]);

        $this->assertSame('Log in', $this->served('user', 'fr')['auth']['login']);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.language.app', [$french->id, 'user']))
            ->assertOk()
            ->assertSee('placeholder="Log in"', false);
    }

    #[Test]
    public function the_language_menu_links_both_screens(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.language.index'))
            ->assertOk()
            ->assertSee(route('admin.language.app', [$this->arabic->id, 'user']), false)
            ->assertSee(route('admin.language.app', [$this->arabic->id, 'driver']), false);
    }
}
