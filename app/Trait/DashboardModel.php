<?php

namespace App\Trait;

use Illuminate\Support\Str;

trait DashboardModel
{
    public static function dashboardModelName(): string
    {
        return Str::snake(class_basename(static::class));
    }
}
