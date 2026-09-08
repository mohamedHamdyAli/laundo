<?php

namespace Tests\Feature\Dashboard;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The three settings that hold documents rather than strings.
 *
 * `About`, `Terms` and `Privacy_Policy` are published as HTML — the public legal
 * page prints them unescaped, and its own comment described them as being
 * "written by the panel's rich-text editors". They were not: About was a
 * three-row textarea, and the only way to give the terms a heading was to type
 * `<h2>` into it by hand.
 *
 * Two things were wrong beyond the missing editor, and both are covered here
 * because neither is visible from reading the screen:
 *
 *   - **«Edit Privacy And Terms» existed and was unreachable.** The route, the
 *     controller, the view and a box per language had all been built; nothing
 *     anywhere linked to it, and `config/menu.php` maps the `setting` key to one
 *     route. It hangs off the general settings header now.
 *   - **The rules were on the wrong thing.** `'Terms' => 'nullable|max:5000'`
 *     applies `max` to an *array*, so it was counting languages: it would have
 *     passed a payload of any size and failed only an install with 5,001 of
 *     them. Moving it to `Terms.*` is the obvious tidy-up and, at 5,000, would
 *     have refused to save the copy this application already ships — the terms
 *     are 7,477 characters of English and 10,106 bytes of Arabic. The ceiling
 *     is 20,000, and the test below pins that so the tidy-up cannot happen
 *     without the size question being asked again.
 */
class LegalSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seedCore();
    }

    private function legalPayload(array $overrides = []): array
    {
        return array_replace([
            'Terms' => [
                'en' => '<h2>Who we are</h2><p>Laundo collects and delivers laundry.</p>',
                'ar' => '<h2>من نحن</h2><p>لاوندو تجمع الملابس وتسلمها.</p>',
            ],
            'Privacy_Policy' => [
                'en' => '<h2>What we store</h2><p>Your number and your addresses.</p>',
                'ar' => '<h2>ما نحفظه</h2><p>رقمك وعناوينك.</p>',
            ],
        ], $overrides);
    }

    private function stored(string $key): array
    {
        return json_decode((string) DB::table('settings')->where('key', $key)->value('value'), true) ?: [];
    }

    // -----------------------------------------------------------------
    // Reachability
    // -----------------------------------------------------------------

    #[Test]
    public function the_general_settings_screen_links_to_the_legal_one(): void
    {
        // The whole defect: everything below this line already worked, and no
        // operator could get to it.
        $this->actingAs($this->superAdmin())
            ->get(route('admin.generalSetting.viewGeneralSetting'))
            ->assertOk()
            ->assertSee(route('admin.generalSetting.viewPrivacyAndTerms'), false);
    }

    #[Test]
    public function the_legal_screen_offers_a_box_for_every_language(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->get(route('admin.generalSetting.viewPrivacyAndTerms'))
            ->assertOk();

        foreach (['en', 'ar'] as $code) {
            $response->assertSee('name="Terms['.$code.']"', false);
            $response->assertSee('name="Privacy_Policy['.$code.']"', false);
        }

        // And back, because this screen is not in the sidebar.
        $response->assertSee(route('admin.generalSetting.viewGeneralSetting'), false);
    }

    // -----------------------------------------------------------------
    // The editor
    // -----------------------------------------------------------------

    #[Test]
    public function every_document_field_is_marked_for_the_rich_text_editor(): void
    {
        // `data-rich-text` is the contract with `setupRichText()` in
        // `layouts/footer_script`. Asserting the attribute rather than the
        // editor because the editor is TinyMCE's business; this is ours.
        $legal = (string) $this->actingAs($this->superAdmin())
            ->get(route('admin.generalSetting.viewPrivacyAndTerms'))
            ->assertOk()
            ->getContent();

        // Counted on the `<textarea>` tags, not with `substr_count` over the
        // page: `footer_script` mentions `data-rich-text` twice itself — once in
        // the selector and once in the comment above it — and it renders on
        // every screen, so a plain substring count reads four boxes as six.
        $this->assertSame(4, preg_match_all('/<textarea[^>]*data-rich-text/', $legal));

        $general = (string) $this->actingAs($this->superAdmin())
            ->get(route('admin.generalSetting.viewGeneralSetting'))
            ->assertOk()
            ->getContent();

        // About, two languages.
        $this->assertSame(2, preg_match_all('/<textarea[^>]*data-rich-text/', $general));
    }

    #[Test]
    public function the_arabic_box_is_authored_right_to_left(): void
    {
        // A document written in Arabic has to be typed right-to-left even while
        // the panel itself is in English, so the direction comes off the
        // language row rather than off the session.
        $html = (string) $this->actingAs($this->superAdmin())
            ->get(route('admin.generalSetting.viewPrivacyAndTerms'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/name="Terms\[ar\]"[^>]*dir="rtl"|dir="rtl"[^>]*name="Terms\[ar\]"/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/name="Terms\[en\]"[^>]*dir="ltr"|dir="ltr"[^>]*name="Terms\[en\]"/s',
            $html
        );
    }

    // -----------------------------------------------------------------
    // Saving
    // -----------------------------------------------------------------

    #[Test]
    public function it_stores_both_languages_of_both_documents(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.generalSetting.updatePrivacyAndTerms'), $this->legalPayload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $terms = $this->stored('Terms');
        $privacy = $this->stored('Privacy_Policy');

        $this->assertStringContainsString('<h2>Who we are</h2>', $terms['en']);
        $this->assertStringContainsString('<h2>من نحن</h2>', $terms['ar']);
        $this->assertStringContainsString('<h2>What we store</h2>', $privacy['en']);
        $this->assertStringContainsString('<h2>ما نحفظه</h2>', $privacy['ar']);
    }

    #[Test]
    public function the_markup_survives_the_round_trip(): void
    {
        // The point of a rich-text editor is that the tags reach the column. A
        // service that escaped or stripped them would leave the public page
        // showing `&lt;h2&gt;`.
        $this->actingAs($this->superAdmin())
            ->put(route('admin.generalSetting.updatePrivacyAndTerms'), $this->legalPayload())
            ->assertRedirect();

        $raw = (string) DB::table('settings')->where('key', 'Terms')->value('value');

        $this->assertStringNotContainsString('&lt;h2&gt;', $raw);
        // Arabic readable in the column, not as escapes — `JSON_UNESCAPED_UNICODE`.
        // The assertion is against a `\uXXXX` sequence; writing the Arabic
        // letter here instead fails on the very value that proves the point,
        // which is a mistake this suite has now made twice.
        $this->assertStringContainsString('من نحن', $raw);
        // A substring rather than a pattern: PCRE2 rejects `\u` in a regex
        // outright — "Compilation failed" — which errors the test instead of
        // failing it, and an errored test reads as a broken suite.
        $this->assertStringNotContainsString('\u', $raw);
    }

    #[Test]
    public function a_document_the_size_of_the_shipped_terms_is_accepted(): void
    {
        // The regression this exists to prevent. The shipped Arabic terms are
        // 10,106 bytes; a `max:5000` on each language would refuse to save copy
        // the operator never touched, and the error would point at a field they
        // had not edited.
        $long = '<h2>Long</h2>'.str_repeat('<p>A clause that goes on. </p>', 400);
        $this->assertGreaterThan(10000, strlen($long));

        $this->actingAs($this->superAdmin())
            ->put(route('admin.generalSetting.updatePrivacyAndTerms'), $this->legalPayload([
                'Terms' => ['en' => $long, 'ar' => $long],
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($long, $this->stored('Terms')['en']);
    }

    #[Test]
    public function a_document_past_the_ceiling_is_refused(): void
    {
        // The ceiling is real rather than decorative, so raising it is a
        // decision somebody makes on purpose.
        $tooLong = str_repeat('x', 20_001);

        $this->actingAs($this->superAdmin())
            ->put(route('admin.generalSetting.updatePrivacyAndTerms'), $this->legalPayload([
                'Terms' => ['en' => $tooLong],
            ]))
            ->assertSessionHasErrors('Terms.en');
    }

    #[Test]
    public function about_is_saved_from_the_general_screen_with_its_markup(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.generalSetting.updateGeneralSetting'), [
                'About' => [
                    'en' => '<p>We count the basket.</p>',
                    'ar' => '<p>نعد السلة.</p>',
                ],
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $about = $this->stored('About');

        $this->assertSame('<p>We count the basket.</p>', $about['en']);
        $this->assertSame('<p>نعد السلة.</p>', $about['ar']);
    }

    #[Test]
    public function one_language_can_be_written_without_wiping_the_other(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.generalSetting.updatePrivacyAndTerms'), $this->legalPayload())
            ->assertRedirect();

        // A second save that changes only the Arabic. The form posts every box,
        // so this is what actually happens — but it is worth pinning, because
        // the service writes the whole JSON blob each time.
        $this->actingAs($this->superAdmin())
            ->put(route('admin.generalSetting.updatePrivacyAndTerms'), $this->legalPayload([
                'Terms' => [
                    'en' => '<h2>Who we are</h2><p>Laundo collects and delivers laundry.</p>',
                    'ar' => '<h2>من نحن</h2><p>نص جديد.</p>',
                ],
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $terms = $this->stored('Terms');

        $this->assertStringContainsString('نص جديد', $terms['ar']);
        $this->assertStringContainsString('Laundo collects', $terms['en']);
    }

    // -----------------------------------------------------------------
    // What the public page does with it
    // -----------------------------------------------------------------

    #[Test]
    public function the_public_page_renders_the_saved_markup_as_markup(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.generalSetting.updatePrivacyAndTerms'), $this->legalPayload())
            ->assertRedirect();

        Cache::flush();

        $this->get('/en/terms')
            ->assertOk()
            ->assertSee('<h2>Who we are</h2>', false);
    }

    #[Test]
    public function the_public_page_does_not_load_the_editor(): void
    {
        // `layouts/landing` deliberately loads none of the panel's assets, and
        // TinyMCE is 7.4 MB of them. Adding the editor to the panel must not
        // reach the public page.
        $html = (string) $this->get('/en/terms')->assertOk()->getContent();

        $this->assertStringNotContainsString('tinymce', $html);
        $this->assertStringNotContainsString('data-rich-text', $html);
    }
}
