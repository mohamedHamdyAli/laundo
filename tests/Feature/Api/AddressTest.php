<?php

namespace Tests\Feature\Api;

use App\Modules\Address\Models\Address;
use App\Modules\User\Models\User;
use App\Modules\Zone\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P4 — addresses, and above all the isolation between customers.
 */
class AddressTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private int $zoneId;

    private int $cityId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $geo = $this->seedGeo();

        $this->cityId = $geo['city']->id;
        $this->zoneId = $geo['zones'][0]->id;

        $this->alice = $this->customer('01011111111');
        $this->bob = $this->customer('01022222222');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'label' => 'المنزل',
            'city_id' => $this->cityId,
            'zone_id' => $this->zoneId,
            'street' => '12 شارع النيل، الدقي',
            'building' => '45',
            'floor' => '3',
            'apartment' => '12',
            'landmark' => 'بجوار صيدلية العزبي',
            'notes' => 'الرجاء الاتصال قبل الوصول',
            'lat' => 30.0444,
            'lng' => 31.2357,
        ], $overrides);
    }

    private function as(User $user): array
    {
        $this->app['auth']->forgetGuards();

        return $this->apiHeaders() + ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken];
    }

    public function test_the_map_pin_is_required(): void
    {
        // Business decision: every address carries coordinates so a driver has a
        // point to navigate to.
        $this->withHeaders($this->as($this->alice))
            ->postJson('/api/v1/addresses', $this->payload(['lat' => null, 'lng' => null]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['lat', 'lng']]);
    }

    public function test_arabic_fields_round_trip_byte_exact(): void
    {
        $this->withHeaders($this->as($this->alice))
            ->postJson('/api/v1/addresses', $this->payload())
            ->assertStatus(201);

        $address = Address::first();

        $this->assertSame('المنزل', $address->label);
        $this->assertSame('12 شارع النيل، الدقي', $address->street);
        $this->assertSame('بجوار صيدلية العزبي', $address->landmark);
        $this->assertSame('الرجاء الاتصال قبل الوصول', $address->notes);
    }

    public function test_the_first_address_becomes_the_default(): void
    {
        $response = $this->withHeaders($this->as($this->alice))
            ->postJson('/api/v1/addresses', $this->payload());

        // Even without asking: a customer with addresses must always have one.
        $response->assertJsonPath('data.is_default', true);
    }

    public function test_contact_phone_falls_back_to_the_account_number(): void
    {
        $this->withHeaders($this->as($this->alice))
            ->postJson('/api/v1/addresses', $this->payload())
            ->assertJsonPath('data.contact_phone', $this->alice->phone);
    }

    public function test_only_one_address_is_ever_default(): void
    {
        $headers = $this->as($this->alice);

        $this->withHeaders($headers)->postJson('/api/v1/addresses', $this->payload());
        $this->withHeaders($headers)->postJson('/api/v1/addresses', $this->payload([
            'label' => 'العمل', 'is_default' => true,
        ]));
        $this->withHeaders($headers)->postJson('/api/v1/addresses', $this->payload([
            'label' => 'الاستراحة', 'is_default' => true,
        ]));

        $this->assertSame(3, $this->alice->addresses()->count());
        $this->assertSame(1, $this->alice->addresses()->where('is_default', true)->count());
    }

    public function test_deleting_the_default_promotes_another(): void
    {
        $headers = $this->as($this->alice);

        $first = $this->withHeaders($headers)->postJson('/api/v1/addresses', $this->payload())->json('data.id');
        $this->withHeaders($headers)->postJson('/api/v1/addresses', $this->payload(['label' => 'العمل']));

        $this->withHeaders($headers)->deleteJson("/api/v1/addresses/{$first}")->assertOk();

        $this->assertSame(1, $this->alice->addresses()->count());
        $this->assertSame(1, $this->alice->addresses()->where('is_default', true)->count());
    }

    public function test_a_customer_cannot_read_another_customers_address(): void
    {
        $id = $this->withHeaders($this->as($this->alice))
            ->postJson('/api/v1/addresses', $this->payload())->json('data.id');

        $this->withHeaders($this->as($this->bob))
            ->getJson("/api/v1/addresses/{$id}")
            ->assertStatus(404);
    }

    public function test_a_customer_cannot_modify_another_customers_address(): void
    {
        $id = $this->withHeaders($this->as($this->alice))
            ->postJson('/api/v1/addresses', $this->payload())->json('data.id');

        $this->withHeaders($this->as($this->bob))
            ->putJson("/api/v1/addresses/{$id}", ['street' => 'tampered', 'lat' => 30, 'lng' => 31])
            ->assertStatus(404);

        $this->assertSame('12 شارع النيل، الدقي', Address::find($id)->street);
    }

    public function test_a_customer_cannot_delete_another_customers_address(): void
    {
        $id = $this->withHeaders($this->as($this->alice))
            ->postJson('/api/v1/addresses', $this->payload())->json('data.id');

        $this->withHeaders($this->as($this->bob))
            ->deleteJson("/api/v1/addresses/{$id}")
            ->assertStatus(404);

        $this->assertNotNull(Address::find($id));
    }

    public function test_a_customer_cannot_redefault_another_customers_address(): void
    {
        $id = $this->withHeaders($this->as($this->alice))
            ->postJson('/api/v1/addresses', $this->payload())->json('data.id');

        $this->withHeaders($this->as($this->bob))
            ->putJson("/api/v1/addresses/{$id}/default")
            ->assertStatus(404);
    }

    public function test_a_customer_only_lists_their_own_addresses(): void
    {
        $this->withHeaders($this->as($this->alice))->postJson('/api/v1/addresses', $this->payload());
        $this->withHeaders($this->as($this->alice))->postJson('/api/v1/addresses', $this->payload(['label' => 'العمل']));

        $this->withHeaders($this->as($this->bob))
            ->getJson('/api/v1/addresses')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_an_inactive_zone_is_refused(): void
    {
        $zone = Zone::find($this->zoneId);
        $zone->update(['status' => 'inactive']);

        // Zones drive assignment, so an inactive one must not be selectable.
        $this->withHeaders($this->as($this->alice))
            ->postJson('/api/v1/addresses', $this->payload())
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['zone_id']]);
    }
}
