<?php

namespace Tests\Feature\Dashboard;

use App\Models\Role;
use App\Modules\Driver\Models\DriverRecordSubmission;
use App\Modules\Driver\Services\DriverRecordReview;
use App\Modules\User\Models\User;
use App\Services\MenuBadges;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Nothing a driver says about their own vehicle or papers takes effect until
 * somebody has looked at it.
 *
 * The driver app can edit three screens, and writing those straight through
 * makes the licence expiry whatever the driver last typed — a record nobody
 * checks is not a record. What is asserted hardest here is therefore the thing
 * that is *not* true after a submission: the profile has not moved.
 */
class DriverRecordReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCore();
    }

    private function submit(array $payload, string $phone = '+201077770001'): array
    {
        $driver = $this->driverUser($phone);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($driver);

        $this->postJson('/api/v1/driver/profile', $payload, $this->apiHeaders())->assertOk();

        return [$driver, DriverRecordSubmission::where('driver_id', $driver->id)->latest('id')->firstOrFail()];
    }

    #[Test]
    public function a_submission_does_not_touch_the_record(): void
    {
        [$driver, $submission] = $this->submit([
            'license_number' => 'DL-CLAIMED',
            'license_expiry' => '2099-01-01',
        ]);

        // The whole point. An expiry the driver typed is a claim, not a fact.
        $this->assertSame('DL-9911', $driver->fresh('profile')->profile->license_number);
        $this->assertSame(DriverRecordSubmission::PENDING, $submission->status);
        $this->assertSame('DL-CLAIMED', $submission->payload['license_number']);
    }

    #[Test]
    public function the_identity_half_still_applies_at_once(): void
    {
        // A name and a photograph are the driver's own and nothing about them is
        // verified. Holding those for review is asking somebody to approve a
        // nickname.
        [$driver] = $this->submit(['name' => 'Renamed', 'license_number' => 'DL-CLAIMED']);

        $this->assertSame('Renamed', $driver->fresh()->name);
    }

    #[Test]
    public function nothing_is_staged_when_only_the_name_was_sent(): void
    {
        $driver = $this->driverUser('+201077770009');
        Sanctum::actingAs($driver);

        $this->postJson('/api/v1/driver/profile', ['name' => 'Just a name'], $this->apiHeaders())->assertOk();

        $this->assertSame(0, DriverRecordSubmission::where('driver_id', $driver->id)->count());
    }

    #[Test]
    public function approving_writes_only_what_was_sent(): void
    {
        [$driver, $submission] = $this->submit(['license_number' => 'DL-CLAIMED']);

        // An operator corrected something else in the meantime.
        $driver->profile->forceFill(['plate_number' => 'OPERATOR 1'])->save();

        app(DriverRecordReview::class)->approve($submission, $this->superAdmin());

        $profile = $driver->fresh('profile')->profile;

        $this->assertSame('DL-CLAIMED', $profile->license_number);
        // A submission from the licence screen must not re-assert the vehicle at
        // whatever it was when that form loaded.
        $this->assertSame('OPERATOR 1', $profile->plate_number);
        $this->assertSame(DriverRecordSubmission::APPROVED, $submission->fresh()->status);
    }

    #[Test]
    public function rejecting_leaves_the_record_alone_and_says_why(): void
    {
        [$driver, $submission] = $this->submit(['license_number' => 'DL-CLAIMED']);

        app(DriverRecordReview::class)->reject($submission, $this->superAdmin(), 'The photo is unreadable.');

        $this->assertSame('DL-9911', $driver->fresh('profile')->profile->license_number);
        $this->assertSame('The photo is unreadable.', $submission->fresh()->note);
    }

    #[Test]
    public function a_second_submission_supersedes_the_first(): void
    {
        // One pending row per driver: an operator working through a backlog of
        // one driver's own corrections is reading history, not making decisions.
        $driver = $this->driverUser('+201077770002');
        Sanctum::actingAs($driver);

        $this->postJson('/api/v1/driver/profile', ['plate_number' => 'FIRST'], $this->apiHeaders())->assertOk();
        $this->postJson('/api/v1/driver/profile', ['plate_number' => 'SECOND'], $this->apiHeaders())->assertOk();

        $pending = DriverRecordSubmission::where('driver_id', $driver->id)->pending()->get();

        $this->assertCount(1, $pending);
        $this->assertSame('SECOND', $pending->first()->payload['plate_number']);
        // Superseded, not deleted: «what did he send last Tuesday» still has an
        // answer.
        $this->assertSame(2, DriverRecordSubmission::where('driver_id', $driver->id)->count());
    }

    #[Test]
    public function a_reviewed_submission_cannot_be_reviewed_twice(): void
    {
        [, $submission] = $this->submit(['plate_number' => 'ONCE']);

        $admin = $this->superAdmin();
        app(DriverRecordReview::class)->approve($submission, $admin);

        $this->expectExceptionMessage('already_reviewed');
        app(DriverRecordReview::class)->approve($submission->fresh(), $admin);
    }

    #[Test]
    public function the_driver_is_told_what_is_waiting(): void
    {
        // Without this the screen saves, redraws the old values and looks broken.
        [$driver] = $this->submit(['plate_number' => 'PENDING 1']);

        Sanctum::actingAs($driver);

        $this->getJson('/api/v1/driver/profile', $this->apiHeaders())
            ->assertOk()
            ->assertJsonPath('data.pending_review.status', 'pending')
            ->assertJsonPath('data.pending_review.fields', ['plate_number']);
    }

    #[Test]
    public function an_upload_is_staged_rather_than_written(): void
    {
        $driver = $this->driverUser('+201077770003');
        Sanctum::actingAs($driver);

        $this->post('/api/v1/driver/profile', [
            'license_image' => UploadedFile::fake()->image('licence.png'),
        ], $this->apiHeaders())->assertOk();

        $submission = DriverRecordSubmission::where('driver_id', $driver->id)->pending()->firstOrFail();

        // The file is written on submit — the reviewer has to be able to look at
        // it — but the profile does not point at it yet.
        $this->assertNotNull($submission->payload['license_image']);
        $this->assertNull($driver->fresh('profile')->profile->license_image);
    }

    #[Test]
    public function the_queue_is_gated_on_its_own_permission(): void
    {
        // Checking a licence photograph is a different job from keeping a
        // driver's shift current, so an install can hand it to a different
        // person — `driver.update` must not be enough.
        // A plain dashboard role, not the super admin: that one bypasses every
        // check by design and would prove nothing.
        $this->grant('admin', ['driver.view', 'driver.update']);

        $operator = User::create([
            'name' => 'Ops',
            'phone' => '+201077779999',
            'email' => 'ops@test.local',
            'password' => 'password',
            'status' => 'active',
            'role_id' => Role::where('slug', 'admin')->value('id'),
        ]);

        $this->actingAs($operator)->get('/admin/driver-record-submission')->assertForbidden();

        // And allowed the moment the right permission is there.
        $this->grant('admin', ['driver_record_submission.view']);

        // Re-read: the role and its permissions are already loaded on the
        // instance above, so the freshly synced row would not be seen.
        $this->actingAs($operator->fresh())->get('/admin/driver-record-submission')->assertOk();
    }

    #[Test]
    public function the_driver_supervisor_role_can_work_the_queue_out_of_the_box(): void
    {
        // The queue is addressed to whoever holds
        // `driver_record_submission.update`. Without a role that ships with it,
        // that is nobody until somebody builds one by hand — and a queue nobody
        // is told about is a driver waiting on a screen that never moves.
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $supervisor = User::create([
            'name' => 'Supervisor',
            'phone' => '+201077778888',
            'email' => 'supervisor@test.local',
            'password' => 'password',
            'status' => 'active',
            'role_id' => Role::where('slug', 'driver_supervisor')->value('id'),
        ]);

        [, $submission] = $this->submit(['plate_number' => 'FOR REVIEW']);

        $this->actingAs($supervisor)->get('/admin/driver-record-submission')->assertOk();
        $this->actingAs($supervisor)
            ->post('/admin/driver-record-submission/approve/'.$submission->id)
            ->assertRedirect();

        $this->assertSame(DriverRecordSubmission::APPROVED, $submission->fresh()->status);
    }

    #[Test]
    public function the_driver_supervisor_is_kept_away_from_what_drivers_are_paid(): void
    {
        // The codebase gates every driver money term on `setting.update`, so the
        // person managing a driver is not the person setting what that driver
        // earns. A supervisor role that quietly carried it would undo that.
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $held = Role::where('slug', 'driver_supervisor')->firstOrFail()
            ->permissions->pluck('slug');

        foreach (['driver_earning.view', 'driver_bonus_rule.update', 'driver_bonus_award.update',
            'setting.update', 'user.view', 'order.view'] as $slug) {
            $this->assertFalse($held->contains($slug), "{$slug} is not driver supervision");
        }

        // And it is not a system role: an install is meant to adjust it.
        $this->assertFalse((bool) Role::where('slug', 'driver_supervisor')->value('is_system'));
    }

    #[Test]
    public function the_badge_counts_only_what_is_waiting(): void
    {
        $this->assertNull(MenuBadges::for('driver_record_submission'));

        [, $submission] = $this->submit(['plate_number' => 'WAITING']);

        $this->assertSame(1, MenuBadges::for('driver_record_submission'));

        app(DriverRecordReview::class)->approve($submission, $this->superAdmin());

        // Zero is not a badge: an empty queue should look like every other
        // finished thing on the list.
        $this->assertNull(MenuBadges::for('driver_record_submission'));
    }
}
