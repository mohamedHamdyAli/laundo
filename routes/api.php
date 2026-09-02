<?php

use App\Http\Controllers\Api\V1\AddressController;
use App\Http\Controllers\Api\V1\AppSettingController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\ComplaintController;
use App\Http\Controllers\Api\V1\ContentController;
use App\Http\Controllers\Api\V1\CouponController;
use App\Http\Controllers\Api\V1\DriverController;
use App\Http\Controllers\Api\V1\DriverTaskController;
use App\Http\Controllers\Api\V1\GeoController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\LanguageController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OrderRatingController;
use App\Http\Controllers\Api\V1\OrderReviewController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\RecurrenceController;
use App\Http\Controllers\Api\V1\ReferralController;
use App\Http\Controllers\Api\V1\RefundController;
use App\Http\Controllers\Api\V1\WalletController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (v1)
|--------------------------------------------------------------------------
|
| Registered in bootstrap/app.php with the prefix `api/v1`, so a route
| declared as '/ping' here is reachable at GET /api/v1/ping.
|
| Middleware applied to this whole group (see bootstrap/app.php):
|   ApiLocale     — resolves the locale from the `lang` header
|   SetTimezone   — applies the configured country timezone
|   throttle:api  — 60 requests/minute per user or IP
|
| Clients set:
|   Accept: application/json      (required)
|   lang: ar | en                 (optional, defaults to the default language)
|   Authorization: Bearer <token> (on protected routes)
|
*/

/*
|--------------------------------------------------------------------------
| Public — no token required
|--------------------------------------------------------------------------
|
| Guest mode browses the catalogue without an account, so these stay open.
|
*/
Route::get('/ping', [HealthController::class, 'ping'])->name('api.v1.ping');

/*
| Payment webhooks — public by necessity, since a provider carries no token.
|
| Safety comes from two places, neither of them authentication: the driver
| verifies the provider's signature, and the reference has to match an attempt we
| created ourselves. An unknown reference is ignored rather than guessed at.
*/
Route::post('/payments/webhook/{provider}', [PaymentController::class, 'webhook'])
    ->name('api.v1.payments.webhook');
Route::get('/languages', [LanguageController::class, 'index'])->name('api.v1.languages');

// The translation set an app ships its own strings from. The upload path on the
// languages screen has always existed and nothing served what it wrote.
Route::get('/translations/{type}', [LanguageController::class, 'translations'])
    ->name('api.v1.translations');

// The catalogue: guest mode browses services and prices without an account.
Route::get('/services', [CatalogController::class, 'services'])->name('api.v1.services');
Route::get('/catalog', [CatalogController::class, 'catalog'])->name('api.v1.catalog');

/*
| Content operations writes and the apps read.
|
| These three admin screens produced content nothing could fetch until now: a
| banner could be published and never seen, the onboarding slides had no source,
| and «الشروط والأحكام» in the account screen had no endpoint behind it.
|
| Public on purpose. Onboarding runs before an account exists and guest mode
| browses the home screen, so a token here would hide the content from the people
| it is aimed at.
*/
Route::get('/banners', [ContentController::class, 'banners'])->name('api.v1.banners');
Route::get('/intros', [ContentController::class, 'intros'])->name('api.v1.intros');
Route::get('/app-settings', [AppSettingController::class, 'index'])->name('api.v1.app-settings');

// «الأسئلة الشائعة» — the first item under «المساعدة والدعم» in both apps.
// ?audience=customer|driver narrows it; without it the caller gets everything,
// because guessing from an absent token would serve one app the other's answers.
Route::get('/faqs', [ContentController::class, 'faqs'])->name('api.v1.faqs');
// «ادعُ أصدقاءك» — what a code is worth, read by the register screen before
// anybody has an account. An app that hardcodes the figure lies the day it moves.
Route::get('/referral-terms', [ReferralController::class, 'terms'])->name('api.v1.referral-terms');

Route::get('/complaint-categories', [ComplaintController::class, 'categories'])
    ->name('api.v1.complaint-categories');

// One long-form page at a time — about | privacy | terms. Separate from
// /app-settings because each is a wall of HTML and the account screen only needs
// one when the customer opens it.
Route::get('/pages/{page}', [AppSettingController::class, 'page'])->name('api.v1.pages');

// Geography and scheduling, for the address form and the wizard's schedule step.
Route::get('/cities', [GeoController::class, 'cities'])->name('api.v1.cities');
Route::get('/time-slots', [GeoController::class, 'timeSlots'])->name('api.v1.time-slots');

