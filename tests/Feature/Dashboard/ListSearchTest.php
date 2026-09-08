<?php

namespace Tests\Feature\Dashboard;

use App\Modules\City\Models\City;
use App\Modules\Country\Models\Country;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The `Searchable` scope behind every list screen's search box.
 *
 * ## Read this before trusting a green run here
 *
 * The bug this file exists for **cannot fail on this suite's database.**
 *
 * Seven translatable columns are MySQL `json` (`cities.name`, `zones.name`,
 * `services.name`, `items.name`, `item_categories.name`, `laundries.name`,
 * `coupons.name`) rather than the `text` CLAUDE.md documents. A MySQL `json`
 * column has no character set and no collation, so it compares as binary and
 * `LIKE` against it is **case-sensitive** — searching `c` on Cities returned
 * nothing while `C` returned Cairo.
 *
 * PHPUnit runs on in-memory SQLite, where `json` is just `text` and `LIKE` is
 * case-insensitive for ASCII by default. So the original, broken scope passes
 * every behavioural assertion below. That is precisely the trap CLAUDE.md warns
 * about — "anything relying on MySQL-only SQL will pass in tests and fail in the
 * app" — and it is why this went unnoticed through 878 tests.
 *
 * Hence `the_scope_folds_case_in_sql`: it asserts the **generated SQL**, which
 * is the only part of this that a SQLite run can hold honest. The behavioural
 * tests are still worth keeping — they pin the contract, and they do fail on
 * MySQL if somebody reverts the scope — but the structural one is the guard.
 */
class ListSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seedCore();

        $country = Country::create([
            'name' => json_encode(['en' => 'Egypt', 'ar' => 'مصر'], JSON_UNESCAPED_UNICODE),
            'code' => 'EG',
            'phone_code' => '+20',
            'status' => 'active',
        ]);

        City::create([
            'name' => json_encode(['en' => 'Cairo', 'ar' => 'القاهرة'], JSON_UNESCAPED_UNICODE),
            'country_id' => $country->id,
            'status' => 'active',
        ]);

        City::create([
            'name' => json_encode(['en' => 'Alexandria', 'ar' => 'الإسكندرية'], JSON_UNESCAPED_UNICODE),
            'country_id' => $country->id,
            'status' => 'active',
        ]);
    }

    #[Test]
    public function the_scope_folds_case_in_sql(): void
    {
        // The structural guard. A revert to `orWhere($column, 'LIKE', ...)`
        // still passes every behavioural test on SQLite, and breaks seven
        // screens on MySQL — so the SQL shape is the thing worth pinning.
        $sql = City::search('cairo', ['name'])->toSql();

        $this->assertStringContainsStringIgnoringCase(
            'lower(cast(',
            $sql,
            'the search scope must fold case in SQL: a MySQL json column has no '
            .'collation, so a bare LIKE against it is case-sensitive'
        );
    }

    #[Test]
    public function the_term_is_lowercased_before_binding(): void
    {
        // Both sides have to be folded. Lowering only the column leaves an
        // uppercase needle that can never match a lowered haystack.
        $bindings = City::search('CAIRO', ['name'])->getBindings();

        $this->assertContains('%cairo%', $bindings);
    }

    #[Test]
    public function a_lowercase_term_matches_a_capitalised_name(): void
    {
        // The reported symptom, on a json column. Green on SQLite either way —
        // see the class docblock — and the reason the fix exists on MySQL.
        $this->assertSame(1, City::search('c', ['name'])->count());
        $this->assertSame(1, City::search('cairo', ['name'])->count());
        $this->assertSame(1, City::search('CAIRO', ['name'])->count());
        $this->assertSame(1, City::search('CaIrO', ['name'])->count());
    }

    #[Test]
    public function it_still_matches_arabic(): void
    {
        // Case folding must not disturb Arabic, which has no case. `mb_strtolower`
        // is used rather than `strtolower` so a multi-byte term is not mangled
        // byte-wise on the way to the binding.
        $this->assertSame(1, City::search('القاهرة', ['name'])->count());
        $this->assertSame(1, City::search('الإسكند', ['name'])->count());
    }

    #[Test]
    public function it_searches_every_named_column(): void
    {
        $this->assertSame(1, Country::search('egypt', ['name', 'code'])->count());
        $this->assertSame(1, Country::search('eg', ['name', 'code'])->count());
    }

    #[Test]
    public function a_blank_term_does_not_filter(): void
    {
        // The list screens call search() on first paint with no term, so this is
        // the "show everything" path, not an edge case.
        $this->assertSame(2, City::search(null, ['name'])->count());
        $this->assertSame(2, City::search('', ['name'])->count());
        $this->assertSame(2, City::search('   ', ['name'])->count());
    }

    #[Test]
    public function a_term_matching_nothing_returns_nothing(): void
    {
        // The counterpart to the case test: a fold that matched everything would
        // pass every assertion above and be just as broken.
        $this->assertSame(0, City::search('luxor', ['name'])->count());
    }

    #[Test]
    public function the_city_search_endpoint_finds_a_lowercase_term(): void
    {
        // End to end, because the scope being right is not the same as the
        // screen working — `lessons.md` has an entry about exactly that gap.
        // `X-Requested-With`, because every search() action is wrapped in
        // `if ($request->ajax())` and returns **null** otherwise — a bare GET to
        // the endpoint is an empty 200, not an error. jQuery sets the header for
        // free, which is why nothing has ever noticed.
        $response = $this->actingAs($this->superAdmin())
            ->get(
                route('admin.city.search', ['query' => 'cairo']),
                ['X-Requested-With' => 'XMLHttpRequest']
            )
            ->assertOk()
            ->assertJsonStructure(['table', 'pagination']);

        $this->assertStringContainsString('Cairo', $response->json('table'));
        $this->assertStringNotContainsString('No data found', $response->json('table'));
    }
}
