<?php

namespace Tests\Feature\Dashboard;

use App\Models\Role;
use App\Modules\Driver\Models\DriverRecordSubmission;
use App\Modules\Driver\Services\DriverRecordReview;
use App\Modules\Notification\Enums\NotificationEvent;
use App\Modules\Notification\Models\NotificationLog;
use App\Modules\User\Models\User;
use App\Services\MenuBadges;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        Storage::fake('public');
    }

    private function submit(array $payload, string $phone = '+201077770001'): array
    {
        $driver = $this->driverUser($phone);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($driver);

        $this->postJson('/api/v1/driver/profile', $payload, $this->apiHeaders())->assertOk();

        return [$driver, DriverRecordSubmission::where('driver_id', $driver->id)->latest('id')->firstOrFail()];
    }

    /**
     * Permissions before roles, because that is the order `DatabaseSeeder` uses
     * and the whole point of these two tests is what a fresh install gets.
     */
    private function seedRolesAsTheInstallerDoes(): void
    {
        $order = (new \ReflectionClass(DatabaseSeeder::class))
            ->getFileName();

        $source = file_get_contents($order);

        $this->assertLessThan(
            strpos($source, 'RoleSeeder::class'),
            strpos($source, 'PermissionSeeder::class'),
            'DatabaseSeeder must seed permissions before roles, or every role ships with none.'
        );

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
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
    public function a_submission_cannot_destroy_the_approved_document(): void
    {
        // The queue exists so that nothing a driver says about their papers
        // takes effect until somebody looks. That has to cover the file as well
        // as the row: `uploadOrUpdateImage()` deletes whatever path it is handed
        // as the existing one, so handing it the approved licence made the
        // *submission* destroy it — before the review, and not undone by a
        // rejection. The operator would then open the comparison and be shown a
        // placeholder where the licence used to be, with nothing saying so.
        $driver = $this->driverUser('+201077770010');

        $approved = UploadedFile::fake()->image('approved.png')
            ->store('images/drivers/documents', 'public');

        $driver->profile()->updateOrCreate(
            ['user_id' => $driver->id],
            ['license_image' => $approved]
        );

        Sanctum::actingAs($driver);

        $this->post('/api/v1/driver/profile', [
            'license_image' => UploadedFile::fake()->image('claimed.png'),
        ], $this->apiHeaders())->assertOk();

        // Still on disk, and still what the record points at.
        Storage::disk('public')->assertExists($approved);
        $this->assertSame($approved, $driver->fresh('profile')->profile->license_image);

        $submission = DriverRecordSubmission::where('driver_id', $driver->id)->pending()->firstOrFail();
        $this->assertNotSame($approved, $submission->payload['license_image']);
        Storage::disk('public')->assertExists($submission->payload['license_image']);
    }

    #[Test]
    public function approval_is_what_retires_the_old_document(): void
    {
        // The other half of the rule above. The approved photograph stops being
        // the record at exactly one moment — when a reviewer replaces it — and
        // leaving it on disk for ever would grow the storage by a file per
        // correction with nothing pointing at any of them.
        $driver = $this->driverUser('+201077770011');

        $approved = UploadedFile::fake()->image('approved.png')
            ->store('images/drivers/documents', 'public');

        $driver->profile()->updateOrCreate(
            ['user_id' => $driver->id],
            ['license_image' => $approved]
        );

        Sanctum::actingAs($driver);

        $this->post('/api/v1/driver/profile', [
            'license_image' => UploadedFile::fake()->image('claimed.png'),
        ], $this->apiHeaders())->assertOk();

        $submission = DriverRecordSubmission::where('driver_id', $driver->id)->pending()->firstOrFail();
        $staged = $submission->payload['license_image'];

        app(DriverRecordReview::class)->approve($submission, $this->superAdmin());

        $this->assertSame($staged, $driver->fresh('profile')->profile->license_image);
        Storage::disk('public')->assertExists($staged);
        Storage::disk('public')->assertMissing($approved);
    }

    #[Test]
    public function a_refused_photograph_is_kept(): void
    {
        // «The uploaded file is kept — a refused photograph is evidence of what
        // was sent.» A driver told only «rejected» sends the same one again, and
        // the conversation about why needs something to look at.
        $driver = $this->driverUser('+201077770012');

        Sanctum::actingAs($driver);

        $this->post('/api/v1/driver/profile', [
            'license_image' => UploadedFile::fake()->image('blurred.png'),
        ], $this->apiHeaders())->assertOk();

        $submission = DriverRecordSubmission::where('driver_id', $driver->id)->pending()->firstOrFail();
        $staged = $submission->payload['license_image'];

        app(DriverRecordReview::class)->reject($submission, $this->superAdmin(), 'Illegible.');

        Storage::disk('public')->assertExists($staged);
    }

    #[Test]
    public function a_refusal_reaches_the_driver_s_phone(): void
    {
        // The note is mandatory because a driver told only «rejected» sends the
        // same photograph again — which only holds if the note actually
        // arrives. `AdminNotification` declares `via() = ['database']`, so
        // sending an app user through it writes a row in a bell they have no
        // reason to open and no push at all: the silence the note exists to
        // prevent, with a record saying it was prevented.
        [$driver, $submission] = $this->submit(['plate_number' => 'ABC 123'], '+201077770013');

        app(DriverRecordReview::class)->reject($submission, $this->superAdmin(), 'Illegible.');

        $channels = NotificationLog::where('user_id', $driver->id)
            ->where('event', NotificationEvent::DriverRecordRejected->value)
            ->pluck('channel')
            ->all();

        sort($channels);

        $this->assertSame(['database', 'push'], $channels);
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
        // In the order `DatabaseSeeder` actually runs them. Seeding these two by
        // hand in the convenient order is how «out of the box» stopped meaning
        // it: `RoleSeeder::syncPermissions()` resolves slugs against the
        // permissions table, so running it first attaches nothing at all — and
        // `sync([])` is not an error, so the role shipped empty and silently.
        $this->seedRolesAsTheInstallerDoes();

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
        // In the order `DatabaseSeeder` actually runs them. Seeding these two by
        // hand in the convenient order is how «out of the box» stopped meaning
        // it: `RoleSeeder::syncPermissions()` resolves slugs against the
        // permissions table, so running it first attaches nothing at all — and
        // `sync([])` is not an error, so the role shipped empty and silently.
        $this->seedRolesAsTheInstallerDoes();

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
