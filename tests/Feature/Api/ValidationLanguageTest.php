<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A rejected request answers in the language it was made in.
 *
 * Both apps sent `Accept-Language: ar`, got a 422, and read «The phone field is
 * required.» — because a validation message does not come from the JSON files
 * the panel edits. Laravel resolves it through `lang/{code}/validation.php`, and
 * there was no Arabic one, so the framework's English set answered every time.
 *
 * Two halves matter and the second is the one that is easy to skip. Translating
 * the sentences alone produces «حقل pickup_address_id مطلوب» — the customer
 * told off in the schema's own words. `validation.attributes` is what turns that
 * into «حقل عنوان الاستلام مطلوب».
 */
class ValidationLanguageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    #[Test]
    public function a_rejected_request_answers_in_arabic(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [], $this->apiHeaders('ar'));

        $response->assertStatus(422);

        $message = $response->json('errors.phone.0') ?? $response->json('msg');

        $this->assertNotNull($message, 'the reply carried no message at all');
        $this->assertMatchesRegularExpression(
            '/[\x{0600}-\x{06FF}]/u',
            (string) $message,
            'an Arabic request was answered in English: '.$message
        );
    }

    #[Test]
    public function the_same_request_still_answers_in_english(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [], $this->apiHeaders('en'));

        $response->assertStatus(422);

        $message = (string) ($response->json('errors.phone.0') ?? $response->json('msg'));

        $this->assertDoesNotMatchRegularExpression('/[\x{0600}-\x{06FF}]/u', $message);
    }

    #[Test]
    public function the_message_names_the_field_and_not_the_column(): void
    {
        // The half that is easy to skip. Without `validation.attributes` the
        // sentence is Arabic and the noun inside it is `pickup_address_id`.
        app()->setLocale('ar');

        $rendered = trans('validation.required', [
            'attribute' => trans('validation.attributes.pickup_address_id'),
        ]);

        $this->assertStringContainsString('عنوان الاستلام', $rendered);
        $this->assertStringNotContainsString('pickup_address_id', $rendered);
    }

    #[Test]
    public function every_field_the_api_validates_has_an_arabic_name(): void
    {
        // A field with no entry falls back to its column name, which reads as a
        // bug to whoever is looking at it. Checked against the request classes
        // themselves so a field added later is caught rather than assumed.
        $this->assertNamed([
            base_path('app/Http/Requests/Api'),
            base_path('app/Http/Controllers/Api'),
        ]);
    }

    #[Test]
    public function every_field_the_dashboard_validates_has_an_arabic_name(): void
    {
        // The other surface. An operator filling in a laundry's commission or a
        // driver's shift is told off in the same Arabic a customer is, and the
        // fields are different ones — the dashboard validates seventy-odd the
        // apps never see.
        $this->assertNamed([
            base_path('app/Modules'),
            base_path('app/Http/Controllers/Admin'),
        ]);
    }

    /**
     * @param  array<int, string>  $roots
     */
    private function assertNamed(array $roots): void
    {
        $attributes = (array) trans('validation.attributes', [], 'ar');

        $missing = [];

        foreach ($this->validatedFields($roots) as $field) {
            if (! isset($attributes[$field])) {
                $missing[] = $field;
            }
        }

        $missing = array_values(array_unique($missing));
        sort($missing);

        $this->assertSame([], $missing, 'no Arabic name for: '.implode(', ', $missing));
    }

    #[Test]
    public function arabic_and_english_name_the_same_fields(): void
    {
        $ar = array_keys((array) trans('validation.attributes', [], 'ar'));
        $en = array_keys((array) trans('validation.attributes', [], 'en'));

        sort($ar);
        sort($en);

        $this->assertSame($en, $ar, 'the two attribute lists have drifted apart');
    }

    /**
     * The field names actually validated under the given roots.
     *
     * Read from the source rather than from a list somebody maintains, because
     * a list is exactly what stops being true the week after it is written.
     *
     * @param  array<int, string>  $roots
     * @return array<int, string>
     */
    private function validatedFields(array $roots): array
    {
        $rules = [
            'required', 'nullable', 'sometimes', 'string', 'integer', 'numeric',
            'boolean', 'array', 'date', 'email', 'exists:', 'unique:', 'max:',
            'min:', 'in:', 'image', 'mimes:', 'confirmed', 'digits', 'accepted',
        ];

        $fields = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                // Models are skipped: a `casts()` entry like
                // 'approved_at' => 'datetime' matches on «date» and is not a
                // validated field at all.
                if (str_contains($file->getPathname(), DIRECTORY_SEPARATOR.'Models'.DIRECTORY_SEPARATOR)) {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                // Only files that actually validate. A service returning
                // `'orders' => $query->count()` is not declaring a field, and
                // naming it would put words in `validation.attributes` that no
                // message will ever use — which makes the list stop describing
                // anything.
                if (! str_contains($source, 'function rules(') && ! str_contains($source, '->validate(')) {
                    continue;
                }

                preg_match_all(
                    "/'([a-z][a-z0-9_.*]*)'\s*=>\s*(\[[^\]]*\]|'[^']*')/",
                    $source,
                    $matches,
                    PREG_SET_ORDER
                );

                foreach ($matches as $match) {
                    $carries = false;

                    foreach ($rules as $rule) {
                        if (str_contains($match[2], $rule)) {
                            $carries = true;
                            break;
                        }
                    }

                    if (! $carries) {
                        continue;
                    }

                    $parts = array_values(array_filter(
                        explode('.', $match[1]),
                        fn (string $part): bool => $part !== '*'
                    ));

                    if ($parts !== []) {
                        $fields[] = end($parts);
                    }
                }
            }
        }

        return array_values(array_unique($fields));
    }
}