/*
|--------------------------------------------------------------------------
| Authentication — public, but individually throttled
|--------------------------------------------------------------------------
|
| Each limiter is defined in AppServiceProvider. They differ on purpose:
| requesting a code is cheap for us and expensive in SMS, while guessing one
| is cheap for an attacker — so sends and verifies are limited separately.
|
*/
Route::prefix('auth')->name('api.v1.auth.')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:otp')->name('register');

    Route::post('/verify-otp', [AuthController::class, 'verifyOtp'])
        ->middleware('throttle:otp-verify')->name('verify-otp');

    Route::post('/resend-otp', [AuthController::class, 'resendOtp'])
        ->middleware('throttle:otp')->name('resend-otp');

    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:login')->name('login');

    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:otp')->name('forgot-password');

    // The code step keeps the `otp-verify` limiter: it is the one that takes
    // guesses at a six-digit code.
    Route::post('/verify-reset-code', [AuthController::class, 'verifyResetCode'])
        ->middleware('throttle:otp-verify')->name('verify-reset-code');

    // The password step takes a 64-character ticket, which is not guessable, so
    // this limiter is only there to stop a client hammering it.
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:otp-verify')->name('reset-password');
});

/*
|--------------------------------------------------------------------------
| Driver app — public entry points
|--------------------------------------------------------------------------
|
| No registration: driver accounts are created in the dashboard, which is what
| the login screen's «تواصل مع المشرف» means. Reset uses the same OTP path as
| customers so a driver locked out mid-shift is not waiting on a supervisor.
|
*/
Route::prefix('driver')->name('api.v1.driver.')->group(function () {
    Route::post('/login', [DriverController::class, 'login'])
        ->middleware('throttle:login')->name('login');

    Route::post('/forgot-password', [DriverController::class, 'forgotPassword'])
        ->middleware('throttle:otp')->name('forgot-password');

    Route::post('/reset-password', [DriverController::class, 'resetPassword'])
        ->middleware('throttle:otp-verify')->name('reset-password');
});

