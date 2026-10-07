<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Address\Models\Address;
use App\Modules\Order\Enums\TaskType;
use App\Modules\Order\Models\OrderTask;
use App\Modules\Order\Services\OrderService;
use App\Modules\User\Models\User;
use App\Modules\Zone\Models\Zone;
use App\Modules\Zone\Services\zoneCrudService;
use App\Modules\Zone\Services\ZoneLocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Zones drawn on the map (the owner's request, 2026-09-29).
 *
 * The drawing decides which zone a pin is in, whatever the app picked; a zone
 * not drawn yet still works the old way; a pin in no drawn zone is in no zone
 * and its order waits for an operator; drawings may not overlap; a driver is
 * only handed trips inside their zones, which follows from the address's zone
 * being where its pin is.
 *
 * The drawings are boxes around the fixture zones' names — Nasr City to the
 * east of Cairo, Maadi to the south — so the pins read like real ones.
 */
class ZoneBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private array $geo;

    private Zone $nasr;

    private Zone $maadi;

    /** Nasr City, roughly: a box, corners [lat, lng]. */
    private const NASR = [[30.08, 31.30], [30.08, 31.36], [30.03, 31.36], [30.03, 31.30]];

    /** Maadi, roughly — nowhere near Nasr City. */
    private const MAADI = [[29.98, 31.24], [29.98, 31.29], [29.94, 31.29], [29.94, 31.24]];

    /** Inside NASR. */
    private const IN_NASR = [30.05, 31.33];

    /** Inside MAADI. */
    private const IN_MAADI = [29.96, 31.26];

    /** Inside neither — Giza, across the river. */
    private const NOWHERE = [30.01, 31.20];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        [$this->nasr, $this->maadi] = $this->geo['zones'];
    }

    private function draw(Zone $zone, array $points): Zone
    {
        app(zoneCrudService::class)->updateRecord(['id' => $zone->id, 'boundary' => json_encode($points)]);

        return $zone->fresh();
    }

    private function saveAddress(User $customer, array $pin, ?int $zoneId): array
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/addresses', [
            'label' => 'Home',
            'city_id' => $this->geo['city']->id,
            'zone_id' => $zoneId,
            'street' => '12 Street',
            'lat' => $pin[0],
            'lng' => $pin[1],
        ], $this->apiHeaders() + ['Authorization' => 'Bearer '.$customer->createToken('t')->plainTextToken])
            ->assertCreated()
            ->json('data');
    }

    // ------------------------------------------------------------ drawing

    #[Test]
    public function a_drawing_is_saved_from_the_zone_screen_with_its_box(): void
    {
        $this->actingAs($this->superAdmin())
            ->put(route('admin.zone.update', $this->nasr->id), ['boundary' => json_encode(self::NASR)])
            ->assertSessionHasNoErrors();

        $zone = $this->nasr->fresh();
        $this->assertSame(self::NASR, $zone->boundary);
        $this->assertTrue($zone->isDrawn());
        $this->assertEquals([30.03, 30.08, 31.30, 31.36], [(float) $zone->min_lat, (float) $zone->max_lat, (float) $zone->min_lng, (float) $zone->max_lng]);
    }

    #[Test]
    public function a_shape_with_no_single_inside_is_refused(): void
    {
        $this->actingAs($this->superAdmin())
            ->putJson(route('admin.zone.update', $this->nasr->id), [
                'boundary' => json_encode([[30.0, 31.0], [30.1, 31.1], [30.1, 31.0], [30.0, 31.1]]),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('boundary');

        $this->assertNull($this->nasr->fresh()->boundary);
    }

    #[Test]
    public function a_drawing_over_another_zone_is_refused_and_names_it(): void
    {
        $this->draw($this->nasr, self::NASR);

        $response = $this->actingAs($this->superAdmin())
            ->putJson(route('admin.zone.update', $this->maadi->id), [
                // Half over Nasr City.
                'boundary' => json_encode([[30.06, 31.34], [30.06, 31.40], [30.00, 31.40], [30.00, 31.34]]),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('boundary');

        $this->assertStringContainsString('Nasr City', $response->json('errors.boundary.0'));
        $this->assertNull($this->maadi->fresh()->boundary);
    }

    #[Test]
    public function a_neighbour_drawn_along_the_same_street_is_accepted(): void
    {
        $this->draw($this->nasr, self::NASR);

        $this->actingAs($this->superAdmin())
            ->putJson(route('admin.zone.update', $this->maadi->id), [
                'boundary' => json_encode([[30.08, 31.36], [30.08, 31.42], [30.03, 31.42], [30.03, 31.36]]),
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->maadi->fresh()->isDrawn());
    }

    #[Test]
    public function a_save_that_says_nothing_of_the_drawing_keeps_it(): void
    {
        // A spreadsheet row, or any caller that knows nothing of the map.
        $this->draw($this->nasr, self::NASR);

        app(zoneCrudService::class)->updateRecord(['id' => $this->nasr->id, 'price_per_km' => '7.50']);

        $this->assertSame(self::NASR, $this->nasr->fresh()->boundary);
    }

    #[Test]
    public function the_form_carries_the_map_and_the_neighbours(): void
    {
        $this->draw($this->maadi, self::MAADI);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.zone.edit', $this->nasr->id))
            ->assertOk()
            ->assertSee('id="zone-boundary"', false)
            ->assertSee('initZoneDrawer', false)
            ->assertSee(__('Not drawn yet: customers still pick this zone from the list until it is drawn.'));

        $this->actingAs($this->superAdmin())
            ->get(route('admin.zone.index'))
            ->assertOk()
            ->assertSee(__('The zones on the map'))
            ->assertSee(__('Drawn on the map'))
            ->assertSee(__('Not drawn yet'));
    }

    #[Test]
    public function both_zone_maps_carry_a_place_search_that_only_moves_the_view(): void
    {
        // The form (drawing) and the list's overview (read-only): the question
        // «where is this street» is asked on both. The overview is drawn only
        // once a zone is.
        $this->draw($this->maadi, self::MAADI);

        foreach ([route('admin.zone.edit', $this->nasr->id), route('admin.zone.index')] as $url) {
            $this->actingAs($this->superAdmin())
                ->get($url)
                ->assertOk()
                ->assertSee('class="map-picker-search" data-map-search-box', false)
                ->assertSee('window.attachPlaceSearch', false)
                ->assertSee(__('Search for a place, street or landmark'))
                // Its own answer when the search is down — there is no pin here
                // to «set by hand», which is the picker's wording.
                ->assertSee(__('The place search is unavailable right now. Move the map to the place yourself.'))
                ->assertDontSee(__('The place search is unavailable right now. Set the pin on the map instead.'));
        }
    }

    #[Test]
    public function the_form_offers_the_shape_tools_and_the_overview_does_not(): void
    {
        $this->draw($this->maadi, self::MAADI);

        $form = $this->actingAs($this->superAdmin())
            ->get(route('admin.zone.edit', $this->nasr->id))
            ->assertOk();

        foreach (['points', 'rectangle', 'circle', 'triangle', 'freehand'] as $mode) {
            $form->assertSee('data-zone-mode="'.$mode.'"', false);
        }
        $form->assertSee(__('Freehand'))
            ->assertSee(__('A shape replaces the drawing — Undo brings it back — and its corners can then be moved like any other.'));

        // The overview draws nothing, so it offers nothing to draw with.
        $this->actingAs($this->superAdmin())
            ->get(route('admin.zone.index'))
            ->assertOk()
            ->assertDontSee('data-zone-mode="rectangle"', false);
    }

    #[Test]
    public function a_circle_as_the_drawer_makes_it_is_a_zone_the_server_accepts(): void
    {
        // The drawer's own arithmetic, done here: 32 corners walked round a
        // centre on the sphere. The server knows nothing of shapes — it has to
        // take this ring as it takes any other.
        [$lat, $lng, $radius] = [30.055, 31.33, 1500.0];
        $dLat = ($radius / 6371000) * (180 / M_PI); // L.CRS.Earth.R
        $dLng = $dLat / cos(deg2rad($lat));
        $ring = [];
        for ($i = 0; $i < 32; $i++) {
            $angle = 2 * M_PI * $i / 32;
            $ring[] = [round($lat + $dLat * cos($angle), 7), round($lng + $dLng * sin($angle), 7)];
        }

        $this->actingAs($this->superAdmin())
            ->put(route('admin.zone.update', $this->nasr->id), ['boundary' => json_encode($ring)])
            ->assertSessionHasNoErrors();

        $this->assertCount(32, $this->nasr->fresh()->boundary);
        $this->assertSame($this->nasr->id, app(ZoneLocator::class)->zoneAt($lat, $lng)?->id);
        // Just outside the circle's edge, due east: not in it.
        $this->assertNull(app(ZoneLocator::class)->zoneAt($lat, $lng + $dLng * 1.05));
    }

    // ------------------------------------------------------------ addresses

    #[Test]
    public function a_pin_inside_a_drawn_zone_is_in_it_whatever_the_app_picked(): void
    {
        $this->draw($this->nasr, self::NASR);

        // The app sent Maadi; the pin is in Nasr City.
        $saved = $this->saveAddress($this->customer(), self::IN_NASR, $this->maadi->id);

        $this->assertSame($this->nasr->id, $saved['zone']['id']);
    }

    #[Test]
    public function a_zone_not_drawn_yet_is_still_taken_at_the_apps_word(): void
    {
        $this->draw($this->nasr, self::NASR);

        // Maadi is not drawn; the pin is outside Nasr City.
        $saved = $this->saveAddress($this->customer(), self::NOWHERE, $this->maadi->id);

        $this->assertSame($this->maadi->id, $saved['zone']['id']);
    }

    #[Test]
    public function a_pin_outside_a_drawn_zone_the_app_picked_is_in_no_zone(): void
    {
        $this->draw($this->nasr, self::NASR);

        $saved = $this->saveAddress($this->customer(), self::NOWHERE, $this->nasr->id);

        $this->assertNull($saved['zone']);
    }

    #[Test]
    public function moving_the_pin_moves_the_zone(): void
    {
        $this->draw($this->nasr, self::NASR);
        $this->draw($this->maadi, self::MAADI);
        $customer = $this->customer();
        $saved = $this->saveAddress($customer, self::IN_NASR, null);

        $this->app['auth']->forgetGuards();
        $this->putJson("/api/v1/addresses/{$saved['id']}", ['lat' => self::IN_MAADI[0], 'lng' => self::IN_MAADI[1]],
            $this->apiHeaders() + ['Authorization' => 'Bearer '.$customer->createToken('t')->plainTextToken])
            ->assertOk()
            ->assertJsonPath('data.zone.id', $this->maadi->id);
    }

    #[Test]
    public function drawing_a_zone_takes_in_the_addresses_already_inside_it_and_lets_go_of_the_rest(): void
    {
        $customer = $this->customer();
        // Picked from the list before anything was drawn.
        $inside = $this->addressFor($customer, $this->maadi, self::IN_NASR[0], self::IN_NASR[1]);
        $outside = $this->addressFor($customer, $this->nasr, self::NOWHERE[0], self::NOWHERE[1], 'Work');

        $this->draw($this->nasr, self::NASR);

        // The pin in Nasr City is now in Nasr City; the one that only claimed
        // Nasr City, across the river, is in no zone.
        $this->assertSame($this->nasr->id, $inside->fresh()->zone_id);
        $this->assertNull($outside->fresh()->zone_id);
    }

    #[Test]
    public function the_apps_are_given_the_drawings(): void
    {
        $this->draw($this->nasr, self::NASR);

        $zones = collect($this->getJson('/api/v1/cities', $this->apiHeaders())->assertOk()->json('data.0.zones'))->keyBy('id');

        $this->assertSame(self::NASR, $zones[$this->nasr->id]['boundary']);
        $this->assertNull($zones[$this->maadi->id]['boundary']);
    }

    // ------------------------------------------------------------ the work

    #[Test]
    public function an_order_from_a_pin_in_a_drawn_zone_goes_to_that_zones_laundry_and_drivers(): void
    {
        $catalog = $this->seedCatalog();
        foreach ($this->geo['zones'] as $zone) {
            $zone->update(['price_per_km' => 5.00, 'min_delivery_fee' => 20.00]);
        }
        $this->draw($this->nasr, self::NASR);

        $nasrLaundry = $this->laundryWithOwner('N', '+201011110011', '+201011110012');
        $this->cover($nasrLaundry['laundry'], $this->nasr->id, $catalog['service']->id, 30.06, 31.32);
        $maadiLaundry = $this->laundryWithOwner('M', '+201011110013', '+201011110014');
        $this->cover($maadiLaundry['laundry'], $this->maadi->id, $catalog['service']->id, 29.96, 31.26);

        $maadiDriver = $this->driverUser('+201044440002', zoneIds: [$this->maadi->id]);
        $nasrDriver = $this->driverUser('+201044440003', zoneIds: [$this->nasr->id]);

        // The customer's app still says Maadi; the pin is in Nasr City.
        $customer = $this->customer();
        $saved = $this->saveAddress($customer, self::IN_NASR, $this->maadi->id);

        $order = app(OrderService::class)->place($customer, [
            'service_id' => $catalog['service']->id,
            'pickup_address_id' => $saved['id'],
            'items' => [['item_id' => $catalog['items'][0]->id, 'qty' => 1]],
            'accepts_review_terms' => true,
        ]);

        $this->assertSame($nasrLaundry['laundry']->id, $order->laundry_id);

        $pickup = OrderTask::where('order_id', $order->id)->where('type', TaskType::PickupFromCustomer->value)->first();
        $this->assertSame($nasrDriver->id, $pickup->driver_id);
        $this->assertNotSame($maadiDriver->id, $pickup->driver_id);
    }

    // ------------------------------------------------------------ after review

    #[Test]
    public function the_same_ground_drawn_twice_is_refused(): void
    {
        $this->draw($this->nasr, self::NASR);

        // The same box for Maadi — no edge crosses, no corner is inside.
        $this->actingAs($this->superAdmin())
            ->putJson(route('admin.zone.update', $this->maadi->id), ['boundary' => json_encode(self::NASR)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('boundary');
    }

    #[Test]
    public function the_save_itself_refuses_an_overlap_the_form_did_not_see(): void
    {
        // Two saves at once: each form was checked before the other saved.
        // The save checks again, inside its transaction.
        $this->draw($this->nasr, self::NASR);

        $this->expectException(ValidationException::class);
        app(zoneCrudService::class)->updateRecord(['id' => $this->maadi->id, 'boundary' => json_encode(self::NASR)]);
    }

    #[Test]
    public function too_many_corners_says_so(): void
    {
        $ring = [];
        for ($i = 0; $i < 501; $i++) {
            $angle = 2 * M_PI * $i / 501;
            $ring[] = [30.05 + 0.02 * sin($angle), 31.33 + 0.02 * cos($angle)];
        }

        $response = $this->actingAs($this->superAdmin())
            ->putJson(route('admin.zone.update', $this->nasr->id), ['boundary' => json_encode($ring)])
            ->assertStatus(422);

        $this->assertStringContainsString('500', $response->json('errors.boundary.0'));
    }

    #[Test]
    public function a_redraw_does_not_empty_a_switched_off_zone(): void
    {
        $customer = $this->customer();
        // Maadi is switched off; its address sits inside the box Nasr City
        // is about to be drawn across, but outside Nasr City's drawing.
        $this->maadi->update(['status' => 'inactive']);
        $address = $this->addressFor($customer, $this->maadi, 30.079, 31.301);

        $this->draw($this->nasr, [[30.08, 31.30], [30.08, 31.36], [30.03, 31.36]]); // a triangle, missing that corner

        $this->assertSame($this->maadi->id, $address->fresh()->zone_id);
    }

    #[Test]
    public function an_address_with_an_order_under_way_is_not_left_without_a_zone(): void
    {
        $catalog = $this->seedCatalog();
        $this->nasr->update(['price_per_km' => 5.00, 'min_delivery_fee' => 20.00]);
        $laundry = $this->laundryWithOwner('N', '+201011110011', '+201011110012');
        $this->cover($laundry['laundry'], $this->nasr->id, $catalog['service']->id, 30.06, 31.32);

        $customer = $this->customer();
        $address = $this->addressFor($customer, $this->nasr, self::NOWHERE[0], self::NOWHERE[1]);
        app(OrderService::class)->place($customer, [
            'service_id' => $catalog['service']->id,
            'pickup_address_id' => $address->id,
            'items' => [['item_id' => $catalog['items'][0]->id, 'qty' => 1]],
            'accepts_review_terms' => true,
        ]);

        // Drawn without the address's pin: it would be let go — but its order
        // is being routed by that zone right now.
        $this->actingAs($this->superAdmin())
            ->put(route('admin.zone.update', $this->nasr->id), ['boundary' => json_encode(self::NASR)])
            ->assertSessionHas('success', fn ($message) => str_contains(
                $message,
                __(':count addresses kept their zone until their orders in progress are done.', ['count' => 1]),
            ));

        $this->assertSame($this->nasr->id, $address->fresh()->zone_id);
    }

    #[Test]
    public function a_pin_in_a_zone_switched_off_stays_in_it_and_is_served_when_it_is_switched_on(): void
    {
        $this->draw($this->nasr, self::NASR);
        $this->nasr->update(['status' => 'inactive']);

        // Saved while Nasr City was off. Until 2026-10-07 the app's pick,
        // Maadi, stood here — so a pin in a paused zone was filed under one
        // that was open and its order went through. Where the pin is decides
        // the zone; the switch decides only whether orders are taken.
        $customer = $this->customer();
        $saved = $this->saveAddress($customer, self::IN_NASR, $this->maadi->id);
        $this->assertSame($this->nasr->id, $saved['zone']['id']);
        $this->assertFalse($saved['is_covered']);

        $this->actingAs($this->superAdmin())
            ->postJson(route('admin.zone.toggleStatus', $this->nasr->id), ['status' => 'active'])
            ->assertOk();

        $address = Address::with('zone.city')->find($saved['id']);
        $this->assertSame($this->nasr->id, $address->zone_id);
        $this->assertTrue($address->isCovered());
    }

    #[Test]
    public function a_zone_created_drawn_and_switched_off_takes_in_the_addresses_inside_it(): void
    {
        // Picked from the list as Maadi, but the pin is where the new zone is
        // drawn. Created switched off, it still holds its ground — the same
        // pin saved a minute later would land in it.
        $customer = $this->customer();
        $address = $this->addressFor($customer, $this->maadi, self::IN_NASR[0], self::IN_NASR[1]);

        $zone = app(zoneCrudService::class)->addNew([
            'city_id' => $this->geo['city']->id,
            'name' => ['en' => 'Heliopolis', 'ar' => 'مصر الجديدة'],
            'sort_order' => 3,
            'status' => 'inactive',
            'boundary' => json_encode(self::NASR),
        ]);

        $address = Address::with('zone.city')->find($address->id);
        $this->assertSame($zone->id, $address->zone_id);
        $this->assertFalse($address->isCovered());
    }

    #[Test]
    public function a_drawn_zone_in_a_city_switched_off_still_holds_its_pins_and_is_not_served(): void
    {
        $this->draw($this->nasr, self::NASR);
        $this->geo['city']->update(['status' => 'inactive']);

        $this->assertSame($this->nasr->id, app(ZoneLocator::class)->zoneAt(...self::IN_NASR)?->id);

        $customer = $this->customer();
        $saved = $this->saveAddress($customer, self::IN_NASR, null);
        $this->assertSame($this->nasr->id, $saved['zone']['id']);
        $this->assertFalse($saved['is_covered']);

        // The city back on: nothing to re-locate, the address was never let go.
        $this->geo['city']->update(['status' => 'active']);
        $this->assertTrue(Address::with('zone.city')->find($saved['id'])->isCovered());
    }

    #[Test]
    public function an_address_edited_while_its_zone_not_drawn_yet_is_off_keeps_it(): void
    {
        // The case the owner asked about: the zone has no drawing, so nothing
        // could claim the address back when the zone is switched on again.
        $customer = $this->customer();
        $address = $this->addressFor($customer, $this->maadi, self::NOWHERE[0], self::NOWHERE[1]);
        $this->maadi->update(['status' => 'inactive']);
        $token = $customer->createToken('t')->plainTextToken;

        // The app moves the pin a little and re-sends the zone it has on file.
        $this->app['auth']->forgetGuards();
        $this->putJson("/api/v1/addresses/{$address->id}", [
            'lat' => self::NOWHERE[0] + 0.001, 'lng' => self::NOWHERE[1], 'zone_id' => $this->maadi->id,
        ], $this->apiHeaders() + ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.zone.id', $this->maadi->id)
            ->assertJsonPath('data.is_covered', false);

        // And without re-sending it.
        $this->app['auth']->forgetGuards();
        $this->putJson("/api/v1/addresses/{$address->id}", ['lat' => self::NOWHERE[0], 'lng' => self::NOWHERE[1]],
            $this->apiHeaders() + ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('data.zone.id', $this->maadi->id);

        $this->maadi->update(['status' => 'active']);
        $this->assertTrue(Address::with('zone.city')->find($address->id)->isCovered());
    }

    #[Test]
    public function the_locator_answers_for_a_pin(): void
    {
        $this->draw($this->nasr, self::NASR);
        $this->draw($this->maadi, self::MAADI);

        $locator = app(ZoneLocator::class);
        $this->assertSame($this->nasr->id, $locator->zoneAt(...self::IN_NASR)?->id);
        $this->assertSame($this->maadi->id, $locator->zoneAt(...self::IN_MAADI)?->id);
        $this->assertNull($locator->zoneAt(...self::NOWHERE));

        // A zone switched off still holds its ground: the switch decides
        // whether orders are taken there, not where the pin is (2026-10-07).
        $this->maadi->update(['status' => 'inactive']);
        $this->assertSame($this->maadi->id, $locator->zoneAt(...self::IN_MAADI)?->id);
    }
}
