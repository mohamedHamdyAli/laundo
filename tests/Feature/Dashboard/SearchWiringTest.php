<?php

namespace Tests\Feature\Dashboard;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every list screen's `setupAjaxSearch()` selectors must match an element that
 * exists in its own view.
 *
 * Two screens shipped with a selector that matched nothing — `item_category`
 * asked for `#itemcategory-table-body` while its container is
 * `#item_category-table-body`, and `laundry_staff` asked for
 * `#staff-table-body` against `#laundry_staff-table-body`.
 *
 * The failure mode is the nasty kind: **nothing errors.** jQuery resolves the
 * bad selector to an empty set, `tableBody.html(response.table)` writes to
 * nowhere, and the screen keeps showing the unfiltered list. Typing a term
 * appears to do nothing at all. Worse, the *error* branch writes to the same
 * empty set — so those two screens could not even display "Error during search"
 * while every other screen was displaying it, which is why they read as a
 * different bug.
 *
 * A static check rather than a browser test, because it is exhaustive and
 * costs nothing: it covers all 33 index views on every run, where driving each
 * one in Playwright covers whichever ones somebody remembered to list.
 */
class SearchWiringTest extends TestCase
{
    /** @return list<array{module: string, view: string, source: string}> */
    private function indexViews(): array
    {
        $root = dirname(__DIR__, 3).'/resources/views/admin';
        $views = [];

        foreach (glob($root.'/*/index.blade.php') ?: [] as $path) {
            $views[] = [
                'module' => basename(dirname($path)),
                'view' => $path,
                'source' => (string) file_get_contents($path),
            ];
        }

        return $views;
    }

    #[Test]
    public function every_search_selector_matches_an_element_in_its_own_view(): void
    {
        $problems = [];

        foreach ($this->indexViews() as $view) {
            preg_match_all('/id="([^"]+)"/', $view['source'], $idMatches);
            $ids = $idMatches[1];

            foreach (['inputSelector', 'tableBodySelector', 'paginationWrapperSelector'] as $key) {
                if (preg_match('/'.$key.":\s*['\"]([^'\"]+)['\"]/", $view['source'], $m) !== 1) {
                    continue;
                }

                $selector = $m[1];

                // Only id selectors can be checked this way; a class selector is
                // legitimate and resolved differently.
                if (! str_starts_with($selector, '#')) {
                    continue;
                }

                if (! in_array(substr($selector, 1), $ids, true)) {
                    $problems[] = "{$view['module']}: {$key} => {$selector} matches no element in its own view";
                }
            }
        }

        $this->assertSame([], $problems, implode("\n  ", $problems));
    }

    #[Test]
    public function a_screen_with_a_search_box_actually_wires_it_up(): void
    {
        // The other half: a box with no handler behind it looks identical to a
        // handler with a broken selector, and both look like "search is broken".
        $problems = [];

        foreach ($this->indexViews() as $view) {
            $hasInput = preg_match('/id="[^"]*SearchInput"/', $view['source']) === 1;
            $hasSetup = str_contains($view['source'], 'setupAjaxSearch(');

            if ($hasInput && ! $hasSetup) {
                $problems[] = "{$view['module']}: has a search input and never calls setupAjaxSearch()";
            }

            if ($hasSetup && ! $hasInput) {
                $problems[] = "{$view['module']}: calls setupAjaxSearch() with no search input to bind";
            }
        }

        $this->assertSame([], $problems, implode("\n  ", $problems));
    }

    #[Test]
    public function a_wired_screen_names_a_search_route_that_exists(): void
    {
        // `route()` would throw at render time on a bad name, so this is mostly a
        // guard against a copy-pasted module name pointing at another module's
        // endpoint — which fails silently by returning the wrong rows.
        $problems = [];

        foreach ($this->indexViews() as $view) {
            if (preg_match("/url:\s*[\"']\{\{\s*route\('([^']+)'/", $view['source'], $m) !== 1) {
                continue;
            }

            $routeName = $m[1];
            $module = $view['module'];

            // admin.{module}.search — the module in the route name has to be this
            // view's own module.
            if (! str_contains($routeName, $module)) {
                $problems[] = "{$module}: wires url to {$routeName}, which is another module's endpoint";
            }
        }

        $this->assertSame([], $problems, implode("\n  ", $problems));
    }
}
