<?php

namespace App\Modules\Setting\Services;

use App\Modules\Setting\Repositories\SettingRepository;

class settingCrudService
{
    public function __construct(private readonly SettingRepository $repository) {}

    public function updateSettings(array $data)
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Trimmed before encoding. The translatable settings are edited
                // in textareas whose content sits on its own indented line, so
                // every save used to fold that indentation into the value and
                // the next save added it again — the stored text grew a little
                // each time somebody opened the screen. The markup is fixed too;
                // this is the half that protects what is already stored.
                $value = array_map(
                    fn ($translation) => is_string($translation) ? trim($translation) : $translation,
                    $value
                );

                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            } elseif (is_string($value)) {
                $value = trim($value);
            }

            if (in_array($key, ['App_Logo', 'App_Logo_Light', 'Login_Cover'], true)) {
                $existingPath = $this->repository->getByKey($key)?->value;
                $value = uploadOrUpdateImage($value, 'images/setting', $existingPath);
            }

            $this->repository->updateOrCreate($key, $value);

            cache()->forget("setting_$key");
        }
    }
}
