<?php

namespace Tests\Feature\Landing;

use App\Models\Language;
use App\Models\Role;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The dashboard screen for the landing page's copy.
 *
 * It writes the same `{code}_web.json` the flat editor does, so the tests that
 * matter are the ones about what it must **not** touch: the ten legacy keys the
 * apps read through `GET /api/v1/translations/web`, and anything outside the
 * `landing.` namespace however the request is shaped.
 *
 * The other one worth having is the round trip. A screen that saves and then
 * shows the old text — because the reader caches for ever and the assembled
 * page for an hour — is a screen an operator edits three times and then reports
 * as broken.
 */
class LandingContentEditorTest extends TestCase
{
    use RefreshDatabase;

    private Language $arabic;

    private string $path;

    private string $original;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seedCore();

        $this->arabic = Language::where('code', 'ar')->firstOrFail();
        $this->path = lang_path('ar_web.json');
        $this->original = File::get($this->path);
    }

    protected function tearDown(): void
    {
        // In a finally-equivalent, so a failing assertion cannot leave the
        // repository's Arabic translation file edited.
        File::put($this->path, $this->original);
        Cache::flush();

        parent::tearDown();
    }

    /** @return array<string, string> */
    private function stored(): array
    {
        /** @var array<string, string> $decoded */
        $decoded = json_decode(File::get($this->path), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function url(): string
    {
        return route('admin.language.landing', $this->arabic->id);
    }

    private function updateUrl(): string
    {
        return route('admin.language.landing.update', $this->arabic->id);
    }

    // ---------------------------------------------------------------------

    #[Test]
    public function it_renders_grouped_by_section(): void
    {
        $response = $this->actingAs($this->superAdmin())->get($this->url())->assertOk();

        // Headings in page order, not the file's alphabetical order — which is
        // the whole reason this screen exists beside the flat editor.
        $response->assertSeeInOrder([
            __('Search engines and sharing'),
            __('Hero'),
            __('The price review'),
            __('Prices'),
            __('Footer'),
        ], false);

        // One input per template key, addressed under `landing[...]`.
        $response->assertSee('name="landing[landing.hero.title]"', false);
        $response->assertSee('name="landing[landing.faq.q1]"', false);
    }

    #[Test]
    public function it_offers_a_box_for_a_key_nobody_has_translated_yet(): void
    {
        // Walked from the template rather than the stored file, so a key added
        // to webFile.php is editable immediately — otherwise it would be
        // invisible until somebody ran the sync command.
        $stripped = $this->stored();
        unset($stripped['landing.hero.title']);
        File::put($this->path, json_encode($stripped, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        Cache::flush();

        $this->actingAs($this->superAdmin())
            ->get($this->url())
            ->assertOk()
            ->assertSee('name="landing[landing.hero.title]"', false);
    }

    #[Test]
    public function saving_writes_the_value_and_the_page_shows_it_at_once(): void
    {
        $this->actingAs($this->superAdmin())
            ->post($this->updateUrl(), [
                'landing' => ['landing.hero.title' => 'عنوان جديد من الداش بورد'],
            ])
            ->assertRedirect($this->url())
            ->assertSessionHas('success');

        $this->assertSame('عنوان جديد من الداش بورد', $this->stored()['landing.hero.title']);

        // The reader caches for ever and the assembled page for an hour, so
        // without both forgets this would still show the old wording.
        $this->get('/ar')->assertOk()->assertSee('عنوان جديد من الداش بورد', false);
    }

    #[Test]
    public function blanking_a_field_falls_back_to_the_default_rather_than_emptying_the_page(): void
    {
        $this->actingAs($this->superAdmin())
            ->post($this->updateUrl(), [
                'landing' => ['landing.hero.title' => '   '],
            ])
            ->assertRedirect();

        $this->assertArrayNotHasKey('landing.hero.title', $this->stored());

        // And the page renders the shipped English default, not a gap.
        $template = require storage_path('app/webFile.php');

        app()->setLocale('ar');
        $this->assertSame($template['landing.hero.title'], webText('landing.hero.title'));
    }

    #[Test]
    public function it_leaves_the_keys_the_apps_read_alone(): void
    {
        $before = $this->stored();

        $this->actingAs($this->superAdmin())
            ->post($this->updateUrl(), [
                'landing' => ['landing.hero.title' => 'أي حاجة'],
            ])
            ->assertRedirect();

        $after = $this->stored();

        // The ten legacy keys predate the landing page and are served to the
        // apps through GET /api/v1/translations/web.
        foreach (['Dashboard', 'users', 'settings', 'profile', 'logout', 'language', 'notifications', 'help', 'about', 'contact'] as $key) {
            $this->assertSame($before[$key], $after[$key] ?? null, "{$key} was modified");
        }
    }

    #[Test]
    public function it_refuses_to_write_outside_the_landing_namespace(): void
    {
        // The form cannot produce this, but a hand-rolled request can, and the
        // file it writes is the one the apps read.
        $before = $this->stored();

        $this->actingAs($this->superAdmin())
            ->post($this->updateUrl(), [
                'landing' => [
                    'logout' => 'HIJACKED',
                    'Dashboard' => 'HIJACKED',
                    'landing.hero.title' => 'مسموح',
                ],
            ])
            ->assertRedirect();

        $after = $this->stored();

        $this->assertSame($before['logout'], $after['logout']);
        $this->assertSame($before['Dashboard'], $after['Dashboard']);
        $this->assertSame('مسموح', $after['landing.hero.title']);
    }

    #[Test]
    public function it_does_not_touch_the_panel_translation_file(): void
    {
        $guarded = [lang_path('ar.json'), lang_path('en.json'), lang_path('ar_mobile.json')];
        $before = [];
        foreach ($guarded as $file) {
            $before[$file] = File::exists($file) ? md5_file($file) : null;
        }

        $this->actingAs($this->superAdmin())
            ->post($this->updateUrl(), ['landing' => ['landing.hero.title' => 'أي حاجة']])
            ->assertRedirect();

        foreach ($guarded as $file) {
            $this->assertSame(
                $before[$file],
                File::exists($file) ? md5_file($file) : null,
                basename($file).' was modified'
            );
        }
    }

    #[Test]
    public function it_is_gated_on_language_update(): void
    {
        // Same permission as the three editors it sits beside — it edits a
        // language file, so it needs no permission of its own.
        //
        // A separate user on the `admin` role, which `seedCore()` grants
        // nothing. Renaming the super admin's own role instead collides with
        // `roles.slug`'s unique index, and that failure reads as a schema
        // problem rather than as the test asking for the wrong thing.
        $plainAdmin = User::create([
            'name' => 'Plain',
            'email' => 'plain@test.local',
            'phone' => '+201000000099',
            'password' => 'password',
            'status' => 'active',
            'role_id' => Role::where('slug', 'admin')->value('id'),
            'phone_verified_at' => now(),
        ]);

        $this->actingAs($plainAdmin)
            ->get($this->url())
            ->assertForbidden();
    }

    #[Test]
    public function the_languages_screen_links_to_it(): void
    {
        // A screen nothing links to is a screen nobody finds. It sits in the
        // same dropdown as the three JSON editors.
        $this->actingAs($this->superAdmin())
            ->get(route('admin.language.index'))
            ->assertOk()
            ->assertSee(route('admin.language.landing', $this->arabic->id), false)
            ->assertSee(__('Edit Landing Page Content'), false);
    }
}
