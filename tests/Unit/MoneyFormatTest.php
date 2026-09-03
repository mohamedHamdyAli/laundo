<?php

namespace Tests\Unit;

use App\Modules\Setting\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * How money is written.
 *
 * Two changes are pinned here, and both touch every screen that shows a price —
 * the panel, both apps and the invoice — which is exactly why they need holding
 * down rather than checking once in a browser.
 *
 * The currency was a parameter default (`'USD'`) that almost no caller passed,
 * so an Egyptian laundry quoted dollars everywhere and nobody had chosen that.
 * And `NumberFormatter` renders `ar` with Arabic-Indic digits, which is correct
 * by the standard and is not what Egyptian apps use.
 */
class MoneyFormatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The locale helpers read the languages table and throw without a
        // default language; `getSettingValue()` caches forever.
        $this->seedCore();
        Cache::flush();
    }

    /**
     * `getCurrentLocale()` reads the `lang` header first and falls back to the
     * application locale. There is no request here, so this is what makes the
     * Arabic assertions actually Arabic — without it they run in the default
     * language and pass for the wrong reason.
     */
    private function inArabic(callable $body): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale('ar');

        try {
            return $body();
        } finally {
            app()->setLocale($previous);
        }
    }

    private function currency(?string $code): void
    {
        if ($code === null) {
            Setting::where('key', 'Currency')->delete();
        } else {
            Setting::updateOrCreate(['key' => 'Currency'], ['value' => $code]);
        }

        Cache::flush();
    }

    public function test_the_currency_comes_from_the_setting(): void
    {
        $this->currency('SAR');

        $this->assertSame('SAR', appCurrency());
        $this->assertStringContainsString('SAR', moneyFormat(10));
    }

    /**
     * EGP, not USD. The old default was the reason every price in a Cairo
     * laundry read in dollars — a default nobody chose and nobody could see.
     */
    public function test_an_unset_currency_falls_back_to_egp_not_usd(): void
    {
        $this->currency(null);

        $this->assertSame('EGP', appCurrency());
        $this->assertStringNotContainsString('US$', moneyFormat(10));
        $this->assertStringNotContainsString('$', moneyFormat(10));
    }

    /**
     * A half-typed or renamed setting must not reach `NumberFormatter`, which
     * renders an unrecognised code as the literal string on every price.
     */
    public function test_a_malformed_currency_falls_back_rather_than_rendering_as_text(): void
    {
        foreach (['', '   ', 'EG', 'EGPP', 'egyptian pound', '123'] as $bad) {
            $this->currency($bad);

            // Braces around the variable: «$bad» reads the name as `$bad»`,
            // because the guillemet is multibyte and PHP takes it as part of
            // the identifier — an undefined-variable error rather than an
            // assertion message.
            $this->assertSame('EGP', appCurrency(), "«{$bad}» should not have been accepted");
        }
    }

    public function test_a_lowercase_setting_is_still_understood(): void
    {
        $this->currency('egp');

        $this->assertSame('EGP', appCurrency());
    }

    /**
     * The caller can still override, for the rare case that genuinely means a
     * different currency from the platform's own.
     */
    public function test_an_explicit_currency_still_wins(): void
    {
        $this->currency('EGP');

        $this->assertStringContainsString('SAR', moneyFormat(10, 'SAR'));
    }

    /**
     * The reason for `-u-nu-latn`. Without it an Arabic request renders
     * «١٠٫٠٠», which is standard-correct and not what people here read prices
     * in.
     */
    public function test_arabic_renders_western_digits(): void
    {
        $this->currency('EGP');

        $formatted = $this->inArabic(fn () => moneyFormat(1234.5));

        // No Arabic-Indic digits.
        $this->assertDoesNotMatchRegularExpression('/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u', $formatted);
        $this->assertStringContainsString('1,234.50', $formatted);
    }

    /**
     * And the extension changes *only* the numbering system: the symbol, its
     * side of the number and the separators all stay as the locale writes them.
     * A `str_replace` over the digits would have taken those with it.
     */
    public function test_the_locale_still_decides_everything_except_the_digits(): void
    {
        $this->currency('EGP');

        $arabic = $this->inArabic(fn () => moneyFormat(1234.5));

        // The Arabic symbol, not the ISO code — that is the locale's choice and
        // it survives.
        $this->assertStringContainsString('ج.م', $arabic);
        // Grouping and decimal separators are the Western ones this locale uses
        // with Latin digits.
        $this->assertStringContainsString('1,234.50', $arabic);
    }

    public function test_a_string_amount_is_formatted_rather_than_dropped(): void
    {
        $this->currency('EGP');

        // `decimal:2` casts read back as strings — "51.00" — and several
        // callers hand that straight in.
        $this->assertStringContainsString('51.00', moneyFormat('51.00'));
    }
}
