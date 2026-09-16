<?php

namespace Tests\Feature\Dashboard;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An empty `<option>` is a placeholder only on a field that posts a value.
 *
 * The panel turns every `.form-select` into a select2 from one place, and that
 * init used to read any `option[value=""]` as a placeholder. On a form field
 * that is right — the empty option means «not chosen yet». On a **filter** it is
 * wrong twice over: the current selection renders greyed like an unfilled
 * prompt, and select2 hangs a clear «×» on the control as soon as anything else
 * is picked.
 *
 * That × was reported on the wallets screen, where in Arabic it printed straight
 * through the first word of the label — the clear button is pinned by the vendor
 * theme with a physical `right`, which does not mirror.
 *
 * Static assertions rather than a browser test, for the reason `SearchWiringTest`
 * gives: they cover every screen on every run and cost nothing, where driving
 * one in Playwright covers whichever one somebody remembered to list.
 */
class SelectPlaceholderWiringTest extends TestCase
{
    private function footerScript(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3).'/resources/views/layouts/footer_script.blade.php'
        );
    }

    private function theme(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3).'/public/assets/css/theme.css'
        );
    }

    #[Test]
    public function the_placeholder_is_gated_on_the_select_posting_a_name(): void
    {
        $source = $this->footerScript();

        $this->assertStringContainsString(
            "\$el.attr('name')",
            $source,
            'the select2 init no longer checks for a `name` before treating an empty '
            .'option as a placeholder — filters would go back to rendering greyed '
            .'and carrying a clear «×»'
        );

        // Tied to the placeholder rather than recomputed, so the two cannot
        // disagree: select2 needs a placeholder for allowClear to mean anything.
        $this->assertStringContainsString(
            'allowClear: placeholder !== null',
            $source,
            'allowClear must follow the placeholder, not the presence of an empty option'
        );
    }

    #[Test]
    public function the_clear_button_is_positioned_logically_and_resets_come_first(): void
    {
        $css = $this->theme();

        $at = strpos($css, '.select2-selection__clear {');

        $this->assertNotFalse($at, 'theme.css no longer overrides the select2 clear button');

        $block = substr($css, $at, (int) strpos($css, '}', $at) - $at);

        $this->assertStringContainsString('inset-inline-end', $block,
            'the clear button must be placed with a logical property or it will not mirror in Arabic');

        // The trap, and the reason this is asserted rather than trusted.
        // `inset-inline-end` resolves to `left` in RTL and `right` in LTR, so a
        // physical reset written *after* it sets the same edge and silently wins
        // — the rule then does nothing in either direction and the × stays where
        // the vendor put it. Written in this order it works; the first pass at
        // this was written the other way round and cancelled itself.
        $logical = strpos($block, 'inset-inline-end');

        foreach (['left: auto', 'right: auto'] as $reset) {
            $at = strpos($block, $reset);

            $this->assertNotFalse($at, "the clear button override must reset `{$reset}`");
            $this->assertLessThan(
                $logical,
                $at,
                "`{$reset}` must come BEFORE `inset-inline-end`, or it overrides it and the rule does nothing"
            );
        }
    }

    #[Test]
    public function room_is_reserved_for_the_clear_button_only_when_there_is_one(): void
    {
        // A blanket reserve would leave dead space on every dropdown in the
        // panel to serve the handful that are clearable.
        $this->assertStringContainsString(
            '.select2-selection__rendered:has(.select2-selection__clear)',
            $this->theme(),
            'the padding that keeps text off the clear button must be conditional on one being rendered'
        );
    }

    #[Test]
    public function no_filter_dropdown_posts_a_name(): void
    {
        // The invariant the rule above rests on. A filter that grew a `name`
        // would start rendering its default option as a placeholder again, and
        // the failure is cosmetic enough to ship unnoticed.
        $filters = [
            'admin/wallet/index.blade.php' => 'walletTypeFilter',
            'admin/order/index.blade.php' => 'orderStatusFilter',
            'admin/dispatch/index.blade.php' => 'dispatchLegFilter',
            'admin/notification/index.blade.php' => 'notificationStatusFilter',
        ];

        $root = dirname(__DIR__, 3).'/resources/views/';
        $problems = [];

        foreach ($filters as $view => $id) {
            $source = (string) file_get_contents($root.$view);

            if (preg_match('/<select[^>]*id="'.preg_quote($id, '/').'"[^>]*>/', $source, $m) !== 1) {
                $problems[] = "{$id} is no longer in {$view}";

                continue;
            }

            if (str_contains($m[0], 'name=')) {
                $problems[] = "{$id} now posts a name, so its default option renders as a placeholder";
            }
        }

        $this->assertSame([], $problems, implode("\n  ", $problems));
    }
}
