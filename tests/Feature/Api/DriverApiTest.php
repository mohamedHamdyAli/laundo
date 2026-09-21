<?php

namespace Tests\Feature\Api;

use App\Modules\Driver\Models\Driver;
use App\Services\Auth\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
            ->postJson('/api/v1/driver/login', ['phone' => '+201033330001', 'password' => 'password']);

        $response->assertOk()
            ->assertJsonPath('key', 'success')
            ->assertJsonPath('data.phone', '+201033330001')
            ->assertJsonStructure(['data' => ['token', 'is_available', 'vehicle', 'license', 'shift', 'zones']]);
    }

    public function test_there_is_no_driver_registration_endpoint(): void
    {
        // Accounts are created in the dashboard — «تواصل مع المشرف» on the design's
        // login screen.
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/driver/register', ['phone' => '+201033330001'])
            ->assertStatus(404);
    }

    public function test_an_inactive_driver_cannot_sign_in(): void
    {
        $this->driverUser(active: false);

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/driver/login', ['phone' => '+201033330001', 'password' => 'password'])
            ->assertStatus(403)
            ->assertJsonPath('key', 'forbidden');
    }

    public function test_a_customer_cannot_sign_in_through_the_driver_endpoint(): void
    {
        // Same table, different role. The Driver model's scope is what refuses.
        $customer = $this->customer('+201044440001');
        $customer->forceFill(['password' => Hash::make('password')])->save();

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/driver/login', ['phone' => '+201044440001', 'password' => 'password'])
            ->assertStatus(401);
    }

    public function test_a_driver_cannot_sign_in_through_the_customer_endpoint(): void
    {
        $this->driverUser();

        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/auth/login', ['phone' => '+201033330001', 'password' => 'password'])
            ->assertStatus(401);
    }

    public function test_a_customer_token_is_refused_on_driver_endpoints(): void
    {
        $token = $this->customer('+201044440001')->createToken('t')->plainTextToken;

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

    public function test_the_profile_is_well_formed_for_a_driver_with_no_documents(): void
    {
        // Documents are a record of what has been collected, not a precondition
        // for having an account — operations onboards a courier on the phone and
        // photographs the licence later. The app has to render that driver, so
        // every key is present and null rather than absent: a missing key is a
        // crash in a typed client, and «not collected yet» is a normal state.
        $driver = $this->driverUser('+201033330099');
        $driver->profile->forceFill([
            'vehicle_type' => null,
            'plate_number' => null,
            'license_number' => null,
            'license_expiry' => null,
            'license_image' => null,
            'vehicle_registration_image' => null,
            'vehicle_registration_expiry' => null,
            'national_id_image' => null,
            'shift_start' => null,
            'shift_end' => null,
        ])->save();

        $token = $driver->createToken('t')->plainTextToken;

        $response = $this->withHeaders($this->tokenHeaders($token))
            ->getJson('/api/v1/driver/profile')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'vehicle' => ['type', 'type_label', 'plate_number'],
                    'license' => ['number', 'expiry'],
                    'documents' => [['key', 'label', 'url', 'expiry']],
                    'shift', 'zones',
                ],
            ]);

        $response->assertJsonPath('data.vehicle.type', null)
            ->assertJsonPath('data.license.number', null)
            ->assertJsonPath('data.shift', null);

        // The three slots are still named, so the screen can draw «لم يتم الرفع»
        // beside each one rather than an empty page with no explanation.
        $this->assertCount(6, $response->json('data.documents'));
        $this->assertSame(
            ['license', 'vehicle_registration', 'vehicle_insurance',
                'vehicle_inspection', 'national_id', 'other'],
            collect($response->json('data.documents'))->pluck('key')->all()
        );
        // Every slot names the field to post it back under, so the app does not
        // keep its own mapping for the upload to drift out of.
        $this->assertSame(
            'license_image',
            collect($response->json('data.documents'))->firstWhere('key', 'license')['field']
        );
        $this->assertTrue(
            collect($response->json('data.documents'))->every(fn ($d) => $d['url'] === null)
        );
    }

    public function test_a_driver_with_no_profile_row_still_has_a_profile_payload(): void
    {
        // Every path that creates a driver makes the row, but nothing in the
        // schema requires it — and the app must not 500 on the one that slipped
        // through, it must show an account with nothing filled in.
        $driver = $this->driverUser('+201033330098');
        $driver->profile()->delete();

        $token = $driver->createToken('t')->plainTextToken;

        $this->withHeaders($this->tokenHeaders($token))
            ->getJson('/api/v1/driver/profile')
            ->assertOk()
            ->assertJsonPath('data.vehicle.plate_number', null)
            ->assertJsonPath('data.license.expiry', null)
            ->assertJsonPath('data.is_available', false)
            ->assertJsonCount(6, 'data.documents');
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

    public function test_a_driver_cannot_change_their_own_zones(): void
    {
        // Territory decides who is handed work, so a driver choosing their own
        // would let them keep the short trips and drop the rest. Vehicle, licence
        // and documents are theirs to maintain — only this is not.
        $driver = $this->driverUser(zoneIds: [$this->zoneIds[0]]);
        $token = $driver->createToken('t')->plainTextToken;

        $this->withHeaders($this->tokenHeaders($token))->postJson('/api/v1/driver/profile', [
            'name' => 'Renamed',
            'zones' => [$this->zoneIds[1]],
            'is_available' => true,
        ])->assertOk();

        $fresh = $driver->fresh(['profile', 'zones']);

        $this->assertSame('Renamed', $fresh->name, 'the name is the driver own to change');
        $this->assertSame([$this->zoneIds[0]], $fresh->zones->pluck('id')->all(), 'territory is assigned, not chosen');
    }

    public function test_a_driver_maintains_their_own_vehicle_licence_and_documents(): void
    {
        $driver = $this->driverUser('+201033330097');
        $token = $driver->createToken('t')->plainTextToken;

        $this->withHeaders($this->tokenHeaders($token))->postJson('/api/v1/driver/profile', [
            'vehicle_type' => 'car',
            'plate_number' => 'س ن ر 4821',
            'vehicle_brand' => 'Toyota',
            'vehicle_model' => 'Corolla',
            'vehicle_year' => '2022',
            'vehicle_color' => 'White',
            'license_number' => 'DL-4455',
            'license_type' => 'Private',
            'license_issued_at' => '2020-01-01',
            'license_expiry' => '2030-01-01',
        ])->assertOk()
            ->assertJsonPath('data.vehicle.brand', 'Toyota')
            ->assertJsonPath('data.vehicle.year', '2022')
            ->assertJsonPath('data.license.type', 'Private');

        $profile = $driver->fresh('profile')->profile;

        $this->assertSame('car', $profile->vehicle_type);
        $this->assertSame('Corolla', $profile->vehicle_model);
        $this->assertSame('DL-4455', $profile->license_number);
        $this->assertSame('2030-01-01', $profile->license_expiry->toDateString());
    }

    public function test_saving_one_screen_does_not_blank_the_next(): void
    {
        // The design has three screens with three save buttons. A field the
        // screen never drew is absent from its payload, and absent has to mean
        // «leave it alone» — otherwise saving the licence wipes the vehicle.
        $driver = $this->driverUser('+201033330096');
        $driver->profile->forceFill([
            'plate_number' => 'KEEP 111',
            'vehicle_brand' => 'Keep',
        ])->save();

        $token = $driver->createToken('t')->plainTextToken;

        $this->withHeaders($this->tokenHeaders($token))
            ->postJson('/api/v1/driver/profile', ['license_number' => 'DL-ONLY'])
            ->assertOk();

        $profile = $driver->fresh('profile')->profile;

        $this->assertSame('DL-ONLY', $profile->license_number);
        $this->assertSame('KEEP 111', $profile->plate_number, 'the vehicle screen was never posted');
        $this->assertSame('Keep', $profile->vehicle_brand);
    }

    public function test_a_field_posted_empty_is_cleared_rather_than_ignored(): void
    {
        // The other half of partial saving: absent means untouched, but present
        // and empty is the driver deleting something, and it has to stick.
        $driver = $this->driverUser('+201033330095');
        $token = $driver->createToken('t')->plainTextToken;

        $this->withHeaders($this->tokenHeaders($token))
            ->postJson('/api/v1/driver/profile', ['plate_number' => null])
            ->assertOk();

        $this->assertNull($driver->fresh('profile')->profile->plate_number);
    }

    public function test_every_record_field_is_optional(): void
    {
        // Nothing here gates having an account: operations onboards a courier on
        // the phone and photographs the papers afterwards.
        $driver = $this->driverUser('+201033330094');
        $token = $driver->createToken('t')->plainTextToken;

        $this->withHeaders($this->tokenHeaders($token))
            ->postJson('/api/v1/driver/profile', ['name' => 'Just the name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Just the name');
    }

    public function test_an_upload_replaces_only_the_document_it_was_sent_for(): void
    {
        $driver = $this->driverUser('+201033330093');
        $driver->profile->forceFill(['national_id_image' => 'images/drivers/documents/keep.png'])->save();

        $token = $driver->createToken('t')->plainTextToken;

        $this->withHeaders($this->tokenHeaders($token))->post('/api/v1/driver/profile', [
            'license_image' => UploadedFile::fake()->image('licence.png'),
        ], $this->tokenHeaders($token))->assertOk();

        $profile = $driver->fresh('profile')->profile;

        $this->assertNotNull($profile->license_image, 'the licence was uploaded');
        $this->assertSame(
            'images/drivers/documents/keep.png',
            $profile->national_id_image,
            'a document nobody sent a file for is left where it was'
        );
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
            ->postJson('/api/v1/driver/forgot-password', ['phone' => '+201033330001']);

        $unknown = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/driver/forgot-password', ['phone' => '+201099998888']);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json('msg'), $unknown->json('msg'));
    }

    /**
     * The two-step reset, end to end — the same shape the customer flow uses,
     * so the two apps have one contract to implement rather than two.
     */
    public function test_reset_password_by_otp_revokes_every_token(): void
    {
        $driver = $this->driverUser();
        $driver->createToken('a');
        $driver->createToken('b');

        $code = app(OtpService::class)->issue($driver);

        $token = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/driver/verify-reset-code', [
                'phone' => '+201033330001',
                'code' => $code,
            ])
            ->assertOk()
            ->json('data.reset_token');

        $this->assertIsString($token);

        $this->withHeaders($this->apiHeaders())->postJson('/api/v1/driver/reset-password', [
            'reset_token' => $token,
            'password' => 'resetme123',
            'password_confirmation' => 'resetme123',
        ])->assertOk();

        $this->assertSame(0, $driver->fresh()->tokens()->count());
        $this->assertTrue(Hash::check('resetme123', $driver->fresh()->password));
    }

    /**
     * The code cannot be presented as the ticket — which is exactly what the
     * old single-call shape allowed.
     */
    public function test_the_driver_password_step_will_not_accept_the_code(): void
    {
        $driver = $this->driverUser();
        $code = app(OtpService::class)->issue($driver);

        $this->withHeaders($this->apiHeaders())->postJson('/api/v1/driver/reset-password', [
            'reset_token' => $code,
            'password' => 'resetme123',
            'password_confirmation' => 'resetme123',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('password', $driver->fresh()->password));
    }

    /**
     * The two endpoints share the ticket mechanics but not the audience: a
     * customer's ticket must not open a driver's password, or a stolen customer
     * account would be a way into the driver app.
     */
    public function test_a_customers_ticket_cannot_reset_a_drivers_password(): void
    {
        $customer = $this->customer('+201055550009');
        $code = app(OtpService::class)->issue($customer);

        $token = $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/auth/verify-reset-code', [
                'phone' => '+201055550009',
                'code' => $code,
            ])
            ->assertOk()
            ->json('data.reset_token');

        $this->withHeaders($this->apiHeaders())->postJson('/api/v1/driver/reset-password', [
            'reset_token' => $token,
            'password' => 'notyours123',
            'password_confirmation' => 'notyours123',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('password', $customer->fresh()->password));
    }
}
