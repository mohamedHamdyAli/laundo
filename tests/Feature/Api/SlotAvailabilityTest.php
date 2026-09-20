<?php

namespace Tests\Feature\Api;

use App\Modules\Address\Models\Address;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Laundry\Models\LaundrySlotCapacity;
use App\Modules\LaundryZone\Models\LaundryZone;
use App\Modules\Order\Models\Order;
use App\Modules\Service\Models\Service;
use App\Modules\Setting\Models\Setting;
use App\Modules\TimeSlot\Models\TimeSlot;
use App\Modules\Zone\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `GET /api/v1/time-slots` answering the laundry-capacity half.
 *
 * The contract rule this is really guarding: **a client that sends neither new
 * parameter gets exactly the response it got before.** The apps are shipped and
 * will not all update at once, so the laundry half is additive or it is wrong.
 */
class SlotAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Zone $zone;

    private Service $service;

    private TimeSlot $morning;

    private TimeSlot $deliveryOnly;

    private Laundry $laundry;

    private int $addressId;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();

        $geo = $this->seedGeo();
        $catalog = $this->seedCatalog();

        $this->zone = $geo['zones'][0];
        $this->service = $catalog['service'];

        $customer = $this->customer();
        $this->addressId = $this->addressFor($customer, $this->zone)->id;

        $pair = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $this->laundry = $pair['laundry'];
        $this->cover($this->laundry, $this->zone->id, $this->service->id);

        $this->morning = TimeSlot::create([
            'start_time' => '09:00', 'end_time' => '12:00', 'applies_to' => 'both',
            'capacity' => null, 'sort_order' => 1, 'status' => 'active',
        ]);

        $this->deliveryOnly = TimeSlot::create([
            'start_time' => '18:00', 'end_time' => '21:00', 'applies_to' => 'delivery',
            'capacity' => null, 'sort_order' => 2, 'status' => 'active',
        ]);
    }

    #[Test]
    public function a_client_that_sends_no_address_gets_the_old_response(): void
    {
        $this->fill($this->morning, 1, 1);

        $response = $this->getJson('/api/v1/time-slots?type=pickup&date='.now()->toDateString());

        $response->assertOk();
        $row = $this->rowFor($response, $this->morning->id);

        $this->assertNull($row['remaining'], 'The platform window is uncapped and must still say so.');
        $this->assertFalse($row['is_full']);
        $this->assertNull($row['laundries_full'], 'Unanswerable without an address, and null is how that is said.');
    }

    #[Test]
    public function with_an_address_a_full_zone_is_reported(): void
    {
        $this->fill($this->morning, 2, 2);

        $row = $this->rowFor(
            $this->getJson($this->url()),
            $this->morning->id,
        );

        $this->assertTrue($row['laundries_full']);
    }

    #[Test]
    public function room_left_anywhere_means_the_zone_is_not_full(): void
    {
        $this->fill($this->morning, 3, 2);

        $row = $this->rowFor($this->getJson($this->url()), $this->morning->id);

        $this->assertFalse($row['laundries_full']);
    }

    #[Test]
    public function the_window_is_only_hidden_when_the_setting_says_so(): void
    {
        $this->fill($this->morning, 1, 1);

        // Default: the order would still be accepted unassigned, so the window
        // stays on offer.
        $this->overflow('unassigned');
        $this->assertFalse($this->rowFor($this->getJson($this->url()), $this->morning->id)['is_full']);

        $this->overflow('nearest');
        $this->assertFalse($this->rowFor($this->getJson($this->url()), $this->morning->id)['is_full']);

        // Only here does a full zone close the window.
        $this->overflow('hide_slot');
        $row = $this->rowFor($this->getJson($this->url()), $this->morning->id);
        $this->assertTrue($row['is_full']);
        $this->assertTrue($row['laundries_full']);
    }

    #[Test]
    public function a_delivery_only_window_is_never_measured_against_intake(): void
    {
        // The laundry is full for the morning. The evening window is delivery
        // only — nothing is collected in it — so its answer is «not applicable»
        // rather than «full», whatever the setting says.
        $this->fill($this->morning, 1, 1);
        $this->overflow('hide_slot');

        $row = $this->rowFor(
            $this->getJson('/api/v1/time-slots?date='.now()->toDateString().'&address_id='.$this->addressId),
            $this->deliveryOnly->id,
        );

        $this->assertNull($row['laundries_full']);
        $this->assertFalse($row['is_full']);
    }

    #[Test]
    public function an_uncovered_zone_is_a_gap_and_not_a_full_day(): void
    {
        // Nothing serves this zone at all. That is a coverage problem the order
        // flow already handles by accepting the order unassigned; telling the
        // customer every window is full would be a different and wrong story.
        $this->laundry->update(['status' => 'inactive']);
        LaundryZone::withoutGlobalScopes()->delete();
        $this->overflow('hide_slot');

        $row = $this->rowFor($this->getJson($this->url()), $this->morning->id);

        $this->assertNull($row['laundries_full']);
        $this->assertFalse($row['is_full']);
    }

    private function url(): string
    {
        return '/api/v1/time-slots?type=pickup&date='.now()->toDateString()
            .'&address_id='.$this->addressId
            .'&service_id='.$this->service->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor($response, int $slotId): array
    {
        foreach ($response->json('data') as $row) {
            if ($row['id'] === $slotId) {
                return $row;
            }
        }

        $this->fail("Slot {$slotId} was not in the response.");
    }

    private function fill(TimeSlot $slot, int $capacity, int $booked): void
    {
        LaundrySlotCapacity::withoutGlobalScopes()->updateOrCreate(
            ['laundry_id' => $this->laundry->id, 'time_slot_id' => $slot->id],
            ['capacity' => $capacity],
        );

        for ($i = 0; $i < $booked; $i++) {
            Order::withoutGlobalScopes()->create([
                'code' => Order::generateCode(),
                'user_id' => $this->customerId(),
                'laundry_id' => $this->laundry->id,
                'service_id' => $this->service->id,
                'status' => 'awaiting_pickup',
                'pickup_address_id' => $this->addressId,
                'delivery_address_id' => $this->addressId,
                'pickup_slot_id' => $slot->id,
                'pickup_date' => now()->toDateString(),
                'qr_token' => Order::generateQrToken(),
            ]);
        }
    }

    private function customerId(): int
    {
        return (int) Address::whereKey($this->addressId)->value('user_id');
    }

    private function overflow(string $behavior): void
    {
        Setting::updateOrCreate(['key' => 'Slot_Overflow_Behavior'], ['value' => $behavior]);
        Cache::forget('setting_Slot_Overflow_Behavior');
    }
}
