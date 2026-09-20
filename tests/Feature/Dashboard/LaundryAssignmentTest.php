<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Address\Models\Address;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Laundry\Models\LaundrySlotCapacity;
use App\Modules\LaundryZone\Models\LaundryZone;
use App\Modules\Order\Enums\OrderStatus;
use App\Modules\Order\Models\Order;
use App\Modules\Order\Services\LaundryAssigner;
use App\Modules\Service\Models\Service;
use App\Modules\Setting\Models\Setting;
use App\Modules\TimeSlot\Models\TimeSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who gets the order, and why.
 *
 * The scenario is the one the owner asked about: a customer in Nasr City, three
 * laundries covering it, and a decision to make. Distances here are straight
 * lines — `phpunit.xml` pins `ROUTING_DRIVER=haversine` so the suite runs
 * offline — which changes the numbers and none of the rules.
 *
 * Coordinates are chosen so the three laundries sit at roughly 1, 3 and 9 km
 * from the customer, far enough apart that a rounding difference cannot flip a
 * result.
 */
class LaundryAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private const CUSTOMER_LAT = 30.0600;

    private const CUSTOMER_LNG = 31.3300;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
    }

    #[Test]
    public function the_nearest_laundry_wins_when_balancing_is_off(): void
    {
        $this->tolerance(0);
        [$near, $mid, $far] = $this->threeLaundries();

        $chosen = $this->assign();

        $this->assertNotNull($chosen);
        $this->assertSame($near->id, $chosen->id);
    }

    #[Test]
    public function a_laundry_that_does_not_cover_the_zone_is_never_chosen(): void
    {
        $this->tolerance(0);
        [, , $far] = $this->threeLaundries();

        // The nearest two stop serving the zone. Coverage is a filter, not a
        // preference: the far one takes it however far away it is.
        LaundryZone::withoutGlobalScopes()
            ->where('laundry_id', '!=', $far->id)
            ->delete();

        $this->assertSame($far->id, $this->assign()?->id);
    }

    #[Test]
    public function an_inactive_laundry_is_never_chosen(): void
    {
        $this->tolerance(0);
        [$near, $mid] = $this->threeLaundries();

        $near->update(['status' => 'inactive']);

        $this->assertSame($mid->id, $this->assign()?->id);
    }

    #[Test]
    public function within_the_tolerance_the_laundry_with_more_room_takes_it(): void
    {
        // The owner's own example: the nearest holds four of five, the next one
        // along is empty, and they are close enough together that the extra
        // driving does not matter.
        $this->tolerance(5);
        [$near, $mid] = $this->threeLaundries();
        $slot = $this->slot();

        $this->capacity($near, $slot, 5);
        $this->capacity($mid, $slot, 5);
        $this->book($near, $slot, 4);

        $this->assertSame($mid->id, $this->assign()?->id, 'The emptier laundry inside the tolerance should win.');
    }

    #[Test]
    public function outside_the_tolerance_distance_still_wins(): void
    {
        // Same load, but now only one kilometre of slack. The mid laundry is
        // about two kilometres further than the near one, so it is out of the
        // group and the busy-but-near laundry keeps the order.
        $this->tolerance(1);
        [$near, $mid] = $this->threeLaundries();
        $slot = $this->slot();

        $this->capacity($near, $slot, 5);
        $this->capacity($mid, $slot, 5);
        $this->book($near, $slot, 4);

        $this->assertSame($near->id, $this->assign()?->id);
    }

    #[Test]
    public function free_places_beat_a_lower_order_count(): void
    {
        // The measure the owner chose. The near laundry holds four of twenty —
        // sixteen free. The mid laundry holds none of two — two free. Counting
        // orders would send this to the small empty one and leave sixteen
        // machines idle next door.
        $this->tolerance(5);
        [$near, $mid] = $this->threeLaundries();
        $slot = $this->slot();

        $this->capacity($near, $slot, 20);
        $this->capacity($mid, $slot, 2);
        $this->book($near, $slot, 4);

        $this->assertSame($near->id, $this->assign()?->id);
    }

    #[Test]
    public function an_uncapped_laundry_counts_as_having_room(): void
    {
        $this->tolerance(5);
        [$near, $mid] = $this->threeLaundries();
        $slot = $this->slot();

        // Near is full; mid has never been given a ceiling at all.
        $this->capacity($near, $slot, 2);
        $this->book($near, $slot, 2);

        $this->assertSame($mid->id, $this->assign()?->id);
    }

    #[Test]
    public function a_full_laundry_is_skipped_for_the_next_one_along(): void
    {
        $this->tolerance(0);
        [$near, $mid] = $this->threeLaundries();
        $slot = $this->slot();

        $this->capacity($near, $slot, 3);
        $this->book($near, $slot, 3);

        // Even with balancing off: full is not a preference, it is a wall.
        $this->assertSame($mid->id, $this->assign()?->id);
    }

    #[Test]
    public function a_cancelled_order_gives_its_place_back(): void
    {
        $this->tolerance(0);
        [$near] = $this->threeLaundries();
        $slot = $this->slot();

        $this->capacity($near, $slot, 2);
        $this->book($near, $slot, 2, OrderStatus::Cancelled);

        $this->assertSame($near->id, $this->assign()?->id);
    }

    #[Test]
    public function load_is_counted_per_window_and_per_day(): void
    {
        $this->tolerance(0);
        [$near, $mid] = $this->threeLaundries();
        $slot = $this->slot();
        $other = TimeSlot::create(['start_time' => '15:00', 'end_time' => '18:00', 'applies_to' => 'both', 'capacity' => null, 'sort_order' => 2, 'status' => 'active']);

        $this->capacity($near, $slot, 2);

        // Two orders, but in the afternoon window and on another day. Neither
        // touches the morning this customer asked for.
        $this->book($near, $other, 2);
        $this->book($near, $slot, 2, OrderStatus::AwaitingPickup, now()->addWeek()->toDateString());

        $this->assertSame($near->id, $this->assign()?->id);
        $this->assertSame($mid->id, $mid->id);
    }

    #[Test]
    public function when_everything_is_full_the_setting_decides(): void
    {
        $this->tolerance(0);
        [$near, $mid, $far] = $this->threeLaundries();
        $slot = $this->slot();

        foreach ([$near, $mid, $far] as $laundry) {
            $this->capacity($laundry, $slot, 1);
            $this->book($laundry, $slot, 1);
        }

        // Default: nobody gets it, and operations place it by hand.
        $this->overflow('unassigned');
        $this->assertNull($this->assign(), 'An unassigned order is the documented outcome, not a failure.');

        // Or the nearest takes it anyway and runs over.
        $this->overflow('nearest');
        $this->assertSame($near->id, $this->assign()?->id);

        // Hiding the window is an app-side answer; the server still refuses to
        // overload somebody who said they were full.
        $this->overflow('hide_slot');
        $this->assertNull($this->assign());
    }

    #[Test]
    public function with_no_window_booked_the_choice_falls_back_to_distance(): void
    {
        $this->tolerance(5);
        [$near, $mid] = $this->threeLaundries();
        $slot = $this->slot();

        $this->capacity($near, $slot, 5);
        $this->capacity($mid, $slot, 5);
        $this->book($near, $slot, 4);

        // Scheduling is optional in the API. With no window named there is
        // nothing to be full of, so the nearest wins.
        $chosen = app(LaundryAssigner::class)->assign($this->pickup, $this->service, null, null);

        $this->assertSame($near->id, $chosen?->id);
    }

    #[Test]
    public function the_panel_is_told_why_each_candidate_lost(): void
    {
        $this->tolerance(0);
        [$near, $mid, $far] = $this->threeLaundries();
        $slot = $this->slot();

        $this->capacity($mid, $slot, 1);
        $this->book($mid, $slot, 1);

        $evaluation = app(LaundryAssigner::class)->evaluate($this->pickup, $this->service, $slot->id, now()->toDateString());

        $reasons = [];

        foreach ($evaluation['candidates'] as $row) {
            $reasons[$row['laundry']->id] = $row['reason'];
        }

        $this->assertTrue($evaluation['candidates'][0]['chosen']);
        $this->assertNull($reasons[$near->id], 'The chosen one needs no excuse.');
        $this->assertNotNull($reasons[$mid->id]);
        $this->assertNotNull($reasons[$far->id]);
        $this->assertStringContainsString('km', (string) $reasons[$far->id]);
    }

    // ---------------------------------------------------------------- helpers

    private Address $pickup;

    private Service $service;

    /**
     * Three laundries covering the customer's zone, at roughly 1, 3 and 9 km.
     *
     * @return array<int, Laundry>
     */
    private function threeLaundries(): array
    {
        $geo = $this->seedGeo();
        $catalog = $this->seedCatalog();
        $zone = $geo['zones'][0];

        $this->service = $catalog['service'];

        $customer = $this->customer();
        $this->pickup = $this->addressFor($customer, $zone, self::CUSTOMER_LAT, self::CUSTOMER_LNG);

        $out = [];

        // Roughly 1 km, 3 km and 9 km east of the customer. A degree of
        // longitude here is about 96 km.
        foreach ([['A', 0.010], ['B', 0.031], ['C', 0.094]] as $i => [$tag, $offset]) {
            $pair = $this->laundryWithOwner($tag, '+2011100000'.$i, '+2011200000'.$i);
            $this->cover(
                $pair['laundry'],
                $zone->id,
                $this->service->id,
                self::CUSTOMER_LAT,
                self::CUSTOMER_LNG + $offset,
            );
            $out[] = $pair['laundry']->fresh();
        }

        return $out;
    }

    private function slot(): TimeSlot
    {
        return TimeSlot::firstOrCreate(
            ['start_time' => '09:00', 'end_time' => '12:00'],
            ['applies_to' => 'both', 'capacity' => null, 'sort_order' => 1, 'status' => 'active'],
        );
    }

    private function capacity(Laundry $laundry, TimeSlot $slot, ?int $capacity): void
    {
        LaundrySlotCapacity::withoutGlobalScopes()->updateOrCreate(
            ['laundry_id' => $laundry->id, 'time_slot_id' => $slot->id],
            ['capacity' => $capacity],
        );
    }

    private function book(
        Laundry $laundry,
        TimeSlot $slot,
        int $count,
        OrderStatus $status = OrderStatus::AwaitingPickup,
        ?string $date = null,
    ): void {
        $date ??= now()->toDateString();

        for ($i = 0; $i < $count; $i++) {
            Order::withoutGlobalScopes()->create([
                'code' => Order::generateCode(),
                'user_id' => $this->pickup->user_id,
                'laundry_id' => $laundry->id,
                'service_id' => $this->service->id,
                'status' => $status,
                'pickup_address_id' => $this->pickup->id,
                'delivery_address_id' => $this->pickup->id,
                'pickup_slot_id' => $slot->id,
                'pickup_date' => $date,
                'qr_token' => Order::generateQrToken(),
            ]);
        }
    }

    private function assign(): ?Laundry
    {
        return app(LaundryAssigner::class)->assign(
            $this->pickup,
            $this->service,
            $this->slot()->id,
            now()->toDateString(),
        );
    }

    private function tolerance(float $km): void
    {
        Setting::updateOrCreate(['key' => 'Balance_Tolerance_Km'], ['value' => $km]);
        Cache::forget('setting_Balance_Tolerance_Km');
    }

    private function overflow(string $behavior): void
    {
        Setting::updateOrCreate(['key' => 'Slot_Overflow_Behavior'], ['value' => $behavior]);
        Cache::forget('setting_Slot_Overflow_Behavior');
    }
}
