<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RoleRequest;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Str;

class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::where('type', 'dashboard')
            ->where('slug', '!=', 'super_admin')
            ->with('permissions')
            ->get();

        $permissions = Permission::all()->groupBy('model');

        return view('admin.roles.index', compact('roles', 'permissions'));
    }


    public function updatePermissions(Request $request, Role $role)
    {
        $permissionIds = Permission::whereIn(
            'slug',
            $request->input('permissions', [])
        )->pluck('id');

        $role->permissions()->sync($permissionIds);

        return redirect()
            ->back()
            ->with('success', 'Permissions updated successfully');
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
