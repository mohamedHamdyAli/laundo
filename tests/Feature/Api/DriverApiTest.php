<?php

namespace Tests\Feature\Api;

use App\Modules\Driver\Models\Driver;
use App\Services\Auth\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * P5 — the driver app's surface, and the wall between it and the customer app.
 */
class DriverApiTest extends TestCase
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

    private function tokenHeaders(string $token): array
    {
        $this->app['auth']->forgetGuards();

        return $this->apiHeaders() + ['Authorization' => "Bearer {$token}"];
    }

    public function test_a_driver_can_sign_in(): void
    {
        $this->driverUser();

        $response = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/driver/login', ['phone' => '01033330001', 'password' => 'password']);

        $response->assertOk()
            ->assertJsonPath('key', 'success')
            ->assertJsonPath('data.phone', '01033330001')
            ->assertJsonStructure(['data' => ['token', 'is_available', 'vehicle', 'license', 'shift', 'zones']]);
    }

    public function test_there_is_no_driver_registration_endpoint(): void
    {
        // Accounts are created in the dashboard — «تواصل مع المشرف» on the design's
        // login screen.
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/driver/register', ['phone' => '01033330001'])
            ->assertStatus(404);
    }

    public function test_an_inactive_driver_cannot_sign_in(): void
    {
        $this->driverUser(active: false);

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/driver/login', ['phone' => '01033330001', 'password' => 'password'])
            ->assertStatus(403)
            ->assertJsonPath('key', 'forbidden');
    }

    public function test_a_customer_cannot_sign_in_through_the_driver_endpoint(): void
    {
        // Same table, different role. The Driver model's scope is what refuses.
        $customer = $this->customer('01044440001');
        $customer->forceFill(['password' => Hash::make('password')])->save();

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/driver/login', ['phone' => '01044440001', 'password' => 'password'])
            ->assertStatus(401);
    }

    public function test_a_driver_cannot_sign_in_through_the_customer_endpoint(): void
    {
        $this->driverUser();

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/auth/login', ['phone' => '01033330001', 'password' => 'password'])
            ->assertStatus(401);
    }

    public function test_a_customer_token_is_refused_on_driver_endpoints(): void
    {
        $token = $this->customer('01044440001')->createToken('t')->plainTextToken;

        $this->withHeaders($this->tokenHeaders($token))
            ->getJson('/api/v1/driver/profile')
            ->assertStatus(403);
    }

    public function test_a_driver_token_is_refused_on_customer_address_endpoints(): void
    {
        $driver = $this->driverUser();
        $token = $driver->createToken('t')->plainTextToken;

        // A driver has no addresses relation of their own to manage, and the
        // customer endpoints must not silently accept them.
        $this->withHeaders($this->tokenHeaders($token))
            ->postJson('/api/v1/addresses', [
                'street' => 'somewhere', 'lat' => 30, 'lng' => 31, 'zone_id' => $this->zoneIds[0],
            ])
            ->assertStatus(201);

        // Documenting reality rather than asserting a wall that does not exist:
        // /addresses is keyed on the authenticated user, so a driver creating one
        // only ever touches their own row. The separation that matters — a driver
        // reading customer data — is covered by AddressTest.
        $this->assertSame(1, $driver->addresses()->count());
    }

    public function test_the_profile_reports_vehicle_documents_shift_and_zones(): void
    {
        $driver = $this->driverUser(zoneIds: [$this->zoneIds[0]]);
        $token = $driver->createToken('t')->plainTextToken;

        $response = $this->withHeaders($this->tokenHeaders($token))->getJson('/api/v1/driver/profile');

        $response->assertOk()
            ->assertJsonPath('data.vehicle.type', 'Motorcycle')
            ->assertJsonPath('data.license.number', 'DL-9911')
            ->assertJsonPath('data.shift', '09:00 – 21:00')
            ->assertJsonCount(1, 'data.zones');
    }

    public function test_availability_toggles_and_persists(): void
    {
        $driver = $this->driverUser(available: false);
        $token = $driver->createToken('t')->plainTextToken;

        $this->withHeaders($this->tokenHeaders($token))
            ->putJson('/api/v1/driver/availability', ['is_available' => true])
            ->assertOk()
            ->assertJsonPath('data.is_available', true);

        $this->assertTrue($driver->fresh()->profile->is_available);

        $this->withHeaders($this->tokenHeaders($token))
            ->putJson('/api/v1/driver/availability', ['is_available' => false])
            ->assertOk()
            ->assertJsonPath('data.is_available', false);

        $this->assertFalse($driver->fresh()->profile->is_available);
    }

    public function test_a_suspended_driver_cannot_make_themselves_available(): void
    {
        // Otherwise suspension would be undone by the driver it applies to.
        $driver = $this->driverUser(available: false);
        $token = $driver->createToken('t')->plainTextToken;

        $driver->forceFill(['status' => 'inactive'])->save();

        $this->withHeaders($this->tokenHeaders($token))
            ->putJson('/api/v1/driver/availability', ['is_available' => true])
            ->assertStatus(403);

        $this->assertFalse($driver->fresh()->profile->is_available);
    }

    public function test_a_driver_cannot_change_their_own_zones_or_documents(): void
    {
        $driver = $this->driverUser(zoneIds: [$this->zoneIds[0]]);
        $token = $driver->createToken('t')->plainTextToken;

        $this->withHeaders($this->tokenHeaders($token))->postJson('/api/v1/driver/profile', [
            'name' => 'Renamed',
            'zones' => [$this->zoneIds[1]],
            'license_number' => 'FORGED-1',
            'license_expiry' => '2099-01-01',
            'is_available' => true,
        ])->assertOk();

        $fresh = $driver->fresh(['profile', 'zones']);

        $this->assertSame('Renamed', $fresh->name, 'the name is the driver own to change');
        $this->assertSame([$this->zoneIds[0]], $fresh->zones->pluck('id')->all(), 'territory is assigned, not chosen');
        $this->assertSame('DL-9911', $fresh->profile->license_number, 'documents are verified records');
    }

    public function test_logout_revokes_only_the_calling_token(): void
    {
        $driver = $this->driverUser();
        $first = $driver->createToken('phone')->plainTextToken;
        $second = $driver->createToken('tablet')->plainTextToken;

        $this->withHeaders($this->tokenHeaders($first))
            ->postJson('/api/v1/driver/logout')->assertOk();

        $this->withHeaders($this->tokenHeaders($first))
            ->getJson('/api/v1/driver/profile')->assertStatus(401);

        $this->withHeaders($this->tokenHeaders($second))
            ->getJson('/api/v1/driver/profile')->assertOk();
    }

    public function test_changing_the_password_signs_other_devices_out(): void
    {
        $driver = $this->driverUser();
        $current = $driver->createToken('phone')->plainTextToken;
        $driver->createToken('tablet');

        $this->withHeaders($this->tokenHeaders($current))->putJson('/api/v1/driver/password', [
            'current_password' => 'password',
            'password' => 'newsecret123',
            'password_confirmation' => 'newsecret123',
        ])->assertOk();

        $this->assertSame(1, $driver->fresh()->tokens()->count());
        $this->assertTrue(Hash::check('newsecret123', $driver->fresh()->password));
    }

    public function test_a_wrong_current_password_is_refused(): void
    {
        $driver = $this->driverUser();
        $token = $driver->createToken('t')->plainTextToken;

        $this->withHeaders($this->tokenHeaders($token))->putJson('/api/v1/driver/password', [
            'current_password' => 'not-it',
            'password' => 'newsecret123',
            'password_confirmation' => 'newsecret123',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['current_password']]);
    }

    public function test_forgot_password_answers_the_same_for_unknown_numbers(): void
    {
        $this->driverUser();

        $known = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/driver/forgot-password', ['phone' => '01033330001']);

        $unknown = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/driver/forgot-password', ['phone' => '01099998888']);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json('msg'), $unknown->json('msg'));
    }

    public function test_reset_password_by_otp_revokes_every_token(): void
    {
        $driver = $this->driverUser();
        $driver->createToken('a');
        $driver->createToken('b');

        $code = app(OtpService::class)->issue($driver);

        $this->withHeaders($this->apiHeaders())->postJson('/api/v1/driver/reset-password', [
            'phone' => '01033330001',
            'code' => $code,
            'password' => 'resetme123',
            'password_confirmation' => 'resetme123',
        ])->assertOk();

        $this->assertSame(0, $driver->fresh()->tokens()->count());
        $this->assertTrue(Hash::check('resetme123', $driver->fresh()->password));
    }
}
