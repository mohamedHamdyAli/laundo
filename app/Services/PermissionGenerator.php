<?php

namespace App\Services;

use App\Models\Permission;
use App\Trait\DashboardModel;

class PermissionGenerator
{
    protected array $actions = ['view', 'create', 'update', 'delete', 'toggle'];

    public function generate(): void
    {
        foreach (config('dashboard.models', []) as $key => $model) {

            if (!in_array(DashboardModel::class, class_uses($model))) {
                continue;
            }

            $modelName = $model::dashboardModelName();

            foreach ($this->actions as $action) {
                Permission::firstOrCreate(
                    ['slug' => "{$modelName}.{$action}"],
                    [
                        'name'   => ucfirst($modelName) . ' ' . ucfirst($action),
                        'model'  => $modelName,
                        'action' => $action,
                    ]
                );
            }
        }
    }
}
