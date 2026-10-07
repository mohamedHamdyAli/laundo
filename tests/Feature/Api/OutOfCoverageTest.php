<?php

namespace Tests\Feature\Api;

use App\Modules\Address\Models\Address;
use App\Modules\Order\Models\Order;
use App\Modules\User\Models\User;
use App\Modules\Zone\Models\CoverageRequest;
use App\Modules\Zone\Services\zoneCrudService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An order to or from an address we do not serve is refused with
 * `out_of_coverage` (the app team's ask, the owner's say-so, 2026-10-07).
 *
 * «Not served» means **no zone**: the pin is outside every zone drawn on the
 * map. A zone no laundry has claimed yet is still accepted and waits for an
 * operator — that is a gap in our setup, not in where the customer lives, and
 * the test for it is here so nobody widens the refusal by accident.
 *
 * The app keys on `key`, not on `msg`: the string is the contract.
 */
class OutOfCoverageTest extends TestCase
{
    use RefreshDatabase;

    private array $geo;

    private array $catalog;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        $this->catalog = $this->seedCatalog();
        $this->customer = $this->customer('+201055550201');
    }

    /** An address whose pin is outside every zone — what `ZoneLocator` saves as no zone. */
    private function nowhere(string $label = 'Giza'): Address
    {
        return Address::create([
            'user_id' => $this->customer->id,
            'label' => $label,
            'city_id' => $this->geo['city']->id,
            'zone_id' => null,
            'street' => '5 شارع الهرم',
            'building' => '12',
            'lat' => 30.0100,
            'lng' => 31.2000,
            'is_default' => false,
        ]);
    }

    private function covered(): Address
    {
        return $this->addressFor($this->customer, $this->geo['zones'][0]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function send(string $uri, Address $pickup, array $extra = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->customer);

        return $this->withHeaders($this->apiHeaders())->postJson($uri, [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $pickup->id,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 1]],
            'accepts_review_terms' => true,
        ] + $extra);
    }

    // ------------------------------------------------------------- refused

    #[Test]
    public function an_order_from_an_address_in_no_zone_is_refused_with_the_key_the_app_reads(): void
    {
        $this->send('/api/v1/orders', $this->nowhere())
            ->assertStatus(422)
            ->assertJsonPath('key', 'out_of_coverage')
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('code', 422)
            ->assertJsonPath('msg', __('Sorry, the service is not available in your area yet.'))
            ->assertJsonPath('errors.pickup_address_id.0', __('This address is outside the areas we serve.'))
            ->assertJsonMissingPath('errors.delivery_address_id');

        // Nothing was made: no order, so no payment, no slot taken, no legs.
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    #[Test]
    public function the_quote_refuses_it_the_same_way_so_the_review_screen_can_stop_them(): void
    {
        $this->send('/api/v1/orders/quote', $this->nowhere())
            ->assertStatus(422)
            ->assertJsonPath('key', 'out_of_coverage')
            ->assertJsonStructure(['errors' => ['pickup_address_id']]);
    }

    #[Test]
    public function a_delivery_to_an_address_in_no_zone_is_refused_and_named(): void
    {
        $this->send('/api/v1/orders', $this->covered(), ['delivery_address_id' => $this->nowhere()->id])
            ->assertStatus(422)
            ->assertJsonPath('key', 'out_of_coverage')
            ->assertJsonMissingPath('errors.pickup_address_id')
            ->assertJsonStructure(['errors' => ['delivery_address_id']]);

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    #[Test]
    public function both_ends_outside_name_both_fields(): void
    {
        $this->send('/api/v1/orders', $this->nowhere('A'), ['delivery_address_id' => $this->nowhere('B')->id])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['pickup_address_id', 'delivery_address_id']]);
    }

    #[Test]
    public function the_same_address_both_ways_is_named_once(): void
    {
        $address = $this->nowhere();

        $this->send('/api/v1/orders', $address, ['delivery_address_id' => $address->id])
            ->assertStatus(422)
            ->assertJsonMissingPath('errors.delivery_address_id');
    }

    // -------------------------------------------------------- still accepted

    #[Test]
    public function a_zone_no_laundry_covers_yet_is_still_accepted_for_an_operator(): void
    {
        // The line the owner drew: our own setup being incomplete is not a
        // reason to turn a customer away.
        $this->send('/api/v1/orders', $this->covered())->assertCreated();

        $this->assertNull(Order::withoutGlobalScopes()->firstOrFail()->laundry_id);
        $this->assertSame(0, CoverageRequest::count());
    }

    // ------------------------------------------------------------- recorded

    #[Test]
    public function each_refusal_is_written_down_for_somebody_to_ring(): void
    {
        $address = $this->nowhere();

        $this->send('/api/v1/orders/quote', $address)->assertStatus(422);

        $row = CoverageRequest::firstOrFail();
        $this->assertSame($this->customer->id, $row->user_id);
        $this->assertSame($address->id, $row->address_id);
        $this->assertSame(1, $row->attempts);
        $this->assertSame('5 شارع الهرم، 12', $row->address_line);
        $this->assertEquals(30.01, (float) $row->lat);

        // Again, and through the order this time: the same row, counted.
        $this->travel(5)->minutes();
        $this->send('/api/v1/orders', $address)->assertStatus(422);

        $this->assertSame(1, CoverageRequest::count());
        $row->refresh();
        $this->assertSame(2, $row->attempts);
        $this->assertTrue($row->last_attempt_at->greaterThan($row->created_at));
    }

    #[Test]
    public function a_new_refusal_puts_an_already_rung_customer_back_on_the_list(): void
    {
        // Rung once with «not yet»; then they try again and the app promises
        // again «we will contact you». That promise needs somebody to keep it.
        $address = $this->nowhere();
        $this->send('/api/v1/orders', $address)->assertStatus(422);
        CoverageRequest::firstOrFail()->forceFill(['contacted_at' => now(), 'contacted_by' => $this->customer->id])->save();

        $this->send('/api/v1/orders', $address)->assertStatus(422);

        $row = CoverageRequest::firstOrFail();
        $this->assertFalse($row->isContacted());
        $this->assertNull($row->contacted_by);
    }

    #[Test]
    public function a_repeat_schedule_on_an_address_in_no_zone_is_refused_the_same_way(): void
    {
        // Otherwise every cycle would ask «محتاج تغسل؟» and refuse the answer.
        $address = $this->nowhere();
        Sanctum::actingAs($this->customer);

        $this->withHeaders($this->apiHeaders())->postJson('/api/v1/recurrences', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $address->id,
            'frequency' => 'weekly',
            'day_of_week' => 3,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('key', 'out_of_coverage')
            ->assertJsonStructure(['errors' => ['pickup_address_id']]);

        $this->assertDatabaseCount('order_recurrences', 0);
        $this->assertSame(1, CoverageRequest::count());

        // And a covered address still saves one.
        $this->withHeaders($this->apiHeaders())->postJson('/api/v1/recurrences', [
            'service_id' => $this->catalog['service']->id,
            'pickup_address_id' => $this->covered()->id,
            'frequency' => 'weekly',
            'day_of_week' => 3,
            'items' => [['item_id' => $this->catalog['items'][0]->id, 'qty' => 2]],
        ])->assertCreated();
    }

    #[Test]
    public function the_copy_survives_the_customer_deleting_the_address(): void
    {
        $address = $this->nowhere();
        $this->send('/api/v1/orders', $address)->assertStatus(422);

        $address->delete();

        $row = CoverageRequest::firstOrFail();
        $this->assertNull($row->address_id);
        $this->assertSame('5 شارع الهرم، 12', $row->address_line);
    }

    #[Test]
    public function both_ends_outside_are_two_rows(): void
    {
        $this->send('/api/v1/orders', $this->nowhere('A'), ['delivery_address_id' => $this->nowhere('B')->id])
            ->assertStatus(422);

        $this->assertSame(2, CoverageRequest::count());
    }

    #[Test]
    public function a_failure_to_write_it_down_does_not_change_what_the_customer_is_told(): void
    {
        // The note is ours; the refusal is theirs. Losing the table must not
        // turn «we do not serve your area» into «something went wrong».
        Schema::drop('coverage_requests');

        $this->send('/api/v1/orders', $this->nowhere())
            ->assertStatus(422)
            ->assertJsonPath('key', 'out_of_coverage');
    }

    // ------------------------------------------------------------ is_covered

    #[Test]
    public function every_address_says_whether_it_is_covered(): void
    {
        $inside = $this->covered();
        $outside = $this->nowhere();
        Sanctum::actingAs($this->customer);

        $flags = collect(
            $this->withHeaders($this->apiHeaders())->getJson('/api/v1/addresses')->assertOk()->json('data')
        )->pluck('is_covered', 'id');

        $this->assertTrue($flags[$inside->id]);
        $this->assertFalse($flags[$outside->id]);
    }

    #[Test]
    public function a_pin_saved_outside_every_drawn_zone_comes_back_not_covered_and_is_refused(): void
    {
        // End to end through the map: the zone is drawn, the app picks it from
        // the list, the pin is across the river — so the server says no zone.
        $nasr = $this->geo['zones'][0];
        app(zoneCrudService::class)->updateRecord(['id' => $nasr->id, 'boundary' => json_encode(
            [[30.08, 31.30], [30.08, 31.36], [30.03, 31.36], [30.03, 31.30]]
        )]);

        Sanctum::actingAs($this->customer);
        $saved = $this->withHeaders($this->apiHeaders())->postJson('/api/v1/addresses', [
            'label' => 'Home',
            'city_id' => $this->geo['city']->id,
            'zone_id' => $nasr->id,
            'street' => '12 Street',
            'lat' => 30.01,
            'lng' => 31.20,
        ])->assertCreated()->assertJsonPath('data.is_covered', false)->json('data');

        $this->send('/api/v1/orders', Address::findOrFail($saved['id']))
            ->assertStatus(422)
            ->assertJsonPath('key', 'out_of_coverage');
    }
}
