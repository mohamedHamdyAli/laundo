<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Assigning a driver moves from `order.update` to `order_task.update`.
 *
 * The old gate was the wrong boundary in both directions. A laundry owner holds
 * `order.update` so it can review and price its own orders — and that quietly
 * let it hand a leg to a driver, take one off him, and re-run the dispatch
 * sweep. Which driver carries which order is the platform's to decide, and a
 * laundry choosing is a laundry picking who it likes and declining the rest.
 * Meanwhile `driver_supervisor`, the role that exists for exactly this work,
 * holds `order_task.update` and *not* `order.update`, so it could open the
 * dispatch board and act on nothing.
 *
 * Moving the gate fixes both, but it would also lock out any dashboard role an
 * operator had already given `order.update` to — `admin` on this install is
 * seeded with no permissions at all and was granted them by hand. So the
 * implicit is made explicit before the fallback is removed, the same way the
 * commission rates were: every **dashboard** role that can act on an order
 * today keeps being able to act on its legs tomorrow.
 *
 * `laundry` roles are deliberately excluded, and that is the whole point of the
 * migration. They keep `order_task.view` — a laundry seeing that a driver is on
 * the way to it is useful and harms nobody — and lose the buttons.
 */
return new class extends Migration
{
    public function up(): void
    {
        $target = DB::table('permissions')->where('slug', 'order_task.update')->value('id');
        $source = DB::table('permissions')->where('slug', 'order.update')->value('id');

        if ($target === null || $source === null) {
            // A fresh install migrating before the permission seeder has run.
            // `RoleSeeder` already grants the right set, so there is nothing to
            // carry across.
            return;
        }

        $roleIds = DB::table('permission_role')
            ->join('roles', 'roles.id', '=', 'permission_role.role_id')
            ->where('permission_role.permission_id', $source)
            // Dashboard only. A `laundry` role reaching this point is exactly
            // what the change exists to stop, and `app` roles never see /admin.
            ->where('roles.type', 'dashboard')
            ->pluck('roles.id');

        foreach ($roleIds as $roleId) {
            $already = DB::table('permission_role')
                ->where('role_id', $roleId)
                ->where('permission_id', $target)
                ->exists();

            if ($already) {
                continue;
            }

            DB::table('permission_role')->insert([
                'role_id' => $roleId,
                'permission_id' => $target,
            ]);
        }
    }

    public function down(): void
    {
        // Deliberately not reversed. Withdrawing the permission would leave the
        // roles that legitimately dispatch unable to, and the old gate it would
        // be falling back to no longer exists in the routes.
    }
};
