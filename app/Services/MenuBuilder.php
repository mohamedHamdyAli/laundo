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

        $permissions = $user->role->slug === 'super_admin'
            ? \App\Models\Permission::pluck('slug')->toArray()
            : $user->role->permissions->pluck('slug')->toArray();

        $models = self::extractModels($permissions);

        return self::buildMenu($models);
    }

    protected static function extractModels(array $permissions): array
    {
        return collect($permissions)
            ->filter(fn($p) => str_ends_with($p, '.view'))
            ->map(fn($p) => explode('.', $p)[0])
            ->unique()
            ->values()
            ->toArray();
    }

    protected static function buildMenu(array $models): array
    {
        $menu = collect();

        // Groups
        foreach (config('menu.groups') as $group) {

            $items = collect($group['items'])
                ->filter(fn($order, $model) => in_array($model, $models))
                ->sortBy(fn($order) => $order)
                ->map(fn($order, $model) => self::item($model))
                ->values();

            if ($items->isEmpty()) {
                continue;
            }

            $menu->push([
                'type'  => 'group',
                'order' => $group['order'],
                'title' => $group['title'],
                'icon'  => $group['icon'],
                'items' => $items->toArray(),
            ]);
        }

        // Singles
        foreach (config('menu.singles') as $model => $order) {
            if (!in_array($model, $models)) {
                continue;
            }

            $menu->push(array_merge(
                self::item($model),
                ['order' => $order]
            ));
        }

        return $menu
            ->sortBy('order')
            ->values()
            ->toArray();
    }


    protected static function item(string $model): array
    {
        return [
            'type'  => 'item',
            'title' => config("menu.titles.$model"),
            'icon'  => config("menu.icons.$model"),
            'route' => config("menu.routes.$model"),
        ];
    }
}
