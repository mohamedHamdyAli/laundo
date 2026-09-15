<?php

namespace Tests\Feature\Dashboard;

use App\Models\Role;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The payer does not get the dial.
 *
 * A laundry owner holds `laundry.update` by design — they keep their own shop's
 * details current — so gating the platform's commission on that permission would
 * hand the party that *pays* the charge the control over what it is. Money terms
 * are gated on `setting.update` instead, which no laundry role holds.
 *
 * Two halves, and both matter:
 *
 * 1. The route refuses. That is the gate.
 * 2. The sidebar does not offer it. A menu item that leads to a 403 is a bug of
 *    its own — it teaches an operator that the panel is broken rather than that
 *    the screen is not theirs, and it is the half that quietly regresses when
 *    somebody widens a role to fix an unrelated complaint.
 */
class MoneyBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedCore();
        $this->seedGeo();
    }

    #[Test]
    public function the_owner_role_does_not_hold_the_commission_permission(): void
    {
        // The shipped grant, not a fixture. `seedCore()` creates the roles bare
        // — tests attach what they need with `grant()` — so asserting anything
        // about what a role *holds* means running the seeders that decide it.
        // Which is the point: this test exists to catch somebody widening the
        // laundry owner's set in `RoleSeeder` to fix an unrelated complaint.
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $role = Role::where('slug', 'laundry_owner')->firstOrFail();
        $slugs = $role->permissions()->pluck('slug')->all();

        $this->assertNotContains('commission_rule.view', $slugs);
        $this->assertNotContains('commission_rule.update', $slugs);

        // The pair it *should* hold: what they are owed, not what they are
        // charged. Money «Applications» is a dropdown of one for them.
        $this->assertContains('order_settlement.view', $slugs);
    }

    #[Test]
    public function an_owner_is_refused_the_commission_screen(): void
    {
        $owner = $this->laundryWithOwner('A', '+201011110001', '+201011110002')['owner'];

        $this->actingAs($owner)->get('/admin/commission-rule')->assertForbidden();
    }

    #[Test]
    public function the_sidebar_never_offers_an_owner_a_screen_they_cannot_open(): void
    {
        // The half that produced the report: a 403 is only a good answer to a
        // typed URL. Reached from the menu it reads as a broken panel.
        $owner = $this->laundryWithOwner('B', '+201022220001', '+201022220002')['owner'];

        $html = $this->actingAs($owner)->get('/admin/home')->assertOk()->getContent();

        $this->assertStringNotContainsString('/admin/commission-rule', $html);
    }

    #[Test]
    public function a_super_admin_still_has_both(): void
    {
        // The bypass is the point of the role, and a gate nobody can pass is
        // just a broken screen.
        $this->actingAs($this->superAdmin())
            ->get('/admin/commission-rule')
            ->assertOk();
    }
}
