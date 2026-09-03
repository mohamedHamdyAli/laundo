<?php

namespace Tests\Feature\Dashboard;

use App\Models\Role;
use App\Modules\Driver\Models\Driver;
use App\Modules\Zone\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P5 — driver management in the dashboard.
 */
class DriverManagementTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, int> */
    private array $zoneIds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
        $geo = $this->seedGeo();
        $this->zoneIds = collect($geo['zones'])->pluck('id')->all();
    }

    public function test_a_driver_cannot_reach_the_dashboard(): void
    {
        // role type `app`, like a customer.
        $this->actingAs($this->driverUser())->get('/admin/home')->assertForbidden();
    }

    public function test_a_super_admin_can_list_drivers(): void
    {
        $this->driverUser();

        $this->actingAs($this->superAdmin())
            ->get('/admin/driver')
            ->assertOk()
            ->assertSee('Test Driver');
    }

    public function test_creating_a_driver_creates_the_account_profile_and_zones_together(): void
    {
        $this->actingAs($this->superAdmin())->post('/admin/driver/store', [
            'name' => 'Mahmoud',
            'phone' => '+201055550001',
            'password' => 'password',
            'password_confirmation' => 'password',
            'status' => 'active',
            'vehicle_type' => 'Van',
            'plate_number' => 'XYZ 999',
            'license_number' => 'DL-1',
            'shift_start' => '08:00',
            'shift_end' => '20:00',
            'is_available' => '1',
            'zones' => $this->zoneIds,
        ])->assertRedirect(route('admin.driver.index'));

        $driver = Driver::where('phone', '+201055550001')->first();

        $this->assertNotNull($driver);
        $this->assertSame(Role::DRIVER, $driver->role->slug);
        $this->assertSame('Van', $driver->profile->vehicle_type);
        $this->assertCount(2, $driver->zones);

        // Created by an admin with the driver present, so putting them through the
        // customer OTP flow would only lock them out.
        $this->assertNotNull($driver->phone_verified_at);
    }

    public function test_the_availability_switch_can_be_turned_off(): void
    {
        // An unchecked checkbox is absent from the payload entirely, so a naive
        // array_filter would drop the false and the switch could never go off.
        $driver = $this->driverUser(available: true);

        $this->actingAs($this->superAdmin())->put("/admin/driver/update/{$driver->id}", [
            'name' => $driver->name,
            'status' => 'active',
            // `is_available` deliberately omitted, as a browser would.
        ])->assertRedirect();

        $this->assertFalse($driver->fresh()->profile->is_available);
    }

    public function test_zones_must_be_active(): void
    {
        Zone::find($this->zoneIds[0])->update(['status' => 'inactive']);

        $this->actingAs($this->superAdmin())->post('/admin/driver/store', [
            'name' => 'Mahmoud',
            'phone' => '+201055550001',
            'password' => 'password',
            'password_confirmation' => 'password',
            'status' => 'active',
            'zones' => [$this->zoneIds[0]],
        ])->assertSessionHasErrors('zones.0');
    }

    public function test_a_duplicate_phone_is_refused(): void
    {
        $this->customer('+201055550001');

        $this->actingAs($this->superAdmin())->post('/admin/driver/store', [
            'name' => 'Mahmoud',
            'phone' => '+201055550001',
            'password' => 'password',
            'password_confirmation' => 'password',
            'status' => 'active',
        ])->assertSessionHasErrors('phone');
    }

    public function test_a_backwards_shift_is_refused(): void
    {
        $this->actingAs($this->superAdmin())->post('/admin/driver/store', [
            'name' => 'Mahmoud',
            'phone' => '+201055550001',
            'password' => 'password',
            'password_confirmation' => 'password',
            'status' => 'active',
            'shift_start' => '20:00',
            'shift_end' => '08:00',
        ])->assertSessionHasErrors('shift_end');
    }

    public function test_the_driver_list_shows_only_drivers(): void
    {
        $this->driverUser();
        $this->customer('+201044440001');
        $this->laundryWithOwner('A', '+201011110001', '+201011110002');

        $this->actingAs($this->superAdmin());

        $this->assertSame(1, Driver::count(), 'customers and laundry staff must not appear here');
    }

    public function test_a_driver_cannot_be_opened_by_a_non_driver_id(): void
    {
        $customer = $this->customer('+201044440001');

        $this->actingAs($this->superAdmin())
            ->get("/admin/driver/show/{$customer->id}")
            ->assertNotFound();
    }

    public function test_expired_documents_are_flagged(): void
    {
        $driver = $this->driverUser();
        $driver->profile->update(['license_expiry' => now()->subDay()->toDateString()]);

        $this->assertTrue($driver->fresh()->profile->hasExpiredDocuments());

        // Surfaced in the list rather than enforced, per the decision taken.
        $this->actingAs($this->superAdmin())
            ->get('/admin/driver')
            ->assertOk()
            ->assertSee('Documents expired');
    }

    public function test_a_document_is_valid_through_its_whole_expiry_day(): void
    {
        $driver = $this->driverUser();
        $driver->profile->update(['license_expiry' => now()->toDateString()]);

        $this->assertFalse(
            $driver->fresh()->profile->hasExpiredDocuments(),
            'a licence expiring today has not expired yet'
        );
    }

    public function test_dispatchability_needs_both_active_and_available(): void
    {
        $this->assertTrue($this->driverUser('+201033330001')->isDispatchable());
        $this->assertFalse($this->driverUser('+201033330002', available: false)->isDispatchable());
        $this->assertFalse($this->driverUser('+201033330003', active: false)->isDispatchable());
    }
}
