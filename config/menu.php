<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Menu Groups (Dropdowns)
    |--------------------------------------------------------------------------
    */

    'groups' => [

        'locations' => [
            'order' => 1,
            'title' => 'Locations',
            'icon'  => 'bi bi-geo-alt-fill',
            'items' => [
                'country' => 1,
                'city'    => 2,
            ],
        ],

        'content' => [
            'order' => 2,
            'title' => 'Content',
            'icon'  => 'bi bi-collection',
            'items' => [
                'banner' => 1,
                'intro'  => 2,
            ],
        ],

        'settings' => [
            'order' => 99,
            'title' => 'Settings',
            'icon'  => 'bi bi-gear-fill',
            'items' => [
                'setting' => 1,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Single Menu Items
    |--------------------------------------------------------------------------
    */

    'singles' => [
        'dashboard' => 0,
        'user'      => 3,
        'category'  => 4,
        'language'  => 5,
        'role'      => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | UI Mapping
    |--------------------------------------------------------------------------
    */

    'icons' => [
        'user'     => 'bi bi-people',
        'category' => 'bi bi-list-task',
        'banner'   => 'bi bi-image',
        'intro'    => 'bi bi-collection-play',
        'country'  => 'bi bi-globe2',
        'city'     => 'bi bi-geo-alt',
        'language' => 'fas fa-language',
        'setting'  => 'bi bi-gear-fill',
        'role'     => 'bi bi-shield-lock',
    ],

    'titles' => [
        'user'     => 'Users',
        'category' => 'Categories',
        'banner'   => 'Banners',
        'intro'    => 'Intros',
        'country'  => 'Countries',
        'city'     => 'Cities',
        'language' => 'Languages',
        'setting'  => 'Settings',
        'role'     => 'Roles',
    ],

    'routes' => [
        'user'     => 'admin.user.index',
        'category' => 'admin.category.index',
        'banner'   => 'admin.banner.index',
        'intro'    => 'admin.intro.index',
        'country'  => 'admin.country.index',
        'city'     => 'admin.city.index',
        'language' => 'admin.language.index',
        'setting'  => 'admin.generalSetting.viewGeneralSetting',
        'role'     => 'admin.roles.index',
    ],
];
