<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RoleRequest;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoleController extends Controller
{
    /**
     * Permissions that may not be taken away, and why there is a list at all.
     *
     * A laundry owner signs in to the same panel as an operator and is held
     * inside their own data by the tenant scope, not by the gate — so the
     * handful of screens that *are* their job have to stay reachable. Clearing
     * `laundry.view` locks every owner out of their own record; clearing
     * `order.view` leaves them signed in to a panel with nothing in it, and
     * with no way to put it back, because the screen that would fix it is the
     * one they cannot open.
     *
     * It is a floor, not a freeze: everything else on the laundry roles is
     * yours to set, including whether an owner sees the dispatch board.
     *
     * @var array<int, string>
     */
    public const PROTECTED_SLUGS = [
        'laundry.view',
        'order.view',
    ];

    /**
     * Roles a super admin may edit here.
     *
     * **`type` is not `dashboard` alone.** It was, and the effect was that the
     * screen managed exactly one role — `admin`, which holds no permissions —
     * while `laundry_owner` and `laundry_staff` carried twenty-one between them
     * with no way to reach any of it but `RoleSeeder`. Laundry roles sign in to
     * this panel (`EnsureDashboardRole` admits `dashboard` *and* `laundry`), so
     * they belong on the screen that decides what a panel account can open.
     *
     * `app` stays out: customers and drivers cannot reach the panel at all, and
     * their rows are not permission-driven.
     *
     * `super_admin` stays out for a different reason — it bypasses every check
     * by slug, so its ticks decide nothing and editing them would only suggest
     * otherwise.
     */
    public function index()
    {
        $roles = Role::whereIn('type', ['dashboard', 'laundry'])
            ->where('slug', '!=', 'super_admin')
            ->with('permissions')
            // Panel roles first, laundry roles after. Plain alphabetical `type`
            // already gives that order — and `FIELD()`, which would say it more
            // explicitly, is MySQL-only and the suite runs on SQLite.
            ->orderBy('type')
            ->orderBy('id')
            ->get();

        $permissions = Permission::all()->groupBy('model');

        return view('admin.roles.index', [
            'roles' => $roles,
            'permissions' => $permissions,
            'protectedSlugs' => self::PROTECTED_SLUGS,
        ]);
    }

    /**
     * Writes a role's permission set.
     *
     * The floor is re-added **here**, not only disabled in the form: a disabled
     * checkbox posts nothing, so trusting the markup would have `sync()` drop
     * exactly the permissions the markup was trying to protect. Server-side is
     * also the only side that holds for a hand-made request.
     */
    public function updatePermissions(Request $request, Role $role)
    {
        $slugs = (array) $request->input('permissions', []);

        if ($role->type === 'laundry') {
            $slugs = array_unique([...$slugs, ...self::PROTECTED_SLUGS]);
        }

        $permissionIds = Permission::whereIn('slug', $slugs)->pluck('id');

        $role->permissions()->sync($permissionIds);

        return redirect()
            ->back()
            ->with('success', __('Permissions updated successfully'));
    }

    public function store(RoleRequest $request)
    {
        $validated = $request->validated();

        try {
            Role::create([
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name']),
                'type' => 'dashboard',
            ]);
        } catch (UniqueConstraintViolationException $e) {
            return back()
                ->withInput()
                ->with('error', __('A role with this name already exists.'));
        }

        return redirect()->route('admin.roles.index')
            ->with('success', 'Role created successfully');
    }
}
