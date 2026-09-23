<?php

/*
|--------------------------------------------------------------------------
| What the brain is allowed to look at
|--------------------------------------------------------------------------
|
| Two lists and they are not symmetrical. `roots` is where the indexer walks;
| `deny` is checked against every path anyway, including paths reached through
| a root, because a root is a convenience and the deny list is a rule.
|
| Nothing here is derived from the framework — the indexer runs with no
| database and no Laravel boot (see README, "Why no artisan dependency").
|
*/

return [

    /*
    | Directories walked, in the order their nodes are created. `app` first so
    | class nodes exist before routes and tests point at them.
    */
    'roots' => [
        'app',
        'routes',
        'config',
        'database/migrations',
        'database/seeders',
        'resources/views',
        'tests',
        'docs',
    ],

    /*
    | Never walked, never read, never hashed. `storage` and `.env*` are the
    | security half of this list; the rest is noise that would drown the index.
    */
    'deny' => [
        'vendor',
        'node_modules',
        'storage',
        'bootstrap/cache',
        'public/assets',
        'public/build',
        'public/storage',
        '.git',
        '.second-brain/data',
        '.second-brain/cache',

        /*
        | The brain's own tests, which quote benchmark queries verbatim.
        |
        | `SearchTest` contains the literal string "how is the delivery fee
        | calculated" three times over, so it matched that query better than
        | most real code did and surfaced at rank 3 for it. A search index that
        | contains its own test fixtures is measuring itself.
        |
        | Denied here rather than filtered in the indexer on purpose: `Doctor`
        | compares `FileScanner::all()` against what is indexed, so a file the
        | indexer skipped but the scanner still listed would be reported as
        | staleness on every single run. One rule, both sides.
        |
        | Application tests stay indexed — `tested_by` is a genuinely useful
        | edge and `tests/Feature/Dashboard` is real coverage of real code.
        */
        'tests/Feature/SecondBrain',
        'test-results',
        'playwright/.cache',
    ],

    /*
    | Filename patterns refused wherever they are found. `.env` is the first
    | entry for the reason the whole list exists.
    */
    'deny_files' => [
        '/^\.env/',
        '/\.key$/',
        '/\.pem$/',
        '/\.p12$/',
        '/\.pfx$/',
        '/\.log$/',
        '/^auth\.json$/',
        '/-credentials\.json$/',
        '/^service-account.*\.json$/',
        '/\.sqlite$/',
        '/\.cache$/',
        '/^composer\.lock$/',
        '/^package-lock\.json$/',
        '/^_ide_helper\.php$/',
        '/^\.phpstorm\.meta\.php$/',
        '/^project_code\.txt$/',
    ],

    /*
    | Extensions carried into the graph. Anything else is skipped before it is
    | opened, which is also why a stray `.pdf` in docs/ costs nothing.
    */
    'extensions' => ['php', 'js', 'json', 'md'],

    /*
    | Where a class sits in the layer contract. Matched against the path, first
    | hit wins, so the order is the specificity order.
    |
    | These are the project's own words: "Controllers do HTTP only and delegate
    | to the service; repositories are the only place raw Eloquent queries live;
    | services own business logic" (CLAUDE.md, "The layer contract").
    */
    'layers' => [
        '#^app/Modules/[^/]+/Controllers/#' => 'controller',
        '#^app/Http/Controllers/Api/#' => 'api-controller',
        '#^app/Http/Controllers/#' => 'controller',
        '#^app/Modules/[^/]+/Services/#' => 'service',
        '#^app/Services/#' => 'service',
        '#^app/Modules/[^/]+/Repositories/#' => 'repository',
        '#^app/Modules/[^/]+/Models/#' => 'model',
        '#^app/Models/#' => 'model',
        '#^app/Modules/[^/]+/Requests/#' => 'request',
        '#^app/Http/Requests/#' => 'request',
        '#^app/Modules/[^/]+/Enums/#' => 'enum',
        '#^app/Modules/[^/]+/Console/#' => 'command',
        '#^app/Console/Commands/#' => 'command',
        '#^app/Modules/[^/]+/Data/#' => 'data',
        '#^app/Modules/[^/]+/Contracts/#' => 'contract',
        '#^app/Modules/[^/]+/Gateways/#' => 'gateway',
        '#^app/Http/Middleware/#' => 'middleware',
        '#^app/Jobs/#' => 'job',
        '#^app/Notifications/#' => 'notification',
        '#^app/Trait/#' => 'trait',
        '#^app/Support/#' => 'support',
        '#^app/Helpers/#' => 'helper',
        '#^app/View/Components/#' => 'view-component',
        '#^app/Providers/#' => 'provider',
        '#^app/Mail/#' => 'mail',
        '#^database/migrations/#' => 'migration',
        '#^database/seeders/#' => 'seeder',
        '#^tests/Browser/#' => 'browser-test',
        '#^tests/#' => 'test',
        '#^resources/views/#' => 'view',
        '#^routes/#' => 'routes',
        '#^config/#' => 'config',
        '#^docs/#' => 'doc',
    ],

    /*
    | Methods worth searching for even though they are not public.
    |
    | The graph deliberately holds only public methods as nodes — 1,500 private
    | helpers would treble it and dilute every result. But three patterns in
    | this project are contracts rather than helpers, and excluding them cost a
    | verified retrieval failure:
    |
    | - `present*` — CLAUDE.md: "Controllers keep a private `present*()` method
    |   per payload shape". This *is* the mobile API's response contract, and
    |   "add a field to the order summary" could not find it because not one of
    |   the 40-odd `present*` methods was indexed anywhere.
    | - `scope*`  — an Eloquent query scope is public API by another name.
    | - `shredData` — the universal view-data assembler every CRUD service has.
    |
    | These become searchable **facets on the containing class**, not nodes.
    | The class stays the thing you open.
    */
    'significant_methods' => [
        '/^present[A-Z]/',
        '/^scope[A-Z]/',
        '/^shredData$/',
    ],

    /*
    | Third-party surfaces the code actually talks to, matched on a literal
    | needle in the source. Detected, never assumed — an entry earns its place
    | by appearing in a file.
    */
    'integrations' => [
        'google_routes_api' => ['routes.googleapis.com', 'computeRouteMatrix'],
        'firebase_fcm' => ['fcm.googleapis.com', 'FcmPushDriver'],
        'laravel_sanctum' => ['Laravel\\Sanctum', 'createToken'],
        'vite' => ['@vite'],
        'playwright' => ['@playwright/test'],
    ],

    /*
    | Query words that are the project's words for the same thing. Built from
    | the vocabulary in CLAUDE.md and the module names, so "courier" reaches the
    | driver module and "commission" reaches the settlement service.
    |
    | One direction only: the query is expanded, the index is not. Expanding the
    | index instead would make every laundry document match "washing".
    */
    'synonyms' => [
        'courier' => ['driver'],
        'rider' => ['driver'],
        'captain' => ['driver'],
        'washing' => ['laundry', 'service'],
        'wash' => ['laundry', 'service'],
        'shop' => ['laundry'],
        'vendor' => ['laundry'],
        'merchant' => ['laundry'],
        'tenant' => ['laundry', 'scope'],

        /*
        | Aliases added after the 20-task validation, each from an observed
        | failure and each traceable to a name that exists in this repository.
        | Provenance is given so a reader can check the claim rather than trust
        | it — an alias nobody can verify is a guess with a config entry.
        */
        // `admin/earning` renders `DriverEarning` rows — CLAUDE.md, "Module
        // structure": the menu key `driver_earning` lives in Modules/Payment.
        'payout' => ['driverearning', 'earning', 'settlement', 'wallet'],
        'paid' => ['settlement', 'earning', 'wallet'],
        // `app/Support/LaundryContext.php` + `app/Trait/BelongsToLaundry.php`
        // are the two halves of tenant scoping; neither contains the word
        // "tenant" in its class name.
        'scoping' => ['laundrycontext', 'belongstolaundry', 'scope'],
        'multitenant' => ['laundrycontext', 'belongstolaundry'],
        // `CheckPermission` middleware + the `canDo()` Blade helper.
        'gate' => ['permission', 'middleware', 'checkpermission', 'cando'],
        'gated' => ['permission', 'middleware'],
        'guard' => ['permission', 'middleware', 'auth'],
        // `SlotOverflowBehavior`, `laundry_slot_capacities` — the words an
        // operator uses for the intake cap.
        'intake' => ['capacity', 'slot', 'laundryslotcapacity'],
        'overflow' => ['slotoverflowbehavior', 'capacity'],
        // `OrderTask` + `TaskType`'s four legs.
        'pickup' => ['ordertask', 'tasktype', 'leg'],
        'delivery' => ['ordertask', 'tasktype', 'deliveryfee'],
        // The panel's own word for a list screen; `setupAjaxSearch` and
        // `setupClientFilter` are the two wirings behind every one of them.
        'dropdown' => ['select', 'filter', 'option', 'extraparams'],
        'screen' => ['view', 'blade', 'index'],
        'grid' => ['setupclientfilter', 'pricing', 'matrix'],
        // `OrderPricing::compose()` is the single place a total is assembled.
        'total' => ['orderpricing', 'compose', 'pretaxtotal'],
        'invoice' => ['invoice', 'invoicerenderer', 'payment'],
        // CLAUDE.md's own words under "Tenant scoping": isolation, leaks
        // across tenants, the global scope. Somebody asking how owners are
        // kept apart uses these rather than the class names.
        'isolation' => ['tenant', 'scope', 'laundrycontext'],
        'isolated' => ['tenant', 'scope'],
        'leak' => ['tenant', 'scope'],
        'owner' => ['laundry', 'tenant'],
        'owners' => ['laundry', 'tenant'],
        'discount' => ['coupon', 'offer'],
        'promo' => ['coupon'],
        'voucher' => ['coupon'],
        'code' => ['coupon'],
        'commission' => ['settlement', 'commissionrule'],
        'earnings' => ['earning', 'driverearning'],
        'bonus' => ['driverbonus', 'earning'],
        'money' => ['payment', 'settlement', 'wallet', 'pricing'],
        'price' => ['pricing', 'itemprice'],
        'pricing' => ['pricing', 'itemprice'],
        'fee' => ['deliveryfee', 'platformfee', 'pricing'],
        'tax' => ['pricing', 'settlement'],
        'refund' => ['refund', 'payment'],
        'cart' => ['order'],
        'basket' => ['order'],
        'booking' => ['order'],
        'job' => ['ordertask', 'task'],
        'leg' => ['ordertask', 'tasktype'],
        'trip' => ['ordertask'],
        'dispatch' => ['dispatch', 'driverdispatcher', 'ordertask'],
        'assignment' => ['laundryassigner', 'dispatch'],
        'status' => ['orderstatus', 'statemachine'],
        'lifecycle' => ['orderstatus', 'statemachine'],
        'transition' => ['statemachine', 'orderstatus'],
        'cancel' => ['orderstatus', 'statemachine'],
        'track' => ['tracking', 'order'],
        'map' => ['tracking', 'routing'],
        'distance' => ['routing', 'router'],
        'route' => ['routing', 'router'],
        'push' => ['notification', 'fcm'],
        'sms' => ['sms', 'otp'],
        'otp' => ['otp', 'auth'],
        'login' => ['auth', 'login'],
        'signin' => ['auth', 'login'],
        'auth' => ['auth', 'sanctum'],
        'permission' => ['permission', 'role'],
        'role' => ['role', 'permission'],
        'acl' => ['permission', 'role'],
        'translation' => ['language', 'localization'],
        'locale' => ['language', 'localization'],
        'language' => ['language'],
        'arabic' => ['language', 'localization'],
        'slot' => ['timeslot', 'capacity'],
        'schedule' => ['timeslot', 'recurrence'],
        'capacity' => ['laundryslotcapacity', 'timeslot'],
        'area' => ['zone'],
        'coverage' => ['zone', 'laundryzone'],
        'geography' => ['zone', 'city', 'country'],
        'address' => ['address'],
        'complaint' => ['complaint'],
        'support' => ['complaint', 'faq'],
        'ticket' => ['complaint'],
        'review' => ['rating', 'orderreview'],
        'rating' => ['rating'],
        'landing' => ['landing'],
        'marketing' => ['landing', 'offer', 'banner'],
        'homepage' => ['landing'],
        'report' => ['report'],
        'settings' => ['setting'],
        'setting' => ['setting'],
        'customer' => ['user'],
        'client' => ['user'],
        'staff' => ['laundrystaff', 'moderator'],
        'admin' => ['moderator', 'dashboard'],
        'panel' => ['dashboard', 'admin'],
        'dashboard' => ['dashboard', 'admin'],
        'search' => ['searchable', 'search'],
        'wallet' => ['wallet'],
        'balance' => ['wallet'],
        'recurring' => ['recurrence'],
        'repeat' => ['recurrence'],
        'subscription' => ['recurrence'],
        'notification' => ['notification'],
        'alert' => ['notification'],
        'email' => ['mail', 'notification'],
        'upload' => ['image', 'media'],
        'image' => ['image', 'media'],
        'photo' => ['image', 'media'],
        'document' => ['driverrecordsubmission', 'media'],
    ],
];
