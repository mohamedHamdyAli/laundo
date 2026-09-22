<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A detail screen is a read-only sheet, not a greyed-out form.
 *
 * The cheap trick behind all of them is that `show` renders the module's own
 * `formInput` with every control `disabled` — the field list lives in one place
 * and the detail view cannot drift from the form. The cost is that a disabled
 * control is styled to say «you cannot type here»: boxed, filled, and dimmed to
 * `opacity: .6`. On a page whose only job is to be read, that is the browser
 * answering a question nobody asked.
 *
 * `theme.css` turns it back into a label-and-value sheet, but only under
 * `.show-page` — so a screen built without the wrapper renders as a locked form
 * and nothing says so. Two of them shipped that way, both money screens, and
 * both were noticed by somebody looking at the page rather than by the suite.
 *
 * This asserts the structural rule instead of those two pages, because the next
 * one will be built by copying a neighbour and the neighbour is what has to be
 * right.
 */
class ShowPageWrapperTest extends TestCase
{
    #[Test]
    public function every_detail_screen_that_locks_a_form_wraps_it(): void
    {
        $missing = [];

        foreach (glob(resource_path('views/admin/*/show.blade.php')) as $show) {
            $module = basename(dirname($show));
            $form = dirname($show).'/forms/formInput.blade.php';

            // Only the screens that reuse the form. The rest — the order, the
            // complaint, the wallet — are written as their own markup and have
            // no disabled control to rescue.
            if (! file_exists($form)) {
                continue;
            }

            $locks = str_contains(file_get_contents($form), "Route::is('*.show')");

            if (! $locks) {
                continue;
            }

            // Matched in a `class` attribute, not anywhere in the file: a
            // plain `str_contains` is satisfied by the comment above the
            // wrapper explaining why the wrapper is there, so removing the
            // wrapper and keeping the comment passed.
            if (! preg_match('/class="[^"]*\bshow-page\b/', file_get_contents($show))) {
                $missing[] = $module;
            }
        }

        $this->assertSame([], $missing, implode(', ', $missing));
    }
}
