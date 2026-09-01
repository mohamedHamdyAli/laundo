<?php

namespace Tests\Feature\Api;

use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Uploading and serving a translation set.
 *
 * Two problems in one path, both found by asking who reads what:
 *
 *   1. `replaceLanguageFile()` deleted the target and moved the upload into its
 *      place. For `panel_file` the target is `{code}.json` — the panel's complete
 *      translation, 1,076 entries — so uploading a partial file destroyed the lot,
 *      silently, with no undo. It merges now.
 *   2. `getTranslationFile()` could read a mobile or web set back and **had no
 *      caller anywhere**. The upload wrote a file nothing served: a column with no
 *      form field, from the other direction.
 *
 * The destructive one gets the most attention here. A retention or upload path
 * that quietly removes data is the kind of bug nobody finds until the day it
 * matters.
 */
class TranslationFileTest extends TestCase
{
    use RefreshDatabase;

    private string $langPath;

    /** @var array<int, string> */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $this->langPath = resource_path('lang');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        // These write into the real resources/lang, so anything created has to go.
        foreach ($this->written as $file) {
            if (File::exists($file)) {
                File::delete($file);
            }
        }

        parent::tearDown();
    }

    /** @param array<string, string> $strings */
    private function upload(array $strings): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'lang').'.json';
        file_put_contents($path, json_encode($strings, JSON_UNESCAPED_UNICODE));

        return new UploadedFile($path, 'upload.json', 'application/json', null, true);
    }

    private function track(string $filename): string
    {
        $full = $this->langPath.'/'.$filename;
        $this->written[] = $full;

        return $full;
    }

    // ------------------------------------------------------- the destructive bug

    #[Test]
    public function an_upload_merges_into_the_existing_file_rather_than_replacing_it(): void
    {
        // A test code, so the real ar.json is never touched.
        $language = Language::create([
            'name' => 'Testish', 'name_en' => 'Testish', 'code' => 'zz',
            'country_code' => 'ZZ', 'default' => 'false', 'is_rtl' => 'false',
            'app_scope' => 'user',
        ]);

        $target = $this->track('zz.json');

        // Stand in for the complete translation: many keys, painstakingly built.
        File::put($target, json_encode([
            'Existing one' => 'قيمة قديمة',
            'Existing two' => 'قيمة أخرى',
            'Existing three' => 'قيمة ثالثة',
        ], JSON_UNESCAPED_UNICODE));

        // An admin uploads a file with two keys.
        replaceLanguageFile($language, 'panel_file', $this->upload([
            'Existing one' => 'قيمة محدَّثة',
            'Brand new' => 'قيمة جديدة',
        ]));

        /** @var array<string, string> $result */
        $result = json_decode(File::get($target), true);

        // The upload wins on the key it names.
        $this->assertSame('قيمة محدَّثة', $result['Existing one']);
        // It adds what it brings.
        $this->assertSame('قيمة جديدة', $result['Brand new']);
        // And — the whole point — it does not remove what it never mentioned.
        $this->assertSame('قيمة أخرى', $result['Existing two']);
        $this->assertSame('قيمة ثالثة', $result['Existing three']);
        $this->assertCount(4, $result);
    }

    #[Test]
    public function a_malformed_upload_is_refused_before_anything_is_written(): void
    {
        $language = Language::create([
            'name' => 'Testish', 'name_en' => 'Testish', 'code' => 'zz',
            'country_code' => 'ZZ', 'default' => 'false', 'is_rtl' => 'false',
            'app_scope' => 'user',
        ]);

        $target = $this->track('zz.json');
        File::put($target, json_encode(['Keep me' => 'قيمة'], JSON_UNESCAPED_UNICODE));

        $path = tempnam(sys_get_temp_dir(), 'lang').'.json';
        file_put_contents($path, '{ this is not json');
        $broken = new UploadedFile($path, 'upload.json', 'application/json', null, true);

        try {
            replaceLanguageFile($language, 'panel_file', $broken);
            $this->fail('A malformed upload should have been refused.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('valid JSON', $e->getMessage());
        }

        // A truncated upload must not leave the target half-written.
        $this->assertSame(['Keep me' => 'قيمة'], json_decode(File::get($target), true));
    }

    #[Test]
    public function a_wrong_file_type_is_refused(): void
    {
        $language = Language::create([
            'name' => 'Testish', 'name_en' => 'Testish', 'code' => 'zz',
            'country_code' => 'ZZ', 'default' => 'false', 'is_rtl' => 'false',
            'app_scope' => 'user',
        ]);

        $path = tempnam(sys_get_temp_dir(), 'lang').'.exe';
        file_put_contents($path, 'not a translation');
        $wrong = new UploadedFile($path, 'upload.exe', 'application/octet-stream', null, true);

        $this->expectException(\Exception::class);

        replaceLanguageFile($language, 'panel_file', $wrong);
    }

    // ------------------------------------------------------------ serving it

    #[Test]
    public function an_app_can_fetch_the_set_that_was_uploaded_for_it(): void
    {
        $target = $this->track('zz_mobile.json');
        File::put($target, json_encode(['Welcome' => 'أهلاً بك'], JSON_UNESCAPED_UNICODE));

        Cache::flush();

        $data = $this->getJson('/api/v1/translations/app?code=zz')->assertOk()->json('data');

        $this->assertSame('zz', $data['code']);
        $this->assertSame('أهلاً بك', $data['strings']['Welcome']);
    }

    #[Test]
    public function a_set_nobody_has_uploaded_returns_empty_rather_than_an_error(): void
    {
        // An app with no overrides falls back to its own bundled strings. That is
        // a normal state, not a failure.
        $this->getJson('/api/v1/translations/web?code=zz')
            ->assertOk()
            ->assertJsonPath('data.strings', []);
    }

    #[Test]
    public function the_panel_set_is_not_served_to_apps(): void
    {
        // `{code}.json` is the dashboard's own translation. No app has any use for
        // a thousand admin strings, and it is not theirs to read.
        $this->getJson('/api/v1/translations/panel')->assertNotFound();
        $this->getJson('/api/v1/translations/anything-else')->assertNotFound();
    }

    #[Test]
    public function the_endpoint_is_public_because_an_app_needs_strings_before_signing_in(): void
    {
        $this->getJson('/api/v1/translations/app')->assertOk();
    }

    #[Test]
    public function an_upload_clears_the_cached_copy(): void
    {
        $language = Language::create([
            'name' => 'Testish', 'name_en' => 'Testish', 'code' => 'zz',
            'country_code' => 'ZZ', 'default' => 'false', 'is_rtl' => 'false',
            'app_scope' => 'user',
        ]);

        $target = $this->track('zz_mobile.json');
        File::put($target, json_encode(['Welcome' => 'قديم'], JSON_UNESCAPED_UNICODE));

        // Read once so the for-ever cache is warm.
        $this->assertSame(
            'قديم',
            $this->getJson('/api/v1/translations/app?code=zz')->assertOk()->json('data.strings.Welcome')
        );

        replaceLanguageFile($language, 'app_file', $this->upload(['Welcome' => 'جديد']));

        // The reader caches for ever, so a stale copy would outlive the upload.
        $this->assertSame(
            'جديد',
            $this->getJson('/api/v1/translations/app?code=zz')->assertOk()->json('data.strings.Welcome')
        );
    }
}
