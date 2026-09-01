<?php

namespace Tests\Feature\Api;

use App\Modules\Item\Models\Item;
use App\Modules\ItemCategory\Models\ItemCategory;
use App\Modules\Pricing\Models\ItemPrice;
use App\Modules\Service\Models\Service;
use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\Zone\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P2 and P3 through the public endpoints — the ones guest mode uses before any
 * account exists.
 */
class PublicCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->seedGeo();

        $service = Service::create([
            'name' => json_encode(['en' => 'Wash & Iron', 'ar' => 'غسيل وكي'], JSON_UNESCAPED_UNICODE),
            'description' => json_encode(['en' => 'Full care', 'ar' => 'عناية متكاملة'], JSON_UNESCAPED_UNICODE),
            'pricing_mode' => 'per_item', 'duration_min' => 24, 'duration_max' => 48,
            'duration_unit' => 'hour', 'sort_order' => 1, 'status' => 'active',
        ]);

        Service::create([
            'name' => json_encode(['en' => 'Household Textiles', 'ar' => 'غسيل المفروشات'], JSON_UNESCAPED_UNICODE),
            'pricing_mode' => 'quote', 'duration_min' => 2, 'duration_max' => 4,
            'duration_unit' => 'day', 'sort_order' => 2, 'status' => 'active',
        ]);

        Service::create([
            'name' => json_encode(['en' => 'Retired', 'ar' => 'متوقفة'], JSON_UNESCAPED_UNICODE),
            'pricing_mode' => 'per_item', 'sort_order' => 3, 'status' => 'inactive',
        ]);

        $category = ItemCategory::create([
            'name' => json_encode(['en' => 'Shirts', 'ar' => 'القمصان'], JSON_UNESCAPED_UNICODE),
            'sort_order' => 1, 'status' => 'active',
        ]);

        $item = Item::create([
            'item_category_id' => $category->id,
            'name' => json_encode(['en' => 'Shirt on hanger', 'ar' => 'قميص على شماعة'], JSON_UNESCAPED_UNICODE),
            'sort_order' => 1, 'status' => 'active',
        ]);

        ItemPrice::create(['item_id' => $item->id, 'service_id' => $service->id, 'price' => 17]);

        TimeSlot::create(['start_time' => '09:00', 'end_time' => '12:00', 'applies_to' => 'both', 'status' => 'active']);
        TimeSlot::create(['start_time' => '12:00', 'end_time' => '15:00', 'applies_to' => 'pickup', 'status' => 'active']);
        TimeSlot::create(['start_time' => '15:00', 'end_time' => '18:00', 'applies_to' => 'delivery', 'status' => 'active']);
        TimeSlot::create(['start_time' => '20:00', 'end_time' => '22:00', 'applies_to' => 'both', 'status' => 'inactive']);
    }

    public function test_services_are_public_and_exclude_inactive_ones(): void
    {
        $response = $this->withHeaders($this->apiHeaders())->getJson('/api/v1/services');

        $response->assertOk()->assertJsonCount(2, 'data');

        $this->assertNotContains('Retired', array_column($response->json('data'), 'name'));
    }

    public function test_services_localize_on_the_lang_header(): void
    {
        $this->withHeaders($this->apiHeaders('ar'))->getJson('/api/v1/services')
            ->assertJsonPath('data.0.name', 'غسيل وكي');

        $this->withHeaders($this->apiHeaders('en'))->getJson('/api/v1/services')
            ->assertJsonPath('data.0.name', 'Wash & Iron');
    }

    public function test_the_duration_range_is_reported(): void
    {
        $this->withHeaders($this->apiHeaders())->getJson('/api/v1/services')
            ->assertJsonPath('data.0.duration', '24–48')
            ->assertJsonPath('data.0.duration_unit', 'hour');
    }

    public function test_the_catalogue_matches_the_designs_price(): void
    {
        $response = $this->withHeaders($this->apiHeaders('ar'))->getJson('/api/v1/catalog');

        $response->assertOk();

        $washIron = collect($response->json('data'))->firstWhere('pricing_mode', 'per_item');

        $this->assertSame('غسيل وكي', $washIron['name']);
        $this->assertSame('القمصان', $washIron['categories'][0]['name']);
        $this->assertSame('قميص على شماعة', $washIron['categories'][0]['items'][0]['name']);
        $this->assertSame('17.00', $washIron['categories'][0]['items'][0]['price']);
    }

    public function test_a_quoted_service_lists_no_prices(): void
    {
        $response = $this->withHeaders($this->apiHeaders())->getJson('/api/v1/catalog');

        $quoted = collect($response->json('data'))->firstWhere('pricing_mode', 'quote');

        $this->assertSame([], $quoted['categories']);
    }

    public function test_cities_come_back_with_their_zones(): void
    {
        $response = $this->withHeaders($this->apiHeaders('ar'))->getJson('/api/v1/cities');

        $response->assertOk()->assertJsonCount(1, 'data');

        $this->assertSame('القاهرة', $response->json('data.0.name'));
        $this->assertCount(2, $response->json('data.0.zones'));
        $this->assertSame('مدينة نصر', $response->json('data.0.zones.0.name'));
    }

    public function test_an_inactive_zone_is_not_offered(): void
    {
        Zone::first()->update(['status' => 'inactive']);

        $this->withHeaders($this->apiHeaders())->getJson('/api/v1/cities')
            ->assertJsonCount(1, 'data.0.zones');
    }

    public function test_time_slots_filter_by_purpose(): void
    {
        // A `both` window qualifies for either, an inactive one for neither.
        $this->withHeaders($this->apiHeaders())->getJson('/api/v1/time-slots?type=pickup')
            ->assertOk()->assertJsonCount(2, 'data');

        $this->withHeaders($this->apiHeaders())->getJson('/api/v1/time-slots?type=delivery')
            ->assertJsonCount(2, 'data');

        $this->withHeaders($this->apiHeaders())->getJson('/api/v1/time-slots')
            ->assertJsonCount(3, 'data');
    }

    public function test_time_slots_report_a_readable_window(): void
    {
        $response = $this->withHeaders($this->apiHeaders())->getJson('/api/v1/time-slots?type=pickup');

        $this->assertSame('09:00 AM – 12:00 PM', $response->json('data.0.label'));
        $this->assertSame('09:00', $response->json('data.0.start_time'));
        $this->assertNull($response->json('data.0.capacity'), 'null capacity means unlimited');
    }

    public function test_every_catalogue_endpoint_works_without_a_token(): void
    {
        // Guest mode browses before signing up.
        foreach (['/api/v1/services', '/api/v1/catalog', '/api/v1/cities', '/api/v1/time-slots'] as $path) {
            $this->withHeaders($this->apiHeaders())->getJson($path)->assertOk();
        }
    }
}
