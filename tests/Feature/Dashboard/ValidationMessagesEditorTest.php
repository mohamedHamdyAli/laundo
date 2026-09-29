<?php

namespace Tests\Feature\Dashboard;

use App\Models\ActivityLog;
use App\Models\Language;
use App\Models\Role;
use App\Modules\User\Models\User;
use App\Services\languages\LanguageService;
use App\Services\languages\ValidationMessages;
use App\Support\Translation\ValidationOverrideLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Edit Validation Messages» — what a refused request is told, per language.
 *
 * The test that matters is the first one: a message saved on the screen is the
 * message a real 422 answers with. A screen that saves a file nothing reads
 * would pass every other test here.
 *
 * The store is pointed at a directory of this test's own, so nothing here
 * writes the store a running app reads — a run killed half-way leaves nothing
 * behind to change what the next customer is told.
 */
class ValidationMessagesEditorTest extends TestCase
{
    use RefreshDatabase;

    private Language $arabic;

    private string $store;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seedCore();

        $this->store = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laundo-validation-'.uniqid();
        config(['app.validation_overrides_path' => $this->store]);

        $this->arabic = Language::where('code', 'ar')->firstOrFail();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->store);

        parent::tearDown();
    }

    private function save(array $messages, ?Language $language = null): TestResponse
    {
        return $this->actingAs($this->superAdmin())->post(
            route('admin.language.validation.update', ($language ?? $this->arabic)->id),
            ['messages' => $messages],
        );
    }

    private function french(string $code = 'fr'): Language
    {
        return Language::create([
            'name' => 'Français', 'name_en' => 'French', 'code' => $code,
            'country_code' => 'FR', 'default' => 'false', 'is_rtl' => 'false', 'app_scope' => 'user',
        ]);
    }

    /**
     * The translator keeps a group once loaded, for the life of the app — one
     * request in production, the whole test here.
     */
    private function forgetLoadedTranslations(): void
    {
        app('translator')->setLoaded([]);
    }

    // ---------------------------------------------------------------------

    #[Test]
    public function a_saved_message_is_what_a_rejected_request_is_told(): void
    {
        $this->save([
            'required' => 'لازم تكتب :attribute يا فندم.',
            'attributes.phone' => 'رقم الموبايل',
        ])->assertRedirect(route('admin.language.validation', $this->arabic->id));

        $this->forgetLoadedTranslations();

        $this->postJson('/api/v1/auth/login', [], $this->apiHeaders('ar'))
            ->assertStatus(422)
            ->assertJsonPath('errors.phone.0', 'لازم تكتب رقم الموبايل يا فندم.');
    }

    #[Test]
    public function it_is_kept_out_of_the_repository(): void
    {
        $this->save(['required' => 'لازم تكتب :attribute يا فندم.']);

        // Written at runtime, so it lives with the runtime data — a tracked
        // directory the server writes into is one `git pull` refuses to update.
        $this->assertFileExists($this->store.DIRECTORY_SEPARATOR.'ar_validation.json');
        $this->assertFileDoesNotExist(lang_path('ar_validation.json'));

        $shipped = require base_path('config/app.php');
        $this->assertStringStartsWith(storage_path(), $shipped['validation_overrides_path']);
    }

    #[Test]
    public function only_the_language_it_was_saved_for_changes(): void
    {
        $this->save(['required' => 'لازم تكتب :attribute يا فندم.']);
        $this->forgetLoadedTranslations();

        $english = (string) $this->postJson('/api/v1/auth/login', [], $this->apiHeaders('en'))
            ->assertStatus(422)
            ->json('errors.phone.0');

        $this->assertStringNotContainsString('يا فندم', $english);
    }

    #[Test]
    public function a_nested_message_is_saved_under_its_own_rule(): void
    {
        // `min.string` is a key inside an array in validation.php, and the form
        // posts it as `messages[min.string]`.
        $this->save(['min.string' => ':attribute لازم يكون :min حروف على الأقل.']);
        $this->forgetLoadedTranslations();

        app()->setLocale('ar');
        $this->assertSame(
            'الاسم لازم يكون 3 حروف على الأقل.',
            trans('validation.min.string', ['attribute' => 'الاسم', 'min' => 3]),
        );

        // Its siblings are still the shipped ones.
        $shipped = require lang_path('ar/validation.php');
        $this->assertSame($shipped['min']['numeric'], trans('validation.min.numeric'));
    }

    #[Test]
    public function the_screen_shows_the_default_as_the_placeholder_and_the_saved_text_as_the_value(): void
    {
        $this->save(['required' => 'لازم تكتب :attribute يا فندم.']);

        $shipped = require lang_path('ar/validation.php');

        $this->actingAs($this->superAdmin())
            ->get(route('admin.language.validation', $this->arabic->id))
            ->assertOk()
            ->assertSee('name="messages[required]"', false)
            ->assertSee('name="messages[min.string]"', false)
            ->assertSee('name="messages[attributes.phone]"', false)
            ->assertSee('value="لازم تكتب :attribute يا فندم."', false)
            ->assertSee('placeholder="'.e($shipped['accepted']).'"', false)
            ->assertSee(__('Field names'));
    }

    #[Test]
    public function a_message_the_arabic_file_never_translated_has_a_box_and_takes_effect(): void
    {
        // `password.letters` answers Arabic users in English today — the one
        // most in need of a box, and the one a screen built from the Arabic file
        // alone would not have offered.
        $english = require lang_path('en/validation.php');
        $shipped = require lang_path('ar/validation.php');
        $this->assertArrayNotHasKey('password', $shipped);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.language.validation', $this->arabic->id))
            ->assertSee('name="messages[password.letters]"', false)
            ->assertSee('placeholder="'.e($english['password']['letters']).'"', false);

        $this->save(['password.letters' => ':attribute لازم يكون فيه حرف واحد على الأقل.'])->assertRedirect();
        $this->forgetLoadedTranslations();

        app()->setLocale('ar');
        $this->assertSame('كلمة السر لازم يكون فيه حرف واحد على الأقل.', trans('validation.password.letters', ['attribute' => 'كلمة السر']));
        // Its untranslated siblings still answer, in English, as before.
        $this->assertSame(
            str_replace(':attribute', 'x', $english['password']['mixed']),
            trans('validation.password.mixed', ['attribute' => 'x']),
        );
    }

    #[Test]
    public function a_blank_box_is_a_reset_to_the_shipped_wording(): void
    {
        $this->save(['required' => 'لازم تكتب :attribute يا فندم.', 'accepted' => 'لازم توافق على :attribute.']);
        $this->save(['required' => '   ', 'accepted' => 'لازم توافق على :attribute.']);

        $stored = ValidationOverrideLoader::overrides('ar');
        $this->assertArrayNotHasKey('required', $stored);
        $this->assertSame('لازم توافق على :attribute.', $stored['accepted']);

        // Everything cleared: no file left behind to read on every request.
        $this->save(['accepted' => '']);
        $this->assertFileDoesNotExist(ValidationOverrideLoader::pathFor('ar'));
    }

    #[Test]
    public function a_message_that_loses_a_placeholder_is_refused_and_nothing_is_saved(): void
    {
        $response = $this->actingAs($this->superAdmin())->postJson(
            route('admin.language.validation.update', $this->arabic->id),
            ['messages' => [
                'accepted' => 'لازم توافق على :attribute.',
                'min.string' => 'الحقل ده قصير أوي.',
            ]],
        );

        // Keyed by the input's own name, so the background submit can paint it
        // beside the box rather than in the banner.
        $response->assertStatus(422)->assertJsonValidationErrors(['messages[min.string]']);
        $message = $response->json('errors')['messages[min.string]'][0];
        $this->assertStringContainsString(':attribute', $message);
        $this->assertStringContainsString(':min', $message);

        // All or nothing: the good row beside it is not half-saved.
        $this->assertFileDoesNotExist(ValidationOverrideLoader::pathFor('ar'));
    }

    #[Test]
    public function a_placeholder_counts_only_in_a_spelling_laravel_fills(): void
    {
        // `:Attribute` and `:MIN` are filled like `:attribute` and `:min`…
        $this->save(['min.string' => ':Attribute لازم يكون :MIN حروف.'])->assertRedirect();
        $this->assertSame(':Attribute لازم يكون :MIN حروف.', ValidationOverrideLoader::overrides('ar')['min.string']);

        // …but `:mIN` would reach the customer as typed, so it has been lost.
        $this->actingAs($this->superAdmin())->postJson(
            route('admin.language.validation.update', $this->arabic->id),
            ['messages' => ['min.string' => ':attribute لازم يكون :mIN حروف.']],
        )->assertStatus(422)->assertJsonValidationErrors(['messages[min.string]']);
    }

    #[Test]
    public function keys_the_shipped_files_do_not_have_are_ignored(): void
    {
        $this->save([
            'no_such_rule' => 'x',
            'custom.phone.required' => 'x',
            '../../../etc' => 'x',
            'min' => ['string' => 'nested posts are not a message'],
            'accepted' => 'لازم توافق على :attribute.',
        ])->assertRedirect();

        $this->assertSame(['accepted' => 'لازم توافق على :attribute.'], ValidationOverrideLoader::overrides('ar'));
    }

    #[Test]
    public function bytes_that_are_not_text_do_not_wipe_the_file(): void
    {
        $this->save(['accepted' => 'لازم توافق على :attribute.', 'required' => 'لازم تكتب :attribute.']);

        // json_encode() gives up on invalid UTF-8; unchecked, the file became a
        // bare newline and every saved message went with it.
        $this->save([
            'accepted' => 'لازم توافق على :attribute.',
            'required' => 'لازم تكتب :attribute.',
            'email' => "\xC3\x28 :attribute",
        ])->assertRedirect();

        $this->assertSame(
            ['accepted' => 'لازم توافق على :attribute.', 'required' => 'لازم تكتب :attribute.'],
            ValidationOverrideLoader::overrides('ar'),
        );
    }

    #[Test]
    public function the_screen_answers_to_language_update_and_nothing_less(): void
    {
        $role = Role::where('slug', 'admin')->firstOrFail();
        $moderator = User::create([
            'name' => 'Mod', 'email' => 'mod@test.local', 'phone' => '+201000000077',
            'password' => 'password', 'status' => 'active', 'role_id' => $role->id,
        ]);

        $this->grant('admin', ['language.view']);

        $this->actingAs($moderator)
            ->get(route('admin.language.validation', $this->arabic->id))
            ->assertForbidden();
        $this->actingAs($moderator)
            ->post(route('admin.language.validation.update', $this->arabic->id), ['messages' => ['required' => ':attribute x']])
            ->assertForbidden();
        $this->assertFileDoesNotExist(ValidationOverrideLoader::pathFor('ar'));

        $this->grant('admin', ['language.view', 'language.update']);

        $this->actingAs($moderator->fresh())
            ->get(route('admin.language.validation', $this->arabic->id))
            ->assertOk();
    }

    #[Test]
    public function a_language_that_ships_no_messages_can_be_given_them(): void
    {
        // Added from the panel later, with no `lang/fr/validation.php` of its
        // own: shown the English file as its defaults, and what is saved is read.
        $french = $this->french();
        $this->assertFileDoesNotExist(lang_path('fr/validation.php'));

        $english = require lang_path('en/validation.php');
        $this->assertSame($english['required'], app(ValidationMessages::class)->defaults('fr')['required']);

        $this->save(['required' => 'Le champ :attribute est obligatoire.'], $french)->assertRedirect();
        $this->forgetLoadedTranslations();

        app()->setLocale('fr');
        $this->assertSame('Le champ phone est obligatoire.', trans('validation.required', ['attribute' => 'phone']));

        // What it was not given still answers, from the fallback language.
        $this->assertSame(
            str_replace(':attribute', 'phone', $english['email']),
            trans('validation.email', ['attribute' => 'phone']),
        );
    }

    #[Test]
    public function naming_one_field_in_such_a_language_leaves_the_others_named(): void
    {
        // Laravel reads `validation.attributes` as one array, with no fallback
        // per field — so a French set holding only `phone` would have un-named
        // every other field in the app.
        $french = $this->french();
        $english = require lang_path('en/validation.php');
        $other = collect($english['attributes'])->except('phone')->keys()->first();

        $this->save(['attributes.phone' => 'le téléphone'], $french)->assertRedirect();
        $this->forgetLoadedTranslations();

        app()->setLocale('fr');
        $names = trans('validation.attributes');
        $this->assertSame('le téléphone', $names['phone']);
        $this->assertSame($english['attributes'][$other], $names[$other]);
    }

    #[Test]
    public function a_saved_message_that_a_later_release_outgrew_is_not_served(): void
    {
        // Saved as «:attribute is short» before a release added `:min` to the
        // shipped wording: serving it would drop the number from every refusal.
        $lines = ValidationOverrideLoader::lay(
            ['min' => ['string' => ':attribute must be at least :min characters.']],
            ['min.string' => ':attribute is short.'],
        );

        $this->assertSame(':attribute must be at least :min characters.', $lines['min']['string']);
    }

    #[Test]
    public function a_field_name_with_dots_in_it_stays_one_field(): void
    {
        // A field is named the way the request names it — `items.*.quantity` —
        // and a name beside it can be `name` and `name.en`. Undotting would
        // nest the first into three arrays and turn the second's string into
        // an array; everything after `attributes.` is one key instead.
        $lines = ValidationOverrideLoader::lay(
            ['required' => 'shipped', 'min' => ['string' => 'shipped'], 'attributes' => ['name' => 'الاسم']],
            [
                'attributes.items.*.quantity' => 'الكمية',
                'attributes.name.en' => 'الاسم بالإنجليزي',
                'min.string' => 'قصير',
                'required.nonsense' => 'a hand-edited file turning a message into an array',
                'min' => 'nor an array into a message',
            ],
        );

        $this->assertSame('الكمية', $lines['attributes']['items.*.quantity']);
        $this->assertSame('الاسم', $lines['attributes']['name']);
        $this->assertSame('الاسم بالإنجليزي', $lines['attributes']['name.en']);
        $this->assertSame(['string' => 'قصير'], $lines['min']);
        $this->assertSame('shipped', $lines['required']);

        // And Laravel finds it: `items.0.quantity` is the field a refusal names.
        app()->setLocale('ar');
        File::ensureDirectoryExists($this->store);
        File::put(ValidationOverrideLoader::pathFor('ar'), json_encode(['attributes.items.*.quantity' => 'الكمية'], JSON_UNESCAPED_UNICODE));
        $this->forgetLoadedTranslations();

        $validator = validator(['items' => [['quantity' => null]]], ['items.*.quantity' => 'required']);
        $this->assertStringContainsString('الكمية', $validator->errors()->first('items.0.quantity'));
    }

    #[Test]
    public function a_file_edited_by_hand_into_nonsense_is_ignored_rather_than_fatal(): void
    {
        File::ensureDirectoryExists($this->store);
        File::put(ValidationOverrideLoader::pathFor('ar'), '{"required": ["not", "a", "string"], "accepted": 5, broken');
        $this->forgetLoadedTranslations();

        $this->postJson('/api/v1/auth/login', [], $this->apiHeaders('ar'))->assertStatus(422);

        File::put(ValidationOverrideLoader::pathFor('ar'), '{"required": ["not", "a", "string"], "accepted": 5}');
        $this->assertSame([], ValidationOverrideLoader::overrides('ar'));
    }

    #[Test]
    public function a_change_is_in_the_activity_log_as_the_words_before_and_after(): void
    {
        $shipped = require lang_path('ar/validation.php');

        $this->save(['required' => 'لازم تكتب :attribute يا فندم.']);
        $this->save(['required' => '']);

        $rows = ActivityLog::where('subject_type', $this->arabic->getMorphClass())
            ->where('subject_id', $this->arabic->id)
            ->where('event', 'updated')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertSame(
            ['old' => $shipped['required'], 'new' => 'لازم تكتب :attribute يا فندم.'],
            $rows[0]->diff['validation.required'],
        );
        // A reset reads as the words it went back to.
        $this->assertSame($shipped['required'], $rows[1]->diff['validation.required']['new']);
        $this->assertSame($this->superAdmin()->id, $rows[0]->user_id);

        // Saving the same words again is not a change.
        $this->save(['accepted' => '']);
        $this->assertSame(2, ActivityLog::where('subject_type', $this->arabic->getMorphClass())->where('event', 'updated')->count());

        app()->setLocale('ar');
        $this->assertSame(__('Validation message').' — required', ActivityLog::fieldLabel('validation.required'));
        $this->assertSame(__('Field name').' — phone', ActivityLog::fieldLabel('validation.attributes.phone'));
    }

    #[Test]
    public function a_language_whose_code_changes_keeps_its_messages(): void
    {
        $french = $this->french();
        $this->save(['required' => 'Le champ :attribute est obligatoire.'], $french);

        app(LanguageService::class)->updateRecord(['id' => $french->id, 'code' => 'fr_CA']);

        $this->assertFileDoesNotExist(ValidationOverrideLoader::pathFor('fr'));
        $this->assertSame(['required' => 'Le champ :attribute est obligatoire.'], ValidationOverrideLoader::overrides('fr_CA'));
    }

    #[Test]
    public function deleting_a_language_takes_its_messages_with_it(): void
    {
        $french = $this->french();
        $this->save(['required' => 'Le champ :attribute est obligatoire.'], $french);
        $this->assertFileExists(ValidationOverrideLoader::pathFor('fr'));

        app(LanguageService::class)->deleteRecord($french->id);

        $this->assertFileDoesNotExist(ValidationOverrideLoader::pathFor('fr'));
    }

    #[Test]
    public function the_languages_list_links_to_it(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('admin.language.index'))
            ->assertOk()
            ->assertSee(route('admin.language.validation', $this->arabic->id), false)
            ->assertSee(__('Edit Validation Messages'));
    }

    #[Test]
    public function a_refusal_is_toasted_as_text_never_as_script(): void
    {
        // The panel's toast used to print each error inside "…" with `{!! !!}`:
        // one quote in a message closed the string and ran the rest. The wording
        // is editable now, so the message is whatever somebody typed.
        $payload = 'x"); document.title = "owned"; ("</script><script>alert(1)</script>';

        $response = $this->actingAs($this->superAdmin())
            ->withSession(['errors' => (new ViewErrorBag)->put('default', new MessageBag(['name' => [$payload]]))])
            ->get(route('admin.language.index'))
            ->assertOk();

        $html = $response->getContent();
        $this->assertStringNotContainsString($payload, $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('showErrorToast('.json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT).');', $html);
    }
}