/*
|--------------------------------------------------------------------------
| Protected — Sanctum token required
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [HealthController::class, 'me'])->name('api.v1.me');

    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');

    // Profile
    Route::get('/profile', [ProfileController::class, 'show'])->name('api.v1.profile.show');
    Route::post('/profile', [ProfileController::class, 'update'])->name('api.v1.profile.update');
    Route::put('/profile/password', [ProfileController::class, 'changePassword'])->name('api.v1.profile.password');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('api.v1.profile.destroy');

    // Addresses
    Route::get('/addresses', [AddressController::class, 'index'])->name('api.v1.addresses.index');
    Route::post('/addresses', [AddressController::class, 'store'])->name('api.v1.addresses.store');
    Route::get('/addresses/{id}', [AddressController::class, 'show'])->name('api.v1.addresses.show');
    Route::put('/addresses/{id}', [AddressController::class, 'update'])->name('api.v1.addresses.update');
    Route::delete('/addresses/{id}', [AddressController::class, 'destroy'])->name('api.v1.addresses.destroy');
    Route::put('/addresses/{id}/default', [AddressController::class, 'makeDefault'])->name('api.v1.addresses.default');

    /*
    | Orders.
    |
    | `quote` exists so the wizard's summary can show a price before anything is
    | saved; it runs the same pricing pass as `store`, so the two cannot disagree.
    |
    | `reorder` returns a pre-filled basket rather than creating an order: the
    | prices may have moved since the original, and the customer has to see the
    | current ones before agreeing.
    */
    Route::get('/orders', [OrderController::class, 'index'])->name('api.v1.orders.index');
    Route::post('/orders/quote', [OrderController::class, 'quote'])->name('api.v1.orders.quote');
    Route::post('/orders', [OrderController::class, 'store'])->name('api.v1.orders.store');
    Route::get('/orders/{id}', [OrderController::class, 'show'])->name('api.v1.orders.show');
    Route::get('/orders/{id}/track', [OrderController::class, 'track'])->name('api.v1.orders.track');
    Route::get('/orders/{id}/reorder', [OrderController::class, 'reorder'])->name('api.v1.orders.reorder');

    /*
    | «ما رأيك في تجربتك؟» — the rating.
    |
    | The GET is not symmetry: the design puts a «تقييم» button on a completed
    | order in the list, and `can_rate` decides whether it is drawn at all. A
    | button that opens a screen which then refuses is worse than no button.
    */
    /*
    | «تقديم شكوى».
    |
    | Both apps offer it — the driver from the account screen, the customer from an
    | order. `order_id` is optional for exactly that reason.
    |
    | Operations answers by phone, so there is no reply thread. The reference and
    | the status are what stop a complaint being a black hole.
    */
    Route::get('/complaints', [ComplaintController::class, 'index'])->name('api.v1.complaints.index');
    Route::post('/complaints', [ComplaintController::class, 'store'])->name('api.v1.complaints.store');
    Route::get('/complaints/{id}', [ComplaintController::class, 'show'])->name('api.v1.complaints.show');

    /*
    | «اختيار موعد جديد» — after «طلب التأجيل».
    |
    | A postponement used to release the leg back to the queue, so the next driver
    | was offered the same trip within seconds of the customer saying "not now".
    | The leg now waits here.
    |
    | The GET exists so the app knows whether to show the prompt at all, and which
    | end of the order it is about — inferring either from a status would be a guess.
    */
    Route::get('/orders/{id}/reschedule', [OrderController::class, 'rescheduleOptions'])
        ->name('api.v1.orders.reschedule.options');
    Route::post('/orders/{id}/reschedule', [OrderController::class, 'reschedule'])
        ->name('api.v1.orders.reschedule');

    Route::get('/orders/{id}/rating', [OrderRatingController::class, 'show'])
        ->name('api.v1.orders.rating.show');
    Route::post('/orders/{id}/rating', [OrderRatingController::class, 'store'])
        ->name('api.v1.orders.rating.store');
    Route::put('/orders/{id}/cancel', [OrderController::class, 'cancel'])->name('api.v1.orders.cancel');

    /*
    | The final price.
    |
    | `review` is the comparison screen — what was agreed against what was found.
    | Then the design's three buttons: confirm the price (which releases the
    | cleaning, not the money), ask for a second count, or ask a question that
    | leaves the order exactly where it is.
    */
    Route::get('/orders/{id}/review', [OrderReviewController::class, 'show'])
        ->name('api.v1.orders.review');
    Route::post('/orders/{id}/confirm', [OrderReviewController::class, 'confirm'])
        ->name('api.v1.orders.confirm');
    Route::post('/orders/{id}/dispute', [OrderReviewController::class, 'dispute'])
        ->name('api.v1.orders.dispute');
    Route::get('/orders/{id}/queries', [OrderReviewController::class, 'queries'])
        ->name('api.v1.orders.queries');

    /*
    | Payment.
    |
    | `pay` starts an attempt and hands back a redirect; it does not settle
    | anything. `payments` is what the app polls when the customer comes back,
    | because the webhook — not the return trip — is what captures.
    */
    Route::get('/payment-methods', [PaymentController::class, 'methods'])
        ->name('api.v1.payment-methods');
    Route::post('/orders/{id}/pay', [PaymentController::class, 'pay'])
        ->name('api.v1.orders.pay');
    Route::get('/orders/{id}/payments', [PaymentController::class, 'show'])
        ->name('api.v1.orders.payments');

    /*
    | The wallet.
    |
    | Reached through its owner and never by key, so there is no id to guess at.
    | Note that `top-up` deliberately credits nothing: money has to arrive through
    | a provider first, and only its webhook may credit a balance.
    */
    Route::get('/wallet', [WalletController::class, 'show'])->name('api.v1.wallet');
    Route::get('/wallet/transactions', [WalletController::class, 'transactions'])
        ->name('api.v1.wallet.transactions');
    Route::post('/wallet/top-up', [WalletController::class, 'topUp'])->name('api.v1.wallet.top-up');
    Route::post('/wallet/withdraw', [WalletController::class, 'withdraw'])->name('api.v1.wallet.withdraw');

    /*
    | Notifications.
    |
    | The in-app list, the device registry, and the design's «الإشعارات» toggle.
    | Note that muting a channel cannot silence a transactional message: an order
    | waiting on a customer who was never told is an order that simply stops.
    */
    Route::get('/notifications', [NotificationController::class, 'index'])
        ->name('api.v1.notifications');
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])
        ->name('api.v1.notifications.unread-count');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])
        ->name('api.v1.notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])
        ->name('api.v1.notifications.read-all');

    Route::post('/devices', [NotificationController::class, 'registerDevice'])
        ->name('api.v1.devices.register');
    Route::delete('/devices', [NotificationController::class, 'forgetDevice'])
        ->name('api.v1.devices.forget');

    Route::get('/notification-preferences', [NotificationController::class, 'preferences'])
        ->name('api.v1.notification-preferences');
    Route::put('/notification-preferences', [NotificationController::class, 'updatePreferences'])
        ->name('api.v1.notification-preferences.update');

    // «تطبيق» — checking a code never consumes it.
    Route::post('/coupons/check', [CouponController::class, 'check'])->name('api.v1.coupons.check');

    // «طلب استرداد» — a request, reviewed by a person.
    Route::get('/orders/{id}/refunds', [RefundController::class, 'index'])
        ->name('api.v1.orders.refunds');
    Route::post('/orders/{id}/refunds', [RefundController::class, 'store'])
        ->name('api.v1.orders.refunds.store');
    Route::post('/orders/{id}/queries', [OrderReviewController::class, 'query'])
        ->name('api.v1.orders.queries.store');

    /*
    | Repeat schedules, and the questions they raise.
    |
    | A schedule never places an order by itself. On its due day it asks
    | «محتاج تغسل النهاردة؟» — /recurrences/prompts is where the app collects those
    | questions, and confirm/decline is where the customer's answer decides.
    */
    Route::get('/recurrences', [RecurrenceController::class, 'index'])->name('api.v1.recurrences.index');
    Route::post('/recurrences', [RecurrenceController::class, 'store'])->name('api.v1.recurrences.store');
    Route::put('/recurrences/{id}/pause', [RecurrenceController::class, 'pause'])->name('api.v1.recurrences.pause');
    Route::put('/recurrences/{id}/resume', [RecurrenceController::class, 'resume'])->name('api.v1.recurrences.resume');
    Route::delete('/recurrences/{id}', [RecurrenceController::class, 'destroy'])->name('api.v1.recurrences.destroy');

    Route::get('/recurrences/prompts', [RecurrenceController::class, 'pendingPrompts'])
        ->name('api.v1.recurrences.prompts');
    Route::post('/recurrences/prompts/{id}/confirm', [RecurrenceController::class, 'confirmPrompt'])
        ->name('api.v1.recurrences.prompts.confirm');
    Route::post('/recurrences/prompts/{id}/decline', [RecurrenceController::class, 'declinePrompt'])
        ->name('api.v1.recurrences.prompts.decline');

    /*
    | Driver app — authenticated.
    |
    | Vehicle details, documents and zones are absent by design: they are
    | verified records and territory assignments, set in the dashboard.
    */
    // «ادعُ أصدقاءك» — the code, who used it, and what it has earned.
    Route::get('/referrals', [ReferralController::class, 'index'])->name('api.v1.referrals');

    Route::prefix('driver')->name('api.v1.driver.')->group(function () {
        Route::post('/logout', [DriverController::class, 'logout'])->name('logout');
        Route::get('/profile', [DriverController::class, 'profile'])->name('profile');
        Route::post('/profile', [DriverController::class, 'updateProfile'])->name('profile.update');
        Route::put('/availability', [DriverController::class, 'setAvailability'])->name('availability');

        /*
        | «تتبع المندوب مباشرة» — the phone reporting its position.
        |
        | Throttled well above the app's own thirty-second cadence: the limiter is
        | there to stop a looping build, not to police a working driver, and a
        | driver whose reports are refused disappears from the customer's map.
        */
        Route::post('/location', [DriverController::class, 'reportLocation'])
            ->middleware('throttle:location')->name('location');
        Route::put('/password', [DriverController::class, 'changePassword'])->name('password');

        /*
        | Tasks — the four legs.
        |
        | `verify` is the QR scan and is separate from `complete` on purpose: the
        | driver scans on arrival and completes after the handover, and collapsing
        | the two would mean a failed upload discarded a successful scan.
        */
        Route::get('/summary', [DriverTaskController::class, 'summaryScreen'])->name('summary');
        Route::get('/tasks', [DriverTaskController::class, 'index'])->name('tasks.index');
        Route::get('/tasks/history', [DriverTaskController::class, 'history'])->name('tasks.history');
        Route::get('/tasks/failure-reasons', [DriverTaskController::class, 'failureReasons'])
            ->name('tasks.failure-reasons');

        // «أرباحي» — pending and released, with the sum behind each.
        Route::get('/earnings', [DriverTaskController::class, 'earnings'])->name('earnings');
        Route::get('/tasks/{id}', [DriverTaskController::class, 'show'])->name('tasks.show');
        Route::post('/tasks/{id}/start', [DriverTaskController::class, 'start'])->name('tasks.start');
        Route::post('/tasks/{id}/verify', [DriverTaskController::class, 'verify'])->name('tasks.verify');
        Route::post('/tasks/{id}/complete', [DriverTaskController::class, 'complete'])->name('tasks.complete');
        Route::post('/tasks/{id}/fail', [DriverTaskController::class, 'fail'])->name('tasks.fail');
    });
});
