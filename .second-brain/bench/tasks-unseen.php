<?php

/*
|--------------------------------------------------------------------------
| The held-out 30
|--------------------------------------------------------------------------
|
| Written on 2026-09-23 **after** all eleven phases were implemented and
| before this set was ever run, to measure whether the improvements generalise
| or were fitted to the frozen 20.
|
| Deliberately drawn from parts of the codebase the known set never touches:
| notifications and the queue, the wallet, recurrence, complaints, time slots,
| the landing page, OTP and password reset, road routing, the laundry and
| driver application queues, refunds, dispatch, ratings, translations,
| invoices, the sidebar, middleware and pricing. None is a paraphrase of a
| known task.
|
| Ground truth was established by reading the files, not by running a search.
| Frozen: nothing here may be edited in response to a score.
|
| Each entry: [category, query, truth[], grep[]]
|
*/

return [
    // ------------------------------------------------------------------- CRUD
    ['CRUD', 'add a new banner to the customer home screen', [
        'app/Modules/Banner/Controllers/BannerController.php',
        'app/Modules/Banner/Services/bannerCrudService.php',
        'app/Modules/Banner/Models/banner.php',
    ], ['banner']],

    ['CRUD', 'edit an onboarding intro slide', [
        'app/Modules/Intro/Controllers/IntroController.php',
        'app/Modules/Intro/Services/introCrudService.php',
    ], ['intro', 'onboarding']],

    ['CRUD', 'create a delivery zone and set its fee', [
        'app/Modules/Zone/Services/zoneCrudService.php',
        'app/Modules/Zone/Models/Zone.php',
    ], ['zone', 'delivery fee']],

    ['CRUD', 'manage the list of countries', [
        'app/Modules/Country/Controllers/CountryController.php',
        'app/Modules/Country/Services/countryCrudService.php',
    ], ['country']],

    // -------------------------------------------------------------------- API
    ['API', 'the endpoint the driver app calls to report its location', [
        'app/Http/Controllers/Api/V1/DriverController.php',
    ], ['location', 'driver']],

    ['API', 'what the customer app receives when it polls order tracking', [
        'app/Http/Controllers/Api/V1/OrderController.php',
    ], ['track', 'tracking']],

    ['API', 'the endpoint that registers a customer and sends an otp', [
        'app/Http/Controllers/Api/V1/AuthController.php',
        'app/Services/Auth/OtpService.php',
    ], ['otp', 'register']],

    ['API', 'how a customer files a complaint from the app', [
        'app/Http/Controllers/Api/V1/ComplaintController.php',
        'app/Modules/Complaint/Services/ComplaintService.php',
    ], ['complaint']],

    ['API', 'the wallet balance endpoint for the customer app', [
        'app/Http/Controllers/Api/V1/WalletController.php',
        'app/Modules/Wallet/Services/WalletService.php',
    ], ['wallet', 'balance']],

    // ------------------------------------------------------------------ Blade
    ['Blade', 'the dispatch board screen where operators assign drivers', [
        'resources/views/admin/dispatch/index.blade.php',
        'app/Modules/Order/Services/dispatchBoardService.php',
    ], ['dispatch']],

    ['Blade', 'the price grid screen where item prices are bulk edited', [
        'resources/views/admin/pricing/index.blade.php',
        'app/Modules/Pricing/Services/pricingService.php',
    ], ['price', 'grid']],

    ['Blade', 'the laundry edit form with its tabs', [
        'resources/views/admin/laundry/edit.blade.php',
        'app/Modules/Laundry/Controllers/LaundryController.php',
    ], ['laundry', 'edit']],

    ['Blade', 'the notification compose screen for broadcasting a message', [
        'resources/views/admin/notification/compose.blade.php',
        'app/Modules/Notification/Controllers/NotificationComposeController.php',
    ], ['notification', 'compose']],

    // ---------------------------------------------------------------- Service
    ['Service', 'how is a push notification actually delivered', [
        'app/Modules/Notification/Services/NotificationDispatcher.php',
    ], ['push', 'notification']],

    ['Service', 'where is road distance between two points measured', [
        'app/Services/Routing/RoutingService.php',
        'app/Services/Routing/GoogleDistanceMatrixRouter.php',
    ], ['distance', 'routing']],

    ['Service', 'how is a refund processed', [
        'app/Modules/Payment/Services/RefundService.php',
    ], ['refund']],

    ['Service', 'where are the four driver legs created for an order', [
        'app/Modules/Order/Services/TaskGenerator.php',
    ], ['task', 'leg']],

    ['Service', 'how does a customer reschedule a pickup', [
        'app/Modules/Order/Services/RescheduleService.php',
    ], ['reschedule']],

    ['Service', 'the referral programme and what a code is worth', [
        'app/Modules/Coupon/Services/ReferralService.php',
    ], ['referral', 'invite']],

    ['Service', 'how is the invoice document produced', [
        'app/Modules/Payment/Services/InvoiceRenderer.php',
    ], ['invoice']],

    // ------------------------------------------------------------------ Model
    ['Model', 'which model records a wallet transaction and why it happened', [
        'app/Modules/Wallet/Models/WalletTransaction.php',
        'app/Modules/Wallet/Enums/TransactionReason.php',
    ], ['wallet', 'transaction']],

    ['Model', 'where are the device tokens for push stored', [
        'app/Modules/Notification/Models/DeviceToken.php',
    ], ['device token', 'fcm']],

    ['Model', 'the model behind a repeating weekly order', [
        'app/Modules/Order/Models/OrderRecurrence.php',
    ], ['recurrence', 'repeat']],

    // --------------------------------------------------------------- Database
    ['Database', 'add a column to the complaints table', [
        'database/migrations/2026_08_31_150000_create_complaints_table.php',
        'app/Modules/Complaint/Models/Complaint.php',
    ], ['complaints', 'complaint']],

    ['Database', 'which migration creates the time slots table', [
        'database/migrations/2026_08_26_093505_create_time_slots_table.php',
        'app/Modules/TimeSlot/Models/TimeSlot.php',
    ], ['time_slots', 'time slot']],

    // ----------------------------------------------------------------- Report
    ['Report', 'the operations report and what it counts', [
        'app/Modules/Report/Services/OperationsReport.php',
    ], ['operations report', 'report']],

    ['Report', 'the dashboard home page summary tiles', [
        'app/Modules/Report/Services/DashboardSummary.php',
    ], ['dashboard', 'summary']],

    // ------------------------------------------------------------- Permission
    ['Permission', 'which permission gates the dispatch board', [
        'app/Modules/Order/Controllers/DispatchController.php',
        'routes/web.php',
    ], ['dispatch', 'permission']],

    // ----------------------------------------------------------- Cross-module
    ['Cross-module', 'a laundry applies to join and an operator approves it', [
        'app/Modules/Laundry/Services/LaundryApplicationService.php',
        'app/Http/Controllers/LaundryApplicationController.php',
    ], ['laundry', 'approve']],

    ['Cross-module', 'a driver submits new documents and somebody reviews them', [
        'app/Modules/Driver/Services/DriverRecordReview.php',
        'app/Modules/Driver/Models/DriverRecordSubmission.php',
    ], ['record submission', 'driver']],
];
