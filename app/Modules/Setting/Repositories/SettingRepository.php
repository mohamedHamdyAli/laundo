<?php

namespace App\Modules\Setting\Repositories;

use App\Modules\Setting\Models\Setting;

class SettingRepository
{
    public function updateOrCreate($key, $value)
    {
        return Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public function getByKey($key)
    {
        return Setting::where('key', $key)->first();
    }
}
