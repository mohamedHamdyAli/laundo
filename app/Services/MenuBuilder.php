<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;

class MenuBuilder
{
    public static function build(): array
    {
        $user = Auth::user();
        if (!$user || !$user->role) {
            return [];
        }

        if ($user->role->slug === 'super_admin') {
            return self::fromAllPermissions();
        }

        return self::fromPermissions(
            $user->role->permissions->pluck('slug')->toArray()
        );
    }

    protected static function fromPermissions(array $permissions): array
    {
        $menu = [];

        foreach ($permissions as $permission) {

            if (!str_ends_with($permission, '.view')) {
                continue;
            }

            [$model] = explode('.', $permission);

            $menu[$model] = self::item($model);
        }

        return array_values($menu);
    }

    protected static function fromAllPermissions(): array
    {
        $permissions = \App\Models\Permission::pluck('slug')->toArray();
        return self::fromPermissions($permissions);
    }

    protected static function item(string $model): array
    {
        return [
            'title' => config("menu.titles.$model", ucfirst($model)),
            'icon'  => config("menu.icons.$model", 'bi bi-circle'),
            'route' => config("menu.routes.$model"),
        ];
    }
}
