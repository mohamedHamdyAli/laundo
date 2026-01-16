<?php

namespace App\Modules\Setting\Services;

use App\Modules\Setting\Repositories\SettingRepository;

class settingCrudService
{
    public function __construct(private readonly SettingRepository $repository)
    {
    }

    public function updateSettings(array $data)
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }

            if ($key === 'App_Logo' || $key === 'Login_Cover') {
                $existingPath = $this->repository->getByKey($key)?->value;
                $value = uploadOrUpdateImage($value, 'images/setting', $existingPath);
            }

            $this->repository->updateOrCreate($key, $value);

            cache()->forget("setting_$key");
        }
    }
}
