<?php

namespace Tests\Feature\Dashboard;

use App\Http\Controllers\Admin\RoleController;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «الأدوار» — and the roles it was quietly leaving out.
 *
 * The screen filtered on `type = 'dashboard'`, so it managed exactly one role:
 * `admin`, which holds no permissions. `laundry_owner` and `laundry_staff`
 * carried twenty-one between them and there was no way to reach any of it
 * except by editing `RoleSeeder` — which is why a laundry owner seeing the
 * dispatch board could not simply be switched off.
 *
 * They sign in to this panel: `EnsureDashboardRole` admits `dashboard` **and**
 * `laundry`. If a role can open the panel, the screen that decides what a panel
 * account may open has to list it.
 *
 * With one floor. A laundry account is held inside its own data by the tenant
 * scope rather than by the gate, so the two screens that *are* its job must stay
 * reachable — clearing them signs an owner in to an empty panel with no way back,
 * because the screen that would fix it is the one they just lost.
 */
class RolePermissionScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    private function screen()
    {
        return $this->actingAs($this->superAdmin())->get(route('admin.roles.index'));
    }

    #[Test]
    public function the_laundry_roles_are_on_the_screen(): void
    {
        $html = $this->screen()->assertOk()->getContent();

        $this->assertStringContainsString('Laundry Owner', $html);
        $this->assertStringContainsString('Laundry Staff', $html);
    }

    #[Test]
    public function app_roles_stay_off_it(): void
    {
        // Customers and drivers cannot reach the panel at all, and nothing they
        // do is permission-driven. Listing them would be five more rows of ticks
        // that decide nothing.
        $html = $this->screen()->getContent();

        $this->assertStringNotContainsString('>Driver<', $html);
    }

    #[Test]
    public function the_super_admin_is_not_listed(): void
    {
        // It bypasses every check by slug, so its ticks decide nothing — and
        // showing them would suggest otherwise.
        $this->assertStringNotContainsString('Super Admin', $this->screen()->getContent());
    }

    #[Test]
    public function an_owners_permissions_can_actually_be_changed(): void
    {
        // The point of the exercise: the dispatch board is now switchable from
        // the screen rather than only from a seeder.
        $role = Role::where('slug', 'laundry_owner')->firstOrFail();

        $keep = $role->permissions->pluck('slug')
            ->reject(fn (string $slug) => $slug === 'order_task.view')
            ->values()
            ->all();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.roles.permissions.update', $role), ['permissions' => $keep])
            ->assertRedirect();

        $this->assertNotContains('order_task.view', $role->fresh()->permissions->pluck('slug')->all());
    }

    #[Test]
    public function the_floor_cannot_be_cleared_even_by_a_hand_made_request(): void
    {
        // A disabled checkbox posts nothing, so the markup alone would have
        // `sync()` drop exactly what the markup was trying to protect. This
        // sends the empty set the browser would send.
        $role = Role::where('slug', 'laundry_owner')->firstOrFail();

        $this->actingAs($this->superAdmin())
            ->post(route('admin.roles.permissions.update', $role), ['permissions' => []])
            ->assertRedirect();

        $slugs = $role->fresh()->permissions->pluck('slug')->all();

        foreach (RoleController::PROTECTED_SLUGS as $slug) {
            $this->assertContains($slug, $slugs, "«{$slug}» is the floor and was cleared");
        }
    }

    #[Test]
    public function the_floor_does_not_apply_to_a_panel_role(): void
    {
        // It exists because a laundry account is confined by the tenant scope
        // rather than by the gate. A moderator with no permissions is simply a
        // moderator who has not been given any, and a super admin can still see
        // and fix them.
        $admin = Role::where('slug', 'admin')->firstOrFail();
        $admin->permissions()->sync(Permission::whereIn('slug', ['laundry.view', 'order.view'])->pluck('id'));

        $this->actingAs($this->superAdmin())
            ->post(route('admin.roles.permissions.update', $admin), ['permissions' => []]);

        $this->assertCount(0, $admin->fresh()->permissions);
    }

    #[Test]
    public function the_floor_is_drawn_locked_rather_than_merely_unchecked(): void
    {
        $html = $this->screen()->getContent();

        // Ticked and disabled: an operator should see that it is on and that it
        // is not theirs to turn off, rather than find out by it coming back.
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringContainsString(__('This role cannot work without this.'), $html);
    }

    #[Test]
    public function the_screen_says_a_laundry_role_is_global(): void
    {
        // Roles are not per-laundry. Somebody editing «Laundry Owner» with one
        // shop in mind is moving all of them.
        $this->assertStringContainsString(
            __('Applies to every laundry on the platform, not to one.'),
            $this->screen()->getContent()
        );
    }

    #[Test]
    public function it_still_needs_the_permission(): void
    {
        $this->actingAs($this->customer())
            ->get(route('admin.roles.index'))
            ->assertForbidden();
    }
}
