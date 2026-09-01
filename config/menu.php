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
            'icon' => 'bi bi-geo-alt-fill',
            'items' => [
                'country' => 1,
                'city' => 2,
                'zone' => 3,
            ],
        ],

        'content' => [
            'order' => 2,
            'title' => 'Content',
            'icon' => 'bi bi-collection',
            'items' => [
                'banner' => 1,
                'intro' => 2,
                'faq' => 3,
            ],
        ],

        'catalog' => [
            'order' => 3,
            'title' => 'Catalog',
            'icon' => 'bi bi-tags-fill',
            'items' => [
                'service' => 1,
                'item_category' => 2,
                'item' => 3,
                'item_price' => 4,
            ],
        ],

        'reports' => [
            'order' => 5,
            'title' => 'Reports',
            'icon' => 'bi bi-graph-up',
            'items' => [
                'report' => 1,
                'order_recurrence' => 2,
                'order_rating' => 3,
                'complaint' => 4,
            ],
        ],

        'money' => [
            'order' => 4,
            'title' => 'Money',
            'icon' => 'bi bi-cash-stack',
            'items' => [
                'coupon' => 1,
                'refund' => 2,
                'wallet' => 3,
                'payment' => 4,
                'driver_earning' => 5,
                'notification_log' => 4,
            ],
        ],

        'settings' => [
            'order' => 99,
            'title' => 'Settings',
            'icon' => 'bi bi-gear-fill',
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
        'order' => 1,
        'user' => 3,
        'language' => 5,
        'role' => 6,
        'moderator' => 7,
        'laundry' => 8,
        'laundry_staff' => 9,
        'driver' => 9.5,
        'laundry_service' => 10,
        'laundry_zone' => 11,
        'time_slot' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | UI Mapping
    |--------------------------------------------------------------------------
    */

    'icons' => [
        'user' => 'bi bi-people',
        'category' => 'bi bi-list-task',
        'banner' => 'bi bi-image',
        'intro' => 'bi bi-collection-play',
        'faq' => 'bi bi-question-circle',
        'country' => 'bi bi-globe2',
        'city' => 'bi bi-geo-alt',
        'language' => 'fas fa-language',
        'setting' => 'bi bi-gear-fill',
        'role' => 'bi bi-shield-lock',
        'moderator' => 'bi bi-person-badge',
        'laundry' => 'bi bi-shop',
        'laundry_staff' => 'bi bi-person-workspace',
        'driver' => 'bi bi-truck',
        'service' => 'bi bi-droplet-half',
        'item_category' => 'bi bi-collection-fill',
        'item' => 'bi bi-tag',
        'item_price' => 'bi bi-cash-coin',
        'laundry_service' => 'bi bi-ui-checks',
        'zone' => 'bi bi-pin-map',
        'time_slot' => 'bi bi-clock-history',
        'laundry_zone' => 'bi bi-geo',
        'order' => 'bi bi-receipt',
        'coupon' => 'bi bi-ticket-perforated',
        'refund' => 'bi bi-arrow-counterclockwise',
        'wallet' => 'bi bi-wallet2',
        'payment' => 'bi bi-credit-card',
        'driver_earning' => 'bi bi-cash-stack',
        'notification_log' => 'bi bi-bell',
        'report' => 'bi bi-bar-chart-line',
        'order_recurrence' => 'bi bi-arrow-repeat',
        'order_rating' => 'bi bi-star',
        'complaint' => 'bi bi-exclamation-circle',
    ],

    'titles' => [
        'user' => 'Users',
        'category' => 'Categories',
        'banner' => 'Banners',
        'intro' => 'Intros',
        'faq' => 'FAQ',
        'country' => 'Countries',
        'city' => 'Cities',
        'language' => 'Languages',
        'setting' => 'Settings',
        'role' => 'Roles',
        'moderator' => 'Moderators',
        'laundry' => 'Laundries',
        'laundry_staff' => 'Laundry Staff',
        'driver' => 'Drivers',
        'service' => 'Services',
        'item_category' => 'Item Categories',
        'item' => 'Items',
        'item_price' => 'Prices',
        'laundry_service' => 'My Services',
        'zone' => 'Zones',
        'time_slot' => 'Time Slots',
        'laundry_zone' => 'My Areas',
        'order' => 'Orders',
        'coupon' => 'Discount Codes',
        'refund' => 'Refunds',
        'wallet' => 'Wallets',
        'payment' => 'Payments',
        'driver_earning' => 'Driver Earnings',
        'notification_log' => 'Notification Log',
        'report' => 'Reports',
        'order_recurrence' => 'Repeat Schedules',
        'order_rating' => 'Ratings',
        'complaint' => 'Complaints',
    ],

    'routes' => [
        'user' => 'admin.user.index',
        'category' => 'admin.category.index',
        'banner' => 'admin.banner.index',
        'intro' => 'admin.intro.index',
        'faq' => 'admin.faq.index',
        'country' => 'admin.country.index',
        'city' => 'admin.city.index',
        'language' => 'admin.language.index',
        'setting' => 'admin.generalSetting.viewGeneralSetting',
        'role' => 'admin.roles.index',
        'moderator' => 'admin.moderator.index',
        'laundry' => 'admin.laundry.index',
        'laundry_staff' => 'admin.laundry_staff.index',
        'driver' => 'admin.driver.index',
        'service' => 'admin.service.index',
        'item_category' => 'admin.item_category.index',
        'item' => 'admin.item.index',
        'item_price' => 'admin.pricing.index',
        'laundry_service' => 'admin.laundry_service.index',
        'zone' => 'admin.zone.index',
        'time_slot' => 'admin.time_slot.index',
        'laundry_zone' => 'admin.laundry_zone.index',
        'order' => 'admin.order.index',
        'coupon' => 'admin.coupon.index',
        'refund' => 'admin.refund.index',
        'wallet' => 'admin.wallet.index',
        'payment' => 'admin.payment.index',
        'driver_earning' => 'admin.earning.index',
        'notification_log' => 'admin.notification.index',
        'report' => 'admin.report.revenue',
        'order_recurrence' => 'admin.recurrence.index',
        'order_rating' => 'admin.rating.index',
        'complaint' => 'admin.complaint.index',
    ],
];
