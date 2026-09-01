<?php

namespace Tests\Feature\Api;

use App\Modules\City\Models\City;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Narrowing the two lookup endpoints.
 *
 * Both were all-or-nothing: the whole price grid, and every city with every zone.
 * That is right for the screens they were built for — «الاسعار» compares services
 * against each other, and the address form fills both dropdowns at once — and
 * wasteful for the callers that already know which service or which city they
 * mean.
 *
 * The rule worth pinning down is what a filter that matches nothing does. Silently
 * widening it is how an app ends up showing a dry-cleaning price on a
 * wash-and-iron screen.
 */
class CatalogFiltersTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $catalog;

    /** @var array<string, mixed> */
    private array $geo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
    }

    // ------------------------------------------------------------- /catalog

    #[Test]
    public function unfiltered_it_is_still_the_whole_grid(): void
    {
        $data = $this->getJson('/api/v1/catalog')->assertOk()->json('data');

        // Both services: the per-item one and the quoted one, which carries no
        // prices by design and still has to appear on the pricing screen.
        $this->assertCount(2, $data);
    }

    #[Test]
    public function one_service_returns_one_column(): void
    {
        $id = $this->catalog['service']->id;

        $data = $this->getJson('/api/v1/catalog?service_id='.$id)->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame($id, $data[0]['id']);
        $this->assertNotEmpty($data[0]['categories']);
    }

    #[Test]
    public function a_quoted_service_narrows_to_itself_with_no_prices(): void
    {
        $id = $this->catalog['quoted']->id;

        $data = $this->getJson('/api/v1/catalog?service_id='.$id)->assertOk()->json('data');

        $this->assertCount(1, $data);
        // «تنظيف جاف» has no catalogue prices at all — it is costed after the
        // pieces are inspected — so an empty category list is the right answer,
        // not a missing service.
        $this->assertSame([], $data[0]['categories']);
    }

    #[Test]
    public function one_category_narrows_every_service_to_it(): void
    {
        $categoryId = $this->catalog['items'][0]->item_category_id;

        $data = $this->getJson('/api/v1/catalog?category_id='.$categoryId)->assertOk()->json('data');

        foreach ($data as $service) {
            foreach ($service['categories'] as $category) {
                $this->assertSame($categoryId, $category['id']);
            }
        }
    }

    #[Test]
    public function both_filters_together_narrow_to_one_cell(): void
    {
        $service = $this->catalog['service']->id;
        $category = $this->catalog['items'][0]->item_category_id;

        $data = $this->getJson("/api/v1/catalog?service_id={$service}&category_id={$category}")
            ->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertCount(1, $data[0]['categories']);
    }

    #[Test]
    public function a_service_that_does_not_exist_is_refused_not_ignored(): void
    {
        // Falling back to the whole grid would put every service's prices on a
        // screen that asked for one.
        $this->getJson('/api/v1/catalog?service_id=999999')->assertStatus(422);
    }

    // -------------------------------------------------------------- /cities

    #[Test]
    public function unfiltered_it_is_every_city_with_its_zones(): void
    {
        $data = $this->getJson('/api/v1/cities')->assertOk()->json('data');

        $this->assertNotEmpty($data);
        $this->assertArrayHasKey('zones', $data[0]);
    }

    #[Test]
    public function one_city_returns_only_its_zones(): void
    {
        $city = City::where('status', 'active')->firstOrFail();

        $data = $this->getJson('/api/v1/cities?city_id='.$city->id)->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame($city->id, $data[0]['id']);
        $this->assertNotEmpty($data[0]['zones']);
    }

    #[Test]
    public function a_city_that_does_not_exist_is_refused(): void
    {
        $this->getJson('/api/v1/cities?city_id=999999')->assertStatus(422);
    }

    #[Test]
    public function an_inactive_city_is_still_hidden_when_asked_for_by_id(): void
    {
        $city = City::where('status', 'active')->firstOrFail();
        $city->update(['status' => 'inactive']);

        // The id exists, so validation passes — and the status filter still has
        // to hold, or `city_id` becomes a way to reach a city we stopped serving.
        $this->assertSame([], $this->getJson('/api/v1/cities?city_id='.$city->id)
            ->assertOk()->json('data'));
    }

    #[Test]
    public function neither_endpoint_needs_a_token(): void
    {
        // Guest mode browses prices and areas before anybody signs up, and adding
        // a filter must not quietly close that door.
        $this->getJson('/api/v1/catalog?service_id='.$this->catalog['service']->id)->assertOk();
        $this->getJson('/api/v1/cities?city_id='.City::value('id'))->assertOk();
    }
}
