<?php

namespace Tests\Feature\Dashboard;

use App\Modules\Driver\Models\DriverApplication;
use App\Modules\Driver\Models\DriverProfile;
use App\Services\MenuBadges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «انضم لنا» — somebody asking to drive, from the landing page.
 *
 * The rule the whole thing rests on: **an application is a lead, not an
 * account.** Nobody who fills this in has been vetted, has a licence on file
 * or can sign in to anything, so nothing here writes to `users` and nothing
 * here creates a driver. An operator rings the number and creates the driver
 * from the Drivers screen if the call goes well.
 *
 * The rest of the cases guard the two things that make the queue work: it is
 * taken without an account, and the sidebar says one is waiting — a lead
 * nobody is told about is a courier who took another job.
 */
class DriverApplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seedCore();
    }

    // ------------------------------------------------------------- applying

    #[Test]
    public function anybody_can_apply_and_it_creates_no_account(): void
    {
        $this->postJson(route('driver.apply'), [
            'name' => 'Mahmoud Ali',
            'phone' => '0112 603 2991',
            'note' => 'I have a motorbike.',
        ])->assertCreated();

        $application = DriverApplication::firstOrFail();

        $this->assertSame('Mahmoud Ali', $application->name);
        $this->assertSame('I have a motorbike.', $application->note);
        $this->assertTrue($application->isWaiting());

        // The whole point. A stranger who typed three boxes is not staff.
        $this->assertDatabaseMissing('users', ['name' => 'Mahmoud Ali']);
        $this->assertSame(0, DriverProfile::count());
    }

    #[Test]
    public function the_number_is_stored_as_it_can_be_dialled(): void
    {
        // «(0112) 603-2991» and «0112 603 2991» are one person; storing them
        // differently makes the list look like two leads.
        $this->postJson(route('driver.apply'), [
            'name' => 'Mahmoud',
            'phone' => '(0112) 603-2991',
        ])->assertCreated();

        $this->assertSame('01126032991', DriverApplication::firstOrFail()->phone);
    }

    #[Test]
    public function a_local_number_is_accepted(): void
    {
        // Deliberately not `phoneRegex()`. Every other number in the app is
        // E.164 because something dials or matches on it; this one is read by
        // a person about to ring it, and refusing «01126032991» from somebody
        // volunteering to work for you throws the lead away over a plus sign.
        $this->postJson(route('driver.apply'), [
            'name' => 'Mahmoud',
            'phone' => '01126032991',
        ])->assertCreated();
    }

    #[Test]
    public function the_note_is_optional(): void
    {
        $this->postJson(route('driver.apply'), [
            'name' => 'Mahmoud',
            'phone' => '01126032991',
        ])->assertCreated();

        $this->assertNull(DriverApplication::firstOrFail()->note);
    }

    #[Test]
    public function a_name_and_a_number_are_not(): void
    {
        $this->postJson(route('driver.apply'), ['note' => 'hello'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'phone']);

        $this->assertSame(0, DriverApplication::count());
    }

    #[Test]
    public function a_phone_field_cannot_be_used_as_a_message_box(): void
    {
        $this->postJson(route('driver.apply'), [
            'name' => 'Spam',
            'phone' => str_repeat('buy followers ', 30),
        ])->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    #[Test]
    public function operations_are_told_a_lead_arrived(): void
    {
        $admin = $this->superAdmin();

        $this->postJson(route('driver.apply'), ['name' => 'Mahmoud', 'phone' => '01126032991']);

        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame($admin->id, (int) DB::table('notifications')->value('notifiable_id'));
    }

    // ---------------------------------------------------------- the queue

    #[Test]
    public function the_screen_lists_them_and_counts_what_is_waiting(): void
    {
        $this->apply('Waiting Person', '01100000001');
        $handled = $this->apply('Handled Person', '01100000002');
        $handled->forceFill(['handled_at' => now()])->save();

        $this->actingAs($this->superAdmin())
            ->get(route('admin.driver_application.index'))
            ->assertOk()
            ->assertSee('Waiting Person')
            ->assertSee('Handled Person')
            // The heading counts only what nobody has picked up.
            ->assertSee('1 waiting');
    }

    #[Test]
    public function the_sidebar_counts_only_what_is_waiting(): void
    {
        $this->assertNull(MenuBadges::for('driver_application'), 'an empty queue draws no badge');

        $this->apply('One', '01100000001');
        $this->apply('Two', '01100000002');

        $this->assertSame(2, MenuBadges::for('driver_application'));

        DriverApplication::first()->forceFill(['handled_at' => now()])->save();

        $this->assertSame(1, MenuBadges::for('driver_application'));
    }

    #[Test]
    public function marking_it_handled_records_who_and_can_be_undone(): void
    {
        $application = $this->apply('Mahmoud', '01126032991');
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->post(route('admin.driver_application.handled', $application->id), [
                'admin_note' => 'Starts Sunday.',
            ])->assertRedirect();

        $application->refresh();
        $this->assertFalse($application->isWaiting());
        $this->assertSame($admin->id, $application->handled_by);
        $this->assertSame('Starts Sunday.', $application->admin_note);

        // Reversible: an operator who marks the wrong row can put it back, and
        // a lead closed too early belongs in the queue rather than lost.
        $this->actingAs($admin)->post(route('admin.driver_application.handled', $application->id));

        $this->assertTrue($application->fresh()->isWaiting());
        $this->assertNull($application->fresh()->handled_by);
    }

    #[Test]
    public function deleting_lands_on_the_list_and_not_back_where_it_came_from(): void
    {
        $application = $this->apply('Mahmoud', '01126032991');

        // The referer is the row's own page, which is what deleting from the
        // detail screen actually sends. `back()` would return the browser
        // there — to a row that no longer exists — and from the list it would
        // return to a page the browser may serve from cache, still showing it.
        $this->actingAs($this->superAdmin())
            ->from(route('admin.driver_application.show', $application->id))
            ->delete(route('admin.driver_application.delete', $application->id))
            ->assertRedirect(route('admin.driver_application.index'));

        $this->assertSame(0, DriverApplication::count());
    }

    #[Test]
    public function a_deleted_row_is_gone_from_the_list(): void
    {
        $kept = $this->apply('Still Here', '01100000001');
        $gone = $this->apply('Deleted Person', '01100000002');

        $this->actingAs($this->superAdmin())
            ->delete(route('admin.driver_application.delete', $gone->id));

        $this->actingAs($this->superAdmin())
            ->get(route('admin.driver_application.index'))
            ->assertOk()
            ->assertSee($kept->name)
            ->assertDontSee($gone->name);
    }

    #[Test]
    public function the_screen_needs_the_permission(): void
    {
        $this->apply('Mahmoud', '01126032991');

        // A laundry owner, who has every other Delivery-adjacent permission
        // and not this one — the gate is the permission, not the role type.
        $this->grant('laundry_owner', ['laundry.view', 'order.view']);
        $tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');

        $this->actingAs($tenant['owner'])
            ->get(route('admin.driver_application.index'))
            ->assertForbidden();
    }

    private function apply(string $name, string $phone): DriverApplication
    {
        return DriverApplication::create(['name' => $name, 'phone' => $phone]);
    }
}
