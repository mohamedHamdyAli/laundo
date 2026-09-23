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
        $attributes = (array) trans('validation.attributes', [], 'ar');

        $missing = [];

        foreach ($this->apiValidatedFields() as $field) {
            if (! isset($attributes[$field])) {
                $missing[] = $field;
            }
        }

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
     * The field names the API actually validates, read from the request classes.
     *
     * @return array<int, string>
     */
    private function apiValidatedFields(): array
    {
        $roots = [
            base_path('app/Http/Requests/Api'),
            base_path('app/Http/Controllers/Api'),
        ];

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

                $source = (string) file_get_contents($file->getPathname());

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
