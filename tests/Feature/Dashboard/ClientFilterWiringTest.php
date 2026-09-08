<?php

namespace Tests\Feature\Dashboard;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The five screens that filter in the browser rather than over AJAX.
 *
 * Prices, My Services, My Areas, Roles and Time Slots are not lists of records —
 * each is a single `<form>` that posts a whole grid. A server-side `search`
 * endpoint would re-render part of that grid and **blank the cells it did not
 * draw** when the form was next saved, so the AJAX pattern the other 29 screens
 * use is the wrong tool here rather than a missing one. They hide non-matching
 * rows in the page instead.
 *
 * Static checks, for the same reason `SearchWiringTest` is static: they cover
 * all five on every run, where driving each one by hand covers whichever ones
 * somebody remembered. The behaviour itself is exercised in the browser — a
 * lesson this project learned the hard way, recorded in `tasks/lessons.md` under
 * "a static audit is not a test".
 */
class ClientFilterWiringTest extends TestCase
{
    /**
     * screen => [the input's id, the selector its items are matched by]
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function screens(): array
    {
        return [
            'pricing' => ['priceFilterInput', '#price-grid tbody tr'],
            'laundry_service' => ['serviceFilterInput', '[data-filter-item]'],
            'laundry_zone' => ['zoneFilterInput', '[data-filter-item]'],
            'roles' => ['permissionFilterInput', '.permission-row'],
            'time_slot' => ['slotFilterInput', '.stack-row'],
        ];
    }

    private function source(string $screen): string
    {
        $path = dirname(__DIR__, 3)."/resources/views/admin/{$screen}/index.blade.php";

        $this->assertFileExists($path, "{$screen}'s index view is missing");

        return (string) file_get_contents($path);
    }

    #[Test]
    public function each_screen_has_a_filter_box_wired_to_the_helper(): void
    {
        $problems = [];

        foreach ($this->screens() as $screen => [$inputId, $itemSelector]) {
            $source = $this->source($screen);

            if (! str_contains($source, 'id="'.$inputId.'"')) {
                $problems[] = "{$screen}: no input with id {$inputId}";
            }

            if (! str_contains($source, 'setupClientFilter(')) {
                $problems[] = "{$screen}: never calls setupClientFilter()";
            }

            if (! str_contains($source, "inputSelector: '#{$inputId}'")) {
                $problems[] = "{$screen}: setupClientFilter is not pointed at #{$inputId}";
            }

            if (! str_contains($source, $itemSelector)) {
                $problems[] = "{$screen}: does not reference its item selector ({$itemSelector})";
            }
        }

        $this->assertSame([], $problems, implode("\n  ", $problems));
    }

    #[Test]
    public function the_wiring_sits_in_a_scripts_stack(): void
    {
        // `layouts.main` renders `@stack('scripts')`. A `<script>` written
        // anywhere else in the view lands before jQuery and the helper are
        // defined, and fails silently.
        $problems = [];

        foreach (array_keys($this->screens()) as $screen) {
            $source = $this->source($screen);

            if (! str_contains($source, "@push('scripts')")) {
                $problems[] = "{$screen}: the filter script is not in a @push('scripts') block";
            }
        }

        $this->assertSame([], $problems, implode("\n  ", $problems));
    }

    #[Test]
    public function no_client_filtered_screen_pretends_to_have_a_search_endpoint(): void
    {
        // The distinction is the whole point. These five must not gain a
        // `setupAjaxSearch` call: it would fetch a re-rendered fragment of a
        // form whose un-rendered inputs are then dropped on save.
        $problems = [];

        foreach (array_keys($this->screens()) as $screen) {
            if (str_contains($this->source($screen), 'setupAjaxSearch(')) {
                $problems[] = "{$screen}: uses setupAjaxSearch, but its form posts a whole grid — "
                    .'a partial re-render would blank the cells it did not draw';
            }
        }

        $this->assertSame([], $problems, implode("\n  ", $problems));
    }

    #[Test]
    public function the_helper_exists_and_handles_all_three_grouping_shapes(): void
    {
        $js = (string) file_get_contents(
            dirname(__DIR__, 3).'/resources/views/layouts/footer_script.blade.php'
        );

        $this->assertStringContainsString('function setupClientFilter(', $js);

        // Flat rows, wrapped groups that collapse when empty, and heading rows
        // that are siblings of their items — the price grid's category rows.
        foreach (['itemSelector', 'groupSelector', 'siblingHeadingSelector'] as $option) {
            $this->assertStringContainsString(
                'config.'.$option,
                $js,
                "setupClientFilter no longer reads {$option}, which one of the five screens depends on"
            );
        }

        // Restoring `''` rather than a guessed display value: these rows are
        // variously table-row and flex, and Bootstrap sets display on some.
        $this->assertStringContainsString("style.display = match ? '' : 'none'", $js);
    }
}
