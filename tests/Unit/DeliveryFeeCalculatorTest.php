<?php

namespace Tests\Unit;

use App\Modules\Address\Models\Address;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Order\Services\DeliveryFeeCalculator;
use App\Modules\Zone\Models\Zone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The fee arithmetic, exercised without a database.
 *
 * The models are built in memory and their relations set by hand, so the maths
 * and the "unknown fee" reporting can be checked in isolation from schema and
 * seeding.
 */
class DeliveryFeeCalculatorTest extends TestCase
{
    private DeliveryFeeCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new DeliveryFeeCalculator;
    }

    #[Test]
    public function it_measures_a_known_distance(): void
    {
        // Cairo Tower to the Pyramids: a little over 11 km as the crow flies.
        $km = $this->calculator->distanceKm(30.0459, 31.2243, 29.9792, 31.1342);

        $this->assertGreaterThan(10.5, $km);
        $this->assertLessThan(12.5, $km);
    }

    #[Test]
    public function the_fee_is_distance_times_the_zone_rate(): void
    {
        $laundry = $this->laundry(30.0561, 31.2003);
        // About 4 km away.
        $pickup = $this->address(30.0900, 31.2200, $this->zone(10.0, null));

        $result = $this->calculator->calculate($laundry, $pickup);

        $this->assertNotNull($result['fee']);

        // The fee is computed from the full-precision distance; `distance_km` is
        // the rounded figure shown to the customer. Comparing them needs a
        // tolerance, not equality — a cent of drift between the two is expected.
        $exact = $this->calculator->distanceKm(30.0561, 31.2003, 30.0900, 31.2200);
        $this->assertEqualsWithDelta(round($exact * 10.0, 2), $result['fee'], 0.001);
        $this->assertEqualsWithDelta($exact, $result['distance_km'], 0.01);
    }

    #[Test]
    public function a_short_trip_is_floored_at_the_zone_minimum(): void
    {
        $laundry = $this->laundry(30.0561, 31.2003);
        // ~300 m away: distance alone would price this at pennies.
        $pickup = $this->address(30.0588, 31.2003, $this->zone(5.0, 20.0));

        $result = $this->calculator->calculate($laundry, $pickup);

        $this->assertSame(20.0, $result['fee']);
        $this->assertLessThan(1.0, $result['distance_km']);
    }

    #[Test]
    public function two_different_addresses_cost_half_again(): void
    {
        $laundry = $this->laundry(30.0561, 31.2003);
        $zone = $this->zone(5.0, 20.0);

        $pickup = $this->address(30.0900, 31.2200, $zone, id: 1);
        $delivery = $this->address(30.0700, 31.2500, $zone, id: 2);

        $same = $this->calculator->calculate($laundry, $pickup, $pickup);
        $split = $this->calculator->calculate($laundry, $pickup, $delivery);

        // Same tolerance reasoning as above: both figures are rounded once, at
        // the end, so their ratio is 1.5 to within a cent.
        $this->assertEqualsWithDelta($same['fee'] * 1.5, $split['fee'], 0.02);
    }

    #[Test]
    public function an_unknowable_fee_is_reported_rather_than_priced_at_zero(): void
    {
        $zone = $this->zone(5.0, 20.0);
        $pickup = $this->address(30.0900, 31.2200, $zone);

        // No laundry assigned yet.
        $none = $this->calculator->calculate(null, $pickup);
        $this->assertNull($none['fee']);
        $this->assertSame('no_laundry_assigned', $none['reason']);

        // Assigned, but we do not know where it is.
        $unlocated = $this->calculator->calculate($this->laundry(null, null), $pickup);
        $this->assertNull($unlocated['fee']);
        $this->assertSame('laundry_has_no_coordinates', $unlocated['reason']);

        // Located, but the zone has never been priced.
        $unpriced = $this->address(30.0900, 31.2200, $this->zone(null, null));
        $result = $this->calculator->calculate($this->laundry(30.0561, 31.2003), $unpriced);
        $this->assertNull($result['fee']);
        $this->assertSame('zone_has_no_rate', $result['reason']);
    }

    private function laundry(?float $lat, ?float $lng): Laundry
    {
        $laundry = new Laundry;
        $laundry->lat = $lat;
        $laundry->lng = $lng;

        return $laundry;
    }

    private function zone(?float $perKm, ?float $minimum): Zone
    {
        $zone = new Zone;
        $zone->price_per_km = $perKm;
        $zone->min_delivery_fee = $minimum;

        return $zone;
    }

    private function address(float $lat, float $lng, Zone $zone, int $id = 1): Address
    {
        $address = new Address;
        $address->id = $id;
        $address->lat = $lat;
        $address->lng = $lng;
        $address->setRelation('zone', $zone);

        return $address;
    }
}
