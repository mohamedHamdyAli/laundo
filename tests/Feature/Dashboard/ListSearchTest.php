<?php

namespace Tests\Feature\Dashboard;

use App\Modules\City\Models\City;
use App\Modules\Country\Models\Country;
use App\Modules\Service\Models\Service;
use App\Modules\Service\Repositories\ServiceRepository;
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
    public function it_searches_across_a_relation(): void
    {
        // The owner's requirement: anything a list screen shows must be
        // searchable, and most screens show at least one column from another
        // table — a driver's vehicle, a laundry's city, a staff member's role.
        // Neither city's own name contains "egypt" — only their country's does.
        $this->assertSame(2, City::search('egypt', ['name', 'country.name'])->count());
        $this->assertSame(0, City::search('egypt', ['name'])->count());
    }

    #[Test]
    public function a_relation_search_does_not_match_every_row(): void
    {
        // The regression that made this worth a test. `whereHas` puts the
        // relation's own join condition in the same subquery, so an `OR` inside
        // the closure escapes it and `EXISTS` becomes true for **every** parent
        // row. Measured on real data: zones matching city "cairo" came back as
        // all 25 instead of Cairo's 15, and items matching category "shirts" as
        // all 10 instead of 6.
        //
        // A term that exists in the relation table but belongs to a *different*
        // parent is the only shape that catches it — which is why there is a
        // second country here with a city of its own.
        $other = Country::create([
            'name' => json_encode(['en' => 'Jordan', 'ar' => 'الأردن'], JSON_UNESCAPED_UNICODE),
            'code' => 'JO',
            'phone_code' => '+962',
            'status' => 'active',
        ]);

        City::create([
            'name' => json_encode(['en' => 'Amman', 'ar' => 'عمّان'], JSON_UNESCAPED_UNICODE),
            'country_id' => $other->id,
            'status' => 'active',
        ]);

        // Three cities now; only the two Egyptian ones may match.
        $this->assertSame(3, City::count());
        $this->assertSame(2, City::search('egypt', ['name', 'country.name'])->count());
        $this->assertSame(1, City::search('jordan', ['name', 'country.name'])->count());
    }

    #[Test]
    public function a_relation_term_matching_nothing_returns_nothing(): void
    {
        // The counterpart: a relation clause that matched everything would pass
        // the assertions above as easily as a correct one.
        $this->assertSame(0, City::search('atlantis', ['name', 'country.name'])->count());
    }

    #[Test]
    public function a_relation_search_is_case_insensitive_too(): void
    {
        foreach (['egypt', 'Egypt', 'EGYPT', 'egY'] as $term) {
            $this->assertSame(
                2,
                City::search($term, ['name', 'country.name'])->count(),
                "searching the country name as '{$term}' should find both Egyptian cities"
            );
        }
    }

    #[Test]
    public function a_composed_cell_can_be_pasted_into_the_search_box(): void
    {
        // Reported as «Duration مش بيفلتر بيه». The Services screen's DURATION
        // column reads "24–48 hours", and no column holds that string:
        // `durationLabel()` composes the range from two columns and the unit word
        // comes from the Web File. So the cell is a sentence assembled from three
        // sources, and pasting it found nothing.
        $service = Service::create([
            'name' => json_encode(['en' => 'Wash & Iron', 'ar' => 'غسيل وكي'], JSON_UNESCAPED_UNICODE),
            'pricing_mode' => 'per_item',
            'duration_min' => 24,
            'duration_max' => 48,
            'duration_unit' => 'hour',
            'sort_order' => 1,
            'status' => 'active',
        ]);

        $repository = app(ServiceRepository::class);

        // The cell exactly as rendered, with the en dash `durationLabel()` uses.
        $this->assertSame(1, $repository->search('24–48 hours', 20)->total());

        // And the same thing typed by hand, with a plain hyphen — nobody has an
        // en dash on their keyboard.
        $this->assertSame(1, $repository->search('24-48 hours', 20)->total());

        // The range on its own, either way round.
        $this->assertSame(1, $repository->search('24–48', 20)->total());
        $this->assertSame(1, $repository->search('24-48', 20)->total());

        // Either bound alone.
        $this->assertSame(1, $repository->search('48', 20)->total());

        $this->assertSame($service->id, $repository->search('24–48 hours', 20)->first()->id);
    }

    #[Test]
    public function the_unit_word_matches_in_the_form_the_table_shows_it(): void
    {
        Service::create([
            'name' => json_encode(['en' => 'Iron Only', 'ar' => 'كي فقط'], JSON_UNESCAPED_UNICODE),
            'pricing_mode' => 'per_item',
            'duration_min' => 24, 'duration_max' => 24, 'duration_unit' => 'hour',
            'sort_order' => 1, 'status' => 'active',
        ]);
        Service::create([
            'name' => json_encode(['en' => 'Household', 'ar' => 'مفروشات'], JSON_UNESCAPED_UNICODE),
            'pricing_mode' => 'quote',
            'duration_min' => 2, 'duration_max' => 4, 'duration_unit' => 'day',
            'sort_order' => 2, 'status' => 'active',
        ]);

        $repository = app(ServiceRepository::class);

        // The column stores the singular; the table renders the plural. Searching
        // the word somebody can actually see found nothing before.
        $this->assertSame(1, $repository->search('hours', 20)->total());
        $this->assertSame(1, $repository->search('hour', 20)->total());
        $this->assertSame(1, $repository->search('days', 20)->total());
        $this->assertSame(1, $repository->search('day', 20)->total());
    }

    #[Test]
    public function a_bare_unit_word_is_not_stripped_into_an_empty_term(): void
    {
        // The condition that makes the stripping safe. "24-48 hours" is a range
        // plus noise and the noise has to go; a bare "hours" is somebody
        // searching the unit itself, and stripping it would leave an empty term —
        // which matches every row. So only a term containing a digit is stripped.
        Service::create([
            'name' => json_encode(['en' => 'Iron Only', 'ar' => 'كي فقط'], JSON_UNESCAPED_UNICODE),
            'pricing_mode' => 'per_item',
            'duration_min' => 24, 'duration_max' => 24, 'duration_unit' => 'hour',
            'sort_order' => 1, 'status' => 'active',
        ]);
        Service::create([
            'name' => json_encode(['en' => 'Household', 'ar' => 'مفروشات'], JSON_UNESCAPED_UNICODE),
            'pricing_mode' => 'quote',
            'duration_min' => 2, 'duration_max' => 4, 'duration_unit' => 'day',
            'sort_order' => 2, 'status' => 'active',
        ]);

        $repository = app(ServiceRepository::class);

        // Two services exist; "hours" must find the one, not both.
        $this->assertSame(2, Service::count());
        $this->assertSame(1, $repository->search('hours', 20)->total());

        // And nonsense still finds nothing, which a match-everything bug would not.
        $this->assertSame(0, $repository->search('zzzznotathing', 20)->total());
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
