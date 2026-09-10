<?php

namespace Tests\Feature\Dashboard;

use App\Mail\LaundryApplicationDecided;
use App\Models\Role;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\Report\Services\DashboardSummary;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A laundry applying to join, and somebody deciding.
 *
 * The rule the whole feature rests on: **an application cannot sign in.** Both
 * halves of it — the laundry row and the owner account — are created switched
 * off, and only approval turns them on.
 *
 * That was not true of the panel before this. `LoginController` used
 * `AuthenticatesUsers` unmodified, which matches on the email and the password
 * and nothing else, so an inactive account signed straight in. The API had
 * always refused one (`account_inactive`, 403); the panel was the outlier, and
 * `laundryCrudService::deleteRecord()` already claimed the opposite — it
 * deactivates a deleted laundry's users "so an orphan cannot still sign in".
 * Several of the cases below exist to keep that promise kept.
 */
class LaundryApplicationTest extends TestCase
{
    use RefreshDatabase;

    private array $geo;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seedCore();
        $this->geo = $this->seedGeo();
        Mail::fake();
    }

    // ------------------------------------------------------------ signing in

    #[Test]
    public function an_inactive_account_cannot_sign_in_to_the_panel(): void
    {
        $admin = $this->superAdmin();
        $admin->update(['status' => 'inactive']);

        $this->post(route('login'), ['email' => $admin->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function an_active_account_still_signs_in(): void
    {
        $admin = $this->superAdmin();

        $this->post(route('login'), ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($admin);
    }

    // --------------------------------------------------------------- applying

    #[Test]
    public function an_application_creates_a_laundry_and_an_owner_that_are_both_off(): void
    {
        $this->post(route('laundry.register.store'), $this->payload())
            ->assertRedirect(route('laundry.applied'));

        $laundry = Laundry::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('inactive', $laundry->status);
        $this->assertNull($laundry->approved_at);
        $this->assertTrue($laundry->isPending());

        $owner = $laundry->owner;
        $this->assertNotNull($owner);
        $this->assertSame('inactive', $owner->status);
        $this->assertSame('laundry_owner', $owner->role->slug);
    }

    #[Test]
    public function an_applicant_is_told_they_are_pending_rather_than_that_their_password_is_wrong(): void
    {
        $this->post(route('laundry.register.store'), $this->payload());

        $this->post(route('login'), ['email' => 'owner@applicant.test', 'password' => 'a-good-password'])
            ->assertSessionHasErrors([
                'email' => __('Your laundry is still being reviewed. We will email you as soon as it is approved.'),
            ]);

        // The password is right. Saying otherwise would send them off to reset
        // a password that works.
        $this->assertGuest();
    }

    #[Test]
    public function operations_are_told_an_application_arrived(): void
    {
        $admin = $this->superAdmin();

        $this->post(route('laundry.register.store'), $this->payload());

        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame($admin->id, (int) DB::table('notifications')->value('notifiable_id'));
    }

    #[Test]
    public function the_application_form_holds_the_same_line_the_panel_does(): void
    {
        // An application that passes here and fails the panel's own validation
        // is one nobody can approve.
        $this->post(route('laundry.register.store'), $this->payload([
            'phone' => '01012345678',        // not E.164
            'owner_password' => 'short',
            'owner_password_confirmation' => 'short',
            'lat' => null,
            'lng' => null,
        ]))->assertSessionHasErrors(['phone', 'owner_password', 'lat']);

        $this->assertSame(0, Laundry::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_name_in_one_language_is_enough(): void
    {
        $this->post(route('laundry.register.store'), $this->payload([
            'name' => ['en' => '', 'ar' => 'مغسلة النيل'],
        ]))->assertRedirect(route('laundry.applied'));

        $this->assertSame(1, Laundry::withoutGlobalScopes()->count());
    }

    #[Test]
    public function a_name_in_no_language_is_not(): void
    {
        $this->post(route('laundry.register.store'), $this->payload([
            'name' => ['en' => '', 'ar' => ''],
        ]))->assertSessionHasErrors('name');

        $this->assertSame(0, Laundry::withoutGlobalScopes()->count());
    }

    // -------------------------------------------------------------- deciding

    #[Test]
    public function approving_turns_both_halves_on_and_lets_them_in(): void
    {
        $this->post(route('laundry.register.store'), $this->payload());
        $laundry = Laundry::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.laundry.approve', $laundry->id))
            ->assertRedirect();

        $laundry->refresh();
        $this->assertSame('active', $laundry->status);
        $this->assertNotNull($laundry->approved_at);
        $this->assertSame('active', $laundry->owner->status);

        Mail::assertSent(LaundryApplicationDecided::class);

        // The point of all of it.
        auth()->logout();
        $this->post(route('login'), ['email' => 'owner@applicant.test', 'password' => 'a-good-password'])
            ->assertRedirect(route('home'));
        $this->assertAuthenticated();
    }

    #[Test]
    public function approving_reactivates_staff_too_not_only_the_owner(): void
    {
        $this->post(route('laundry.register.store'), $this->payload());
        $laundry = Laundry::withoutGlobalScopes()->firstOrFail();

        // An application decided weeks later may already have staff. Turning on
        // one of two accounts is a half-open door.
        $staff = User::create([
            'name' => 'Staff', 'email' => 'staff@applicant.test', 'phone' => '+201099990001',
            'password' => 'password', 'status' => 'inactive',
            'role_id' => Role::where('slug', 'laundry_staff')->value('id'),
            'laundry_id' => $laundry->id,
        ]);

        $this->actingAs($this->superAdmin())->post(route('admin.laundry.approve', $laundry->id));

        $this->assertSame('active', $staff->fresh()->status);
    }

    #[Test]
    public function rejecting_keeps_them_out_and_records_why(): void
    {
        $this->post(route('laundry.register.store'), $this->payload());
        $laundry = Laundry::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.laundry.reject', $laundry->id), [
                'rejection_reason' => 'We could not verify the address.',
            ])->assertRedirect();

        $laundry->refresh();
        $this->assertSame('inactive', $laundry->status);
        $this->assertNotNull($laundry->rejected_at);
        $this->assertSame('We could not verify the address.', $laundry->rejection_reason);
        $this->assertFalse($laundry->isPending());

        Mail::assertSent(LaundryApplicationDecided::class);

        auth()->logout();
        $this->post(route('login'), ['email' => 'owner@applicant.test', 'password' => 'a-good-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    #[Test]
    public function approving_a_rejected_application_clears_the_rejection(): void
    {
        $this->post(route('laundry.register.store'), $this->payload());
        $laundry = Laundry::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->superAdmin());
        $this->post(route('admin.laundry.reject', $laundry->id), ['rejection_reason' => 'Missing address']);
        $this->post(route('admin.laundry.approve', $laundry->id));

        $laundry->refresh();
        $this->assertTrue($laundry->isApproved());
        // Otherwise an approved laundry still reads as rejected on its own page.
        $this->assertNull($laundry->rejected_at);
        $this->assertNull($laundry->rejection_reason);
    }

    #[Test]
    public function approving_twice_is_refused(): void
    {
        $this->post(route('laundry.register.store'), $this->payload());
        $laundry = Laundry::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->superAdmin());
        $this->post(route('admin.laundry.approve', $laundry->id));
        $this->post(route('admin.laundry.approve', $laundry->id))->assertSessionHas('error');
    }

    // ---------------------------------------------------------- the queue UI

    #[Test]
    public function the_pending_screen_lists_only_applications(): void
    {
        $this->post(route('laundry.register.store'), $this->payload());

        // An operator-created laundry is approved by the act of creating it,
        // so it must not appear in a queue asking somebody to decide.
        $existing = $this->laundryWithOwner('A', '+201011110001', '+201011110002');

        $this->actingAs($this->superAdmin())
            ->get(route('admin.laundry.pending'))
            ->assertOk()
            ->assertSee('owner@applicant.test')
            ->assertDontSee($existing['owner']->email);
    }

    #[Test]
    public function a_laundry_the_panel_creates_is_approved_by_that_act(): void
    {
        $this->actingAs($this->superAdmin())
            ->post(route('admin.laundry.store'), [
                'name' => ['en' => 'Panel Laundry', 'ar' => 'مغسلة اللوحة'],
                'phone' => '+201055550009',
                'status' => 'active',
                'owner_name' => 'Panel Owner',
                'owner_email' => 'panel@owner.test',
                'owner_phone' => '+201055550008',
                'owner_password' => 'a-good-password',
                'owner_password_confirmation' => 'a-good-password',
            ]);

        $laundry = Laundry::withoutGlobalScopes()->where('phone', '+201055550009')->firstOrFail();

        $this->assertTrue($laundry->isApproved());
        $this->assertSame(0, Laundry::withoutGlobalScopes()->pending()->count());
    }

    #[Test]
    public function the_home_queue_counts_applications(): void
    {
        $this->post(route('laundry.register.store'), $this->payload());

        $this->actingAs($this->superAdmin());

        $item = collect(app(DashboardSummary::class)->needsAPerson())
            ->firstWhere('key', 'laundry_applications');

        $this->assertNotNull($item);
        $this->assertSame(1, $item['count']);
        $this->assertSame('admin.laundry.pending', $item['route']);
    }

    /**
     * What the public form actually posts.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'name' => ['en' => 'Applicant Laundry', 'ar' => 'مغسلة المتقدم'],
            'phone' => '+201066660001',
            'email' => 'hello@applicant.test',
            'address' => '12 Nile Street',
            'city_id' => $this->geo['city']->id,
            'lat' => 30.0444,
            'lng' => 31.2357,
            'owner_name' => 'Applicant Owner',
            'owner_email' => 'owner@applicant.test',
            'owner_phone' => '+201066660002',
            'owner_password' => 'a-good-password',
            'owner_password_confirmation' => 'a-good-password',
            'accepts_terms' => '1',
        ], $extra);
    }
}
