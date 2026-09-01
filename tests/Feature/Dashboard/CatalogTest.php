<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Item\Models\Item;
use App\Modules\ItemCategory\Models\ItemCategory;
use App\Modules\Pricing\Models\ItemPrice;
use App\Modules\Pricing\Services\pricingService;
use App\Modules\Service\Models\Service;
use App\Modules\Service\Services\serviceCrudService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P2 — the price matrix and the rules that keep it meaningful.
 */
class CatalogTest extends TestCase
{
    use RefreshDatabase;

    private Service $washIron;

    private Service $quoted;

    private Item $shirt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();

        $this->washIron = Service::create([
            'name' => json_encode(['en' => 'Wash & Iron', 'ar' => 'غسيل وكي'], JSON_UNESCAPED_UNICODE),
            'pricing_mode' => 'per_item', 'duration_min' => 24, 'duration_max' => 48,
            'duration_unit' => 'hour', 'sort_order' => 1, 'status' => 'active',
        ]);

        $this->quoted = Service::create([
            'name' => json_encode(['en' => 'Household Textiles', 'ar' => 'غسيل المفروشات'], JSON_UNESCAPED_UNICODE),
            'pricing_mode' => 'quote', 'duration_min' => 2, 'duration_max' => 4,
            'duration_unit' => 'day', 'sort_order' => 2, 'status' => 'active',
        ]);

        $category = ItemCategory::create([
            'name' => json_encode(['en' => 'Shirts', 'ar' => 'القمصان'], JSON_UNESCAPED_UNICODE),
            'sort_order' => 1, 'status' => 'active',
        ]);

        $this->shirt = Item::create([
            'item_category_id' => $category->id,
            'name' => json_encode(['en' => 'Shirt on hanger', 'ar' => 'قميص على شماعة'], JSON_UNESCAPED_UNICODE),
            'sort_order' => 1, 'status' => 'active',
        ]);
    }

    public function test_a_quoted_service_is_absent_from_the_grid_columns(): void
    {
        $data = app(pricingService::class)->shredData();

        $ids = $data['services']->pluck('id')->all();

        $this->assertContains($this->washIron->id, $ids);
        $this->assertNotContains($this->quoted->id, $ids, 'a quoted service has no per-piece price');
        $this->assertSame(1, $data['quotedServices']->count());
    }

    public function test_saving_a_price_persists_it(): void
    {
        app(pricingService::class)->saveGrid([
            $this->shirt->id => [$this->washIron->id => '17.50'],
        ]);

        $this->assertSame('17.50', ItemPrice::first()->price);
    }

    public function test_a_blank_cell_deletes_the_row_rather_than_storing_zero(): void
    {
        // "not offered for this item" and "free" are different claims.
        app(pricingService::class)->saveGrid([$this->shirt->id => [$this->washIron->id => '17.00']]);
        $this->assertSame(1, ItemPrice::count());

        app(pricingService::class)->saveGrid([$this->shirt->id => [$this->washIron->id => '']]);

        $this->assertSame(0, ItemPrice::count());
    }

    public function test_a_price_for_a_quoted_service_is_rejected(): void
    {
        app(pricingService::class)->saveGrid([
            $this->shirt->id => [$this->quoted->id => '999'],
        ]);

        $this->assertSame(0, ItemPrice::count(), 'a quoted service must never carry a per-item price');
    }

    public function test_a_price_for_an_inactive_service_is_rejected(): void
    {
        $this->washIron->update(['status' => 'inactive']);

        app(pricingService::class)->saveGrid([
            $this->shirt->id => [$this->washIron->id => '17.00'],
        ]);

        $this->assertSame(0, ItemPrice::count());
    }

    public function test_scalar_junk_in_the_grid_payload_is_ignored(): void
    {
        // prices[5]=abc survives the prices.*.* rule as a scalar.
        app(pricingService::class)->saveGrid([$this->shirt->id => 'not-an-array']);

        $this->assertSame(0, ItemPrice::count());
    }

    public function test_switching_a_service_to_quoted_clears_its_prices(): void
    {
        app(pricingService::class)->saveGrid([$this->shirt->id => [$this->washIron->id => '17.00']]);
        $this->assertSame(1, ItemPrice::count());

        app(serviceCrudService::class)->updateRecord([
            'id' => $this->washIron->id,
            'pricing_mode' => 'quote',
        ]);

        $this->assertSame(0, ItemPrice::count(), 'orphan prices must not survive the switch');
    }

    public function test_the_unique_constraint_prevents_duplicate_cells(): void
    {
        ItemPrice::create(['item_id' => $this->shirt->id, 'service_id' => $this->washIron->id, 'price' => 17]);

        $this->expectException(UniqueConstraintViolationException::class);

        ItemPrice::create(['item_id' => $this->shirt->id, 'service_id' => $this->washIron->id, 'price' => 19]);
    }

    public function test_the_duration_label_matches_the_design(): void
    {
        $this->assertSame('24–48', $this->washIron->durationLabel());

        // A single value collapses rather than reading "24–24".
        $this->washIron->update(['duration_min' => 24, 'duration_max' => 24]);
        $this->assertSame('24', $this->washIron->fresh()->durationLabel());
    }

    public function test_the_public_catalogue_omits_quoted_services_prices(): void
    {
        app(pricingService::class)->saveGrid([$this->shirt->id => [$this->washIron->id => '17.00']]);

        $catalog = app(pricingService::class)->publicCatalog();

        $washIron = collect($catalog)->firstWhere('pricing_mode', 'per_item');
        $quoted = collect($catalog)->firstWhere('pricing_mode', 'quote');

        $this->assertNotEmpty($washIron['categories']);
        $this->assertSame([], $quoted['categories'], 'a quoted service lists no prices');
    }

    public function test_arabic_names_store_without_unicode_escapes(): void
    {
        $raw = DB::table('services')->where('id', $this->washIron->id)->value('name');

        $this->assertStringNotContainsString('\u', $raw);
        $this->assertSame('غسيل وكي', $this->washIron->name->ar);
    }
}
