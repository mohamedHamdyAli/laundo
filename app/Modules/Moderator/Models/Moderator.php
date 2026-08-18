<?php

namespace App\Modules\Moderator\Models;

use App\Modules\User\Models\User;
use App\Trait\DashboardModel;

class Moderator extends User
{
    use DashboardModel;

    protected $table = 'users';

    protected static function booted(): void
    {
        static::addGlobalScope('moderators', function ($query) {
            $query->whereHas(
                'role',
                fn ($q) => $q->where('type', 'dashboard')->where('slug', '!=', 'super_admin')
            );
        });
    }
}
