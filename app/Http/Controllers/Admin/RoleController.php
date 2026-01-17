<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Permission;
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

    public function create()
    {
        return view('admin.roles.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        Role::create([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'type' => 'dashboard',
        ]);

        return redirect()->route('admin.roles.index')
            ->with('success', 'Role created successfully');
    }
}
