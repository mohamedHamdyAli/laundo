<?php

namespace App\Services\setting;

use App\Models\Setting;

class settingCrudService
{
    public function updateRecord($request)
    {

        foreach ($request as $key => $value) {
            if (is_array($value)) {
                $request[$key] = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
        }
        foreach (array_filter($request) as $key => $value) {
            if ($key === 'App_Logo') {
                $existingPath = getSettingValue('App_Logo');
                $value = uploadOrUpdateImage($request['App_Logo'], 'images/setting', $existingPath);
            }

            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
