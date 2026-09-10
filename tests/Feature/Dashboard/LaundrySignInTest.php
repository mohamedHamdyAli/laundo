<?php

namespace Tests\Feature\Dashboard;

use App\Models\Role;
use App\Modules\Laundry\Models\Laundry;
use App\Modules\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The laundry's way in, and the password behind it.
 *
 * A laundry owner has been able to sign in since `EnsureDashboardRole` began
 * admitting `role.type = laundry` — but the only address was `/login`, headed
 * «Admin Control Panel» over the words "your admin account". An owner handed an
 * account had nothing telling them it was theirs, and no link anywhere to start
 * from.
 *
 * Three things are pinned here, and the first is the one that matters most:
 *
 *   1. **The second door is a door, not a second lock.** `/laundry/login` posts
 *      to the same `login` route. If a future change gives it a controller of
 *      its own, throttling and the session redirect grow a second implementation
 *      and one of them will drift.
 *   2. **A blank password box leaves the account alone.** The laundry edit form
 *      submits `owner_password` on every save; treating empty as "set it" would
 *      wipe an owner's password every time somebody corrected a phone number.
 *   3. **The password pages do not need Vite.** They used to extend
 *      `layouts.app`, the panel's only Vite chain, while nothing linked to them
 *      — so the missing-manifest 500 was invisible. The login screen links to
 *      them now.
 */
class LaundrySignInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seedCore();
    }

    #[Test]
    public function the_laundry_door_exists_and_says_whose_it_is(): void
    {
        $response = $this->get('/laundry/login');

        $response->assertOk()
            ->assertSee(__('Your laundry, signed in'))
            // The whole point of the page: it must not read as the admin panel.
            ->assertDontSee(__('Sign in to your admin account to continue'));
    }

    #[Test]
    public function it_is_a_second_door_and_not_a_second_lock(): void
    {
        // One authentication path. A form posting anywhere else would mean
        // throttling, the session and the /admin/home redirect exist twice.
        $this->get('/laundry/login')
            ->assertOk()
            ->assertSee('action="'.route('login').'"', false);
    }

    #[Test]
    public function the_admin_login_points_at_the_laundry_door(): void
    {
        // One-way on purpose. The admin screen offers the other door because a
        // laundry owner may well land there; the laundry screen does not offer
        // the admin one, because nobody arriving at it is staff.
        $this->get('/login')->assertOk()->assertSee(route('laundry.login'), false);
        $this->get('/laundry/login')->assertOk()->assertDontSee(__('Sign in to the admin panel'));
    }

    #[Test]
    public function a_signed_in_user_is_not_shown_the_laundry_door(): void
    {
        $tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');

        $this->actingAs($tenant['owner'])
            ->get('/laundry/login')
            ->assertRedirect(route('home'));
    }

    #[Test]
    public function an_owner_signing_in_from_their_own_door_reaches_the_panel(): void
    {
        $tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');

        $this->post(route('login'), [
            'email' => $tenant['owner']->email,
            'password' => 'password',
        ])->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($tenant['owner']);

        // And the gate really does let a `laundry` role through to /admin.
        $this->get(route('home'))->assertOk();
    }

    #[Test]
    public function the_login_screen_offers_a_way_out_of_a_forgotten_password(): void
    {
        $this->get('/login')->assertOk()->assertSee(route('password.request'), false);
        $this->get('/laundry/login')->assertOk()->assertSee(route('password.request'), false);
    }

    #[Test]
    public function the_password_pages_render_without_a_vite_build(): void
    {
        // `layouts.app` would throw a ViteManifestNotFoundException here, which
        // is exactly the 500 a deploy that skipped `npm run build` used to serve.
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee(__('Reset your password'));

        $this->get(route('password.reset', ['token' => 'a-token']))
            ->assertOk()
            ->assertSee(__('Set a new password'));
    }

    // ------------------------------------------------ the owner's password

    #[Test]
    public function a_laundry_knows_which_account_owns_it(): void
    {
        $tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');

        // A staff account on the same laundry must not be mistaken for the owner.
        User::create([
            'name' => 'Staff', 'email' => 'staff@test.local', 'phone' => '+201011110003',
            'password' => 'password', 'status' => 'active',
            'role_id' => Role::where('slug', 'laundry_staff')->value('id'),
            'laundry_id' => $tenant['laundry']->id,
        ]);

        $this->assertSame($tenant['owner']->id, $tenant['laundry']->fresh()->owner?->id);
    }

    #[Test]
    public function the_edit_screen_offers_to_reset_the_owners_password(): void
    {
        $tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');

        $this->actingAs($this->superAdmin())
            ->get(route('admin.laundry.edit', $tenant['laundry']->id))
            ->assertOk()
            ->assertSee('name="owner_password"', false)
            // Named, so nobody resets the wrong person's password.
            ->assertSee($tenant['owner']->email);
    }

    #[Test]
    public function saving_a_new_password_changes_the_owners(): void
    {
        $tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');

        $this->actingAs($this->superAdmin())
            ->put(route('admin.laundry.update', $tenant['laundry']->id), $this->payload($tenant['laundry'], [
                'owner_password' => 'a-brand-new-one',
                'owner_password_confirmation' => 'a-brand-new-one',
            ]));

        $this->assertTrue(Hash::check('a-brand-new-one', $tenant['owner']->fresh()->password));
    }

    #[Test]
    public function a_blank_password_box_leaves_the_account_alone(): void
    {
        $tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $before = $tenant['owner']->password;

        $this->actingAs($this->superAdmin())
            ->put(route('admin.laundry.update', $tenant['laundry']->id), $this->payload($tenant['laundry'], [
                // What the form actually submits when nobody typed in it.
                'owner_password' => '',
                'owner_password_confirmation' => '',
            ]));

        $this->assertSame($before, $tenant['owner']->fresh()->password);
        $this->assertTrue(Hash::check('password', $tenant['owner']->fresh()->password));
    }

    #[Test]
    public function a_mistyped_confirmation_is_refused(): void
    {
        $tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $before = $tenant['owner']->password;

        $this->actingAs($this->superAdmin())
            ->put(route('admin.laundry.update', $tenant['laundry']->id), $this->payload($tenant['laundry'], [
                'owner_password' => 'a-brand-new-one',
                'owner_password_confirmation' => 'a-brand-new-two',
            ]))
            ->assertSessionHasErrors('owner_password');

        $this->assertSame($before, $tenant['owner']->fresh()->password);
    }

    #[Test]
    public function a_short_password_is_refused(): void
    {
        $tenant = $this->laundryWithOwner('A', '+201011110001', '+201011110002');

        $this->actingAs($this->superAdmin())
            ->put(route('admin.laundry.update', $tenant['laundry']->id), $this->payload($tenant['laundry'], [
                'owner_password' => 'short',
                'owner_password_confirmation' => 'short',
            ]))
            ->assertSessionHasErrors('owner_password');
    }

    #[Test]
    public function an_owner_cannot_reach_another_laundrys_edit_screen(): void
    {
        $mine = $this->laundryWithOwner('A', '+201011110001', '+201011110002');
        $theirs = $this->laundryWithOwner('B', '+201011110004', '+201011110005');

        // The tenant scope, not the permission: this owner holds laundry.update.
        $this->actingAs($mine['owner'])
            ->get(route('admin.laundry.edit', $theirs['laundry']->id))
            ->assertNotFound();
    }

    /**
     * The laundry form posts every field on every save; a partial payload would
     * test a request this screen never sends.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(Laundry $laundry, array $extra = []): array
    {
        return array_merge([
            'id' => $laundry->id,
            'name' => ['en' => 'Laundry A', 'ar' => 'مغسلة أ'],
            'phone' => $laundry->phone,
            'email' => $laundry->email,
            'status' => 'active',
        ], $extra);
    }
}
