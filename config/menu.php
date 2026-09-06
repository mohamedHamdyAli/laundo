<?php

/*
|--------------------------------------------------------------------------
| The sidebar
|--------------------------------------------------------------------------
|
| Two rules decide where a screen goes, and they are worth stating because the
| previous arrangement followed neither and drifted as modules were added.
|
| 1. **The list reads in the order the platform is built.** The owner's decision:
|    what everything else rests on comes first, so the menu can be walked top to
|    bottom to stand a working platform up. Locations before Catalog because a
|    zone prices delivery; Catalog before Laundries because a laundry picks the
|    services it offers from it; Laundries before Delivery, and both before the
|    Orders they produce. Operations, Money and Reports are the consequences and
|    follow. Settings stays pinned last at 99.
|
| 2. **Things that depend on each other share a list.** Country -> City -> Zone
|    and Service -> Category -> Item -> Price are chains: you cannot fill in the
|    fourth without the first three, so hunting them across two dropdowns is the
|    wrong shape. Same for the pairs one person manages together - a laundry with
|    its staff, services and areas; a driver with their earnings; and Offers
|    beside Discount Codes, which the order code treats as one decision (they are
|    mutually exclusive on an order) while the menu had them three groups apart.
|
| `MenuBuilder` intersects these keys with the signed-in role's `*.view`
| permissions, so a restricted role sees a subset. That is why the grouping has
| to read correctly when most of it is missing: a laundry owner's four screens
| now sit in one dropdown instead of scattered as four loose singles.
|
| A key needs an entry in `icons`, `titles` **and** `routes` or it renders as a
| blank row. `TranslationCoverageTest` fails the build on both that and a title
| with no `ar.json` entry.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Menu Groups (Dropdowns)
    |--------------------------------------------------------------------------
    |
    | Declared in the order they render, so the file reads the way the sidebar
    | does and a wrong `order` is visible rather than something to work out.
    |
    */

    'groups' => [

        // The ground everything stands on. Country -> City -> Zone, in the order
        // they have to be filled in: each row needs its parent, and a zone is
        // also what prices a delivery.
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

        // Service -> Category -> Item -> Price, same reasoning: a price cannot
        // exist without an item and a service. Second because a laundry picks
        // the services it offers out of this list.
        'catalog' => [
            'order' => 2,
            'title' => 'Catalog',
            'icon' => 'bi bi-tags-fill',
            'items' => [
                'service' => 1,
                'item_category' => 2,
                'item' => 3,
                'item_price' => 4,
            ],
        ],

        // A laundry and the three things that belong to it. These four were
        // loose singles, which meant the laundry owner - whose whole panel is
        // exactly this list - met them spread down the sidebar.
        'laundries' => [
            'order' => 3,
            'title' => 'Laundries',
            'icon' => 'bi bi-shop-window',
            'items' => [
                'laundry' => 1,
                'laundry_staff' => 2,
                'laundry_service' => 3,
                'laundry_zone' => 4,
            ],
        ],

        // Earnings are a driver's, not the treasury's: they are read while
        // looking at the driver, so they sit with the driver.
        'delivery' => [
            'order' => 4,
            'title' => 'Delivery',
            'icon' => 'bi bi-truck-front',
            'items' => [
                'driver' => 1,
                'driver_earning' => 2,
            ],
        ],

        // The last thing set up before orders start arriving: what the customer
        // is shown, and what they are offered. Offers and Discount Codes lead
        // because they are the two halves of one rule - one discount per order,
        // the offer wins - and reading them apart is how somebody publishes a
        // code that can never be redeemed.
        'marketing' => [
            'order' => 6,
            'title' => 'Marketing',
            'icon' => 'bi bi-megaphone',
            'items' => [
                'offer' => 1,
                'coupon' => 2,
                'banner' => 3,
                'intro' => 4,
                'journey_step' => 5,
                'faq' => 6,
            ],
        ],

        // Everything that hangs off an order, so it follows Orders. A complaint
        // is first because it is the only one with a customer waiting at the
        // other end of it.
        'operations' => [
            'order' => 8,
            'title' => 'Operations',
            'icon' => 'bi bi-clipboard-check',
            'items' => [
                'complaint' => 1,
                'order_rating' => 2,
                'order_recurrence' => 3,
                'time_slot' => 4,
            ],
        ],

        // Money that moved, and the balances it moved into.
        'money' => [
            'order' => 9,
            'title' => 'Money',
            'icon' => 'bi bi-cash-stack',
            'items' => [
                'payment' => 1,
                'refund' => 2,
                'wallet' => 3,
            ],
        ],

        // Who may use the panel, what it speaks, what it sent, how it behaves.
        // Pinned last: nothing here is part of standing the platform up, and
        // nothing here is opened during a working day.
        'system' => [
            'order' => 99,
            'title' => 'System',
            'icon' => 'bi bi-sliders',
            'items' => [
                'moderator' => 1,
                'role' => 2,
                'language' => 3,
                'notification_log' => 4,
                'setting' => 5,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Single Menu Items
    |--------------------------------------------------------------------------
    |
    | Three screens that answer to nothing else and are opened on their own.
    | Burying any of them one click deep would cost more than the tidiness.
    | Their numbers interleave with the groups above: Users after Delivery,
    | Orders once everything that makes one exists, Reports after the money.
    |
    | Dashboard is not here: `layouts/sidebar.blade.php` renders it directly, and
    | no model generates a `dashboard.view` permission for it to match, so the
    | entry that used to sit here could never render.
    |
    */

    'singles' => [
        'user' => 5,
        'order' => 7,
        'report' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | UI Mapping
    |--------------------------------------------------------------------------
    |
    | Kept in the reading order of the menu above, so a missing entry is visible
    | rather than something to search for.
    |
    */

    'icons' => [
        'country' => 'bi bi-globe2',
        'city' => 'bi bi-geo-alt',
        'zone' => 'bi bi-pin-map',

        'service' => 'bi bi-droplet-half',
        'item_category' => 'bi bi-collection-fill',
        'item' => 'bi bi-tag',
        'item_price' => 'bi bi-cash-coin',

        'laundry' => 'bi bi-shop',
        'laundry_staff' => 'bi bi-person-workspace',
        'laundry_service' => 'bi bi-ui-checks',
        'laundry_zone' => 'bi bi-geo',

        'driver' => 'bi bi-truck',
        // Was `bi bi-cash-stack`, which is now the Money group's own icon.
        'driver_earning' => 'bi bi-coin',

        'user' => 'bi bi-people',

        'offer' => 'bi bi-tags',
        'coupon' => 'bi bi-ticket-perforated',
        'banner' => 'bi bi-image',
        'intro' => 'bi bi-collection-play',
        'journey_step' => 'bi bi-signpost-split',
        'faq' => 'bi bi-question-circle',

        'order' => 'bi bi-receipt',

        'complaint' => 'bi bi-exclamation-circle',
        'order_rating' => 'bi bi-star',
        'order_recurrence' => 'bi bi-arrow-repeat',
        'time_slot' => 'bi bi-clock-history',

        'payment' => 'bi bi-credit-card',
        'refund' => 'bi bi-arrow-counterclockwise',
        'wallet' => 'bi bi-wallet2',

        'report' => 'bi bi-bar-chart-line',

        'moderator' => 'bi bi-person-badge',
        'role' => 'bi bi-shield-lock',
        'language' => 'fas fa-language',
        'notification_log' => 'bi bi-bell',
        'setting' => 'bi bi-gear-fill',
    ],

    'titles' => [
        'country' => 'Countries',
        'city' => 'Cities',
        'zone' => 'Zones',

        'service' => 'Services',
        'item_category' => 'Item Categories',
        'item' => 'Items',
        'item_price' => 'Prices',

        'laundry' => 'Laundries',
        'laundry_staff' => 'Laundry Staff',
        'laundry_service' => 'My Services',
        'laundry_zone' => 'My Areas',

        'driver' => 'Drivers',
        'driver_earning' => 'Driver Earnings',

        'user' => 'Users',

        'offer' => 'Offers',
        'coupon' => 'Discount Codes',
        'banner' => 'Banners',
        'intro' => 'Intros',
        'journey_step' => 'Journey Steps',
        'faq' => 'FAQ',

        'order' => 'Orders',

        'complaint' => 'Complaints',
        'order_rating' => 'Ratings',
        'order_recurrence' => 'Repeat Schedules',
        'time_slot' => 'Time Slots',

        'payment' => 'Payments',
        'refund' => 'Refunds',
        'wallet' => 'Wallets',

        'report' => 'Reports',

        'moderator' => 'Moderators',
        'role' => 'Roles',
        'language' => 'Languages',
        'notification_log' => 'Notification Log',
        'setting' => 'Settings',
    ],

    'routes' => [
        'country' => 'admin.country.index',
        'city' => 'admin.city.index',
        'zone' => 'admin.zone.index',

        'service' => 'admin.service.index',
        'item_category' => 'admin.item_category.index',
        'item' => 'admin.item.index',
        'item_price' => 'admin.pricing.index',

        'laundry' => 'admin.laundry.index',
        'laundry_staff' => 'admin.laundry_staff.index',
        'laundry_service' => 'admin.laundry_service.index',
        'laundry_zone' => 'admin.laundry_zone.index',

        'driver' => 'admin.driver.index',
        'driver_earning' => 'admin.earning.index',

        'user' => 'admin.user.index',

        'offer' => 'admin.offer.index',
        'coupon' => 'admin.coupon.index',
        'banner' => 'admin.banner.index',
        'intro' => 'admin.intro.index',
        'journey_step' => 'admin.journey_step.index',
        'faq' => 'admin.faq.index',

        'order' => 'admin.order.index',

        'complaint' => 'admin.complaint.index',
        'order_rating' => 'admin.rating.index',
        'order_recurrence' => 'admin.recurrence.index',
        'time_slot' => 'admin.time_slot.index',

        'payment' => 'admin.payment.index',
        'refund' => 'admin.refund.index',
        'wallet' => 'admin.wallet.index',

        'report' => 'admin.report.revenue',

        'moderator' => 'admin.moderator.index',
        'role' => 'admin.roles.index',
        'language' => 'admin.language.index',
        'notification_log' => 'admin.notification.index',
        'setting' => 'admin.generalSetting.viewGeneralSetting',
    ],
];
