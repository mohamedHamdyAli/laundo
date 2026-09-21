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
            'vehicle_type' => 'van',
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
        // A slug now, not typed text: the field is a closed list.
        $this->assertSame('van', $driver->profile->vehicle_type);
        $this->assertCount(2, $driver->zones);

        // Created by an admin with the driver present, so putting them through the
        // customer OTP flow would only lock them out.
        $this->assertNotNull($driver->phone_verified_at);
    }

    public function test_a_driver_can_be_created_with_no_documents_at_all(): void
    {
        // «Documents» is a record of what has been collected, not a gate on
        // creating the account. Operations onboards a courier on the phone and
        // photographs the licence later; a form that refuses until every scan is
        // in hand means the driver is not in the system on the day they start.
        $this->actingAs($this->superAdmin())->post('/admin/driver/store', [
            'name' => 'Undocumented',
            'phone' => '+201055550077',
            'password' => 'password',
            'password_confirmation' => 'password',
            'status' => 'active',
            // Not one of: license_number, license_expiry, license_image,
            // vehicle_registration_image, vehicle_registration_expiry,
            // national_id_image, vehicle_type, plate_number.
        ])->assertRedirect(route('admin.driver.index'))
            ->assertSessionHasNoErrors();

        $driver = Driver::where('phone', '+201055550077')->first();

        $this->assertNotNull($driver, 'the account exists without a single document');
        $this->assertNotNull($driver->profile, 'and still gets its profile row');
        $this->assertNull($driver->profile->license_number);
        $this->assertNull($driver->profile->license_expiry);
        $this->assertNull($driver->profile->license_image);
        $this->assertNull($driver->profile->vehicle_registration_image);
        $this->assertNull($driver->profile->vehicle_registration_expiry);
        $this->assertNull($driver->profile->national_id_image);
    }

    public function test_documents_can_be_left_out_of_an_update_without_being_demanded(): void
    {
        $driver = $this->driverUser('+201055550078');
        $driver->profile->forceFill([
            'license_number' => 'DL-9',
            'license_expiry' => '2030-01-01',
        ])->save();

        $this->actingAs($this->superAdmin())->put("/admin/driver/update/{$driver->id}", [
            'name' => 'Renamed',
            'status' => 'active',
            // The documents block is absent, exactly as a browser posts it when
            // the operator only touched the name.
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Renamed', $driver->fresh()->name);
    }

    public function test_a_document_field_can_be_emptied_again(): void
    {
        // «Optional» has to mean both directions. The payload used to run through
        // an array_filter that dropped nulls, so a licence number typed into the
        // wrong driver could be set and never unset: the form posted a blank, the
        // filter discarded it, and the screen came back showing the old value as
        // though the save had not happened.
        $driver = $this->driverUser('+201055550079');
        $driver->profile->forceFill([
            'license_number' => 'DL-WRONG',
            'plate_number' => 'TYPO 1',
            'vehicle_brand' => 'Wrong',
        ])->save();

        $this->actingAs($this->superAdmin())->put("/admin/driver/update/{$driver->id}", [
            'name' => $driver->name,
            'status' => 'active',
            'license_number' => '',
            'plate_number' => '',
            'vehicle_brand' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $profile = $driver->fresh('profile')->profile;

        $this->assertNull($profile->license_number);
        $this->assertNull($profile->plate_number);
        $this->assertNull($profile->vehicle_brand);
    }

    public function test_the_new_vehicle_and_document_fields_save(): void
    {
        $this->actingAs($this->superAdmin())->post('/admin/driver/store', [
            'name' => 'Fully Documented',
            'phone' => '+201055550080',
            'password' => 'password',
            'password_confirmation' => 'password',
            'status' => 'active',
            'vehicle_brand' => 'Toyota',
            'vehicle_model' => 'Corolla',
            'vehicle_year' => '2022',
            'vehicle_color' => 'White',
            'license_type' => 'Private',
            'license_issued_at' => '2020-01-01',
            'vehicle_insurance_expiry' => '2027-06-01',
            'vehicle_inspection_expiry' => '2027-09-01',
        ])->assertRedirect(route('admin.driver.index'))->assertSessionHasNoErrors();

        $profile = Driver::where('phone', '+201055550080')->first()->profile;

        $this->assertSame('Toyota', $profile->vehicle_brand);
        $this->assertSame('2022', $profile->vehicle_year);
        $this->assertSame('Private', $profile->license_type);
        $this->assertSame('2027-06-01', $profile->vehicle_insurance_expiry->toDateString());
    }

    public function test_every_dated_document_is_watched_for_expiry(): void
    {
        // A new document with an expiry nothing looks at lapses in silence, which
        // is the one thing recording the date was for.
        $driver = $this->driverUser('+201055550081');
        $driver->profile->forceFill([
            'vehicle_insurance_expiry' => now()->subDay()->toDateString(),
            'vehicle_inspection_expiry' => now()->addYear()->toDateString(),
        ])->save();

        $expired = $driver->fresh('profile')->profile->expiredDocuments();

        $this->assertArrayHasKey('vehicle_insurance_expiry', $expired);
        $this->assertArrayNotHasKey('vehicle_inspection_expiry', $expired);
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
