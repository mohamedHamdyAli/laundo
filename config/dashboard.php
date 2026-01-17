<?php

use App\Models\Language;
use App\Models\Role;
use App\Modules\Banner\Models\banner;
use App\Modules\Category\Models\Category;
use App\Modules\City\Models\City;
use App\Modules\Country\Models\Country;
use App\Modules\Intro\Models\intro;
use App\Modules\Setting\Models\Setting;
use App\Modules\User\Models\User;

return [
    'models' => [
        User::class,
        Category::class,
        banner::class,
        Language::class,
        City::class,
        Country::class,
        intro::class,
        Setting::class,
        Role::class,
    ],
];
