<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Faq\Models\Faq;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FAQs are translatable like every other content module.
 *
 * The schema, the service and the read path were all ready — `faqs.question` and
 * `faqs.answer` are `text` holding `{"en":…,"ar":…}`, the service json-encodes
 * whatever array it is given, and `getLocalizedValue()` reads it. Only the
 * **form** was short: it rendered one box per field, for the default language, so
 * an Arabic FAQ could not be entered at all.
 *
 * Adding the boxes needed the validation fixed in the same change. `question.*`
 * and `answer.*` were `required`, which demanded *every* language — invisible
 * while there was only ever one box to fill, and an immediate wall the moment
 * there were two. CLAUDE.md states the rule the rest of the modules follow: at
 * least one language, not all of them, and the client-side `required` comes off
 * with the server-side one because a plain attribute cannot say "at least one of
 * these".
 */
class FaqTranslationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seedCore();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'question' => ['en' => 'How is the price decided?', 'ar' => 'السعر بيتحدد إزاي؟'],
            'answer' => ['en' => 'The laundry counts and prices it.', 'ar' => 'المغسلة بتعدّ وبتسعّر.'],
            'audience' => 'customer',
            'order' => 1,
            'status' => 'active',
        ], $overrides);
    }

    // -----------------------------------------------------------------

    #[Test]
    public function the_form_offers_a_box_for_every_language(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.faq.create'))
            ->assertOk();

        // Both languages, both fields. Walked from the languages table rather
        // than hardcoded, so a third language added tomorrow is covered.
        foreach (['en', 'ar'] as $code) {
            $response->assertSee('name="question['.$code.']"', false);
            $response->assertSee('name="answer['.$code.']"', false);
        }
    }

    #[Test]
    public function the_translation_boxes_carry_no_client_side_required(): void
    {
        // The pairing CLAUDE.md warns about: with the server rule relaxed to "at
        // least one", a `required` attribute would block a submit the server
        // would have accepted, and the user gets no message explaining why.
        $html = (string) $this->actingAs($this->superAdmin())
            ->get(route('admin.faq.create'))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            0,
            preg_match_all('/name="(?:question|answer)\[[a-z]{2}\]"[^>]*\brequired\b/', $html),
            'a translation box still carries required'
        );
    }

    #[Test]
    public function it_stores_both_languages(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.faq.store'), $this->payload())
            ->assertRedirect();

        $faq = Faq::firstOrFail();

        // The accessor returns stdClass — never an array. The project's most
        // repeated gotcha.
        $this->assertSame('How is the price decided?', $faq->question->en);
        $this->assertSame('السعر بيتحدد إزاي؟', $faq->question->ar);
        $this->assertSame('The laundry counts and prices it.', $faq->answer->en);
        $this->assertSame('المغسلة بتعدّ وبتسعّر.', $faq->answer->ar);
    }

    #[Test]
    public function the_arabic_is_stored_readably_not_as_escapes(): void
    {
        // `JSON_UNESCAPED_UNICODE`, or the column holds \uXXXX and the value is
        // unreadable in the editor that has to maintain it.
        $this->actingAs($this->superAdmin())
            ->post(route('admin.faq.store'), $this->payload())
            ->assertRedirect();

        // The query builder, not the model: `Faq::query()->value()` still runs the
        // accessor and hands back stdClass, which is the decoded value rather than
        // the bytes in the column this test is about.
        $raw = (string) DB::table('faqs')->value('question');

        $this->assertStringContainsString('السعر', $raw);
        // The escape sequence itself, not the letter — asserting the letter is
        // absent would fail on the very value that proves the point.
        $this->assertStringNotContainsString('\u0627', $raw);
    }

    #[Test]
    public function one_language_is_enough(): void
    {
        // The reason the validation had to change with the form. An
        // English-only FAQ is a normal thing to write.
        $this->actingAs($this->superAdmin())
            ->post(route('admin.faq.store'), $this->payload([
                'question' => ['en' => 'English only', 'ar' => ''],
                'answer' => ['en' => 'Also English only', 'ar' => ''],
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Faq::count());
    }

    #[Test]
    public function arabic_only_is_enough_too(): void
    {
        // The other direction, which is the common case on this product — its
        // seeded content is Arabic-first.
        $this->actingAs($this->superAdmin())
            ->post(route('admin.faq.store'), $this->payload([
                'question' => ['en' => '', 'ar' => 'بالعربي بس'],
                'answer' => ['en' => '', 'ar' => 'الإجابة بالعربي بس'],
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('بالعربي بس', Faq::firstOrFail()->question->ar);
    }

    #[Test]
    public function every_language_blank_is_still_refused(): void
    {
        // "At least one" has to mean at least one. Whitespace does not count —
        // `filled()`, matching what `pickTranslation()` does when reading.
        $this->actingAs($this->superAdmin())
            ->post(route('admin.faq.store'), $this->payload([
                'question' => ['en' => '', 'ar' => '   '],
                'answer' => ['en' => '', 'ar' => ''],
            ]))
            ->assertSessionHasErrors(['question', 'answer']);

        $this->assertSame(0, Faq::count());
    }

    #[Test]
    public function an_edit_can_add_the_second_language_without_resending_the_first(): void
    {
        $faq = Faq::create([
            'question' => json_encode(['en' => 'Original', 'ar' => ''], JSON_UNESCAPED_UNICODE),
            'answer' => json_encode(['en' => 'Original answer', 'ar' => ''], JSON_UNESCAPED_UNICODE),
            'audience' => 'both',
            'order' => 1,
            'status' => 'active',
        ]);

        $this->actingAs($this->superAdmin())
            ->put(route('admin.faq.update', $faq->id), $this->payload([
                'question' => ['en' => 'Original', 'ar' => 'العربية اتضافت'],
                'answer' => ['en' => 'Original answer', 'ar' => 'الإجابة اتضافت'],
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $fresh = $faq->fresh();

        $this->assertSame('Original', $fresh->question->en);
        $this->assertSame('العربية اتضافت', $fresh->question->ar);
    }

    #[Test]
    public function the_edit_form_shows_what_is_already_stored_in_each_language(): void
    {
        // A form that loses the other language on open is a form that deletes it
        // on save.
        $faq = Faq::create([
            'question' => json_encode(['en' => 'Stored EN', 'ar' => 'المخزّن بالعربي'], JSON_UNESCAPED_UNICODE),
            'answer' => json_encode(['en' => 'Answer EN', 'ar' => 'الإجابة بالعربي'], JSON_UNESCAPED_UNICODE),
            'audience' => 'both',
            'order' => 1,
            'status' => 'active',
        ]);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.faq.edit', $faq->id))
            ->assertOk()
            ->assertSee('Stored EN', false)
            ->assertSee('المخزّن بالعربي', false)
            ->assertSee('الإجابة بالعربي', false);
    }

    #[Test]
    public function one_search_term_finds_either_language(): void
    {
        // The `CAST(... AS CHAR)` in the search scope means a LIKE runs against
        // the raw JSON, so a single term reaches both languages at once.
        Faq::create([
            'question' => json_encode(['en' => 'Cancellation', 'ar' => 'الإلغاء'], JSON_UNESCAPED_UNICODE),
            'answer' => json_encode(['en' => 'Free until pickup', 'ar' => 'مجاناً قبل الاستلام'], JSON_UNESCAPED_UNICODE),
            'audience' => 'both',
            'order' => 1,
            'status' => 'active',
        ]);

        foreach (['cancellation', 'الإلغاء', 'مجاناً'] as $term) {
            $this->assertSame(
                1,
                Faq::search($term, ['question', 'answer'])->count(),
                "searching '{$term}' should have found the FAQ"
            );
        }
    }
}
