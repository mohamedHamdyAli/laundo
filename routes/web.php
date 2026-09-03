<?php

use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\HomeController;
use App\Modules\Banner\Controllers\BannerController;
use App\Modules\JourneyStep\Controllers\JourneyStepController;
use App\Modules\Offer\Controllers\OfferController;
use App\Modules\City\Controllers\CityController;
use App\Modules\Complaint\Controllers\ComplaintController;
use App\Modules\Country\Controllers\CountryController;
use App\Modules\Coupon\Controllers\CouponController;
use App\Modules\Driver\Controllers\DriverController;
use App\Modules\Faq\Controllers\FaqController;
use App\Modules\Intro\Controllers\IntroController;
use App\Modules\Item\Controllers\ItemController;
use App\Modules\ItemCategory\Controllers\ItemCategoryController;
use App\Modules\Laundry\Controllers\LaundryController;
use App\Modules\LaundryService\Controllers\LaundryServiceController;
use App\Modules\LaundryStaff\Controllers\LaundryStaffController;
use App\Modules\LaundryZone\Controllers\LaundryZoneController;
use App\Modules\Moderator\Controllers\ModeratorController;
use App\Modules\Notification\Controllers\NotificationLogController;
use App\Modules\Order\Controllers\OrderController;
use App\Modules\Order\Controllers\OrderReviewController as DashboardOrderReviewController;
use App\Modules\Order\Controllers\OrderTaskController;
use App\Modules\Payment\Controllers\InvoiceController;
use App\Modules\Payment\Controllers\PaymentLedgerController;
use App\Modules\Payment\Controllers\RefundController;
use App\Modules\Pricing\Controllers\PricingController;
use App\Modules\Rating\Controllers\RatingController;
use App\Modules\Recurrence\Controllers\RecurrenceController;
use App\Modules\Report\Controllers\ReportController;
use App\Modules\Service\Controllers\ServiceController;
use App\Modules\Setting\Controllers\SettingController;
use App\Modules\TimeSlot\Controllers\TimeSlotController;
use App\Modules\User\Controllers\UserController;
use App\Modules\Wallet\Controllers\WalletController;
use App\Modules\Zone\Controllers\ZoneController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth & Landing
|--------------------------------------------------------------------------
*/

Route::get('/', static function () {
    if (Auth::check()) {
        return redirect('/admin/home');
    }

    return view('auth.login');
});

Auth::routes(['register' => false]);

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'dashboard.only'])->prefix('/admin')->group(function () {

    Route::get('/home', [HomeController::class, 'index'])->name('home');

    Route::get('change-password', [HomeController::class, 'changePasswordIndex'])
        ->name('change-password.index');

    Route::post('change-password', [HomeController::class, 'changePasswordUpdate'])
        ->name('change-password.update');

    /*
    |--------------------------------------------------------------------------
    | The operator's own inbox (إشعاراتي)
    |--------------------------------------------------------------------------
    |
    | Renamed from `admin.notifications.*`, which sat **one letter** from
    | `admin.notification.*` — the notification log. Two route prefixes differing
    | by an "s" is a mistake waiting to be made, and they are not even related:
    | this reads Laravel's `notifications` table, the inbox the dispatcher writes
    | to and the topbar bell shows; the log reads `notification_logs`, an audit
    | record of every delivery attempt to customers and drivers.
    |
    | `index` exists because the bell is a ten-item dropdown, and an alert that
    | scrolled past the tenth was gone — a poor fate for the only warning that a
    | task has had no driver for six hours.
    |
    | No permission gate: these are the signed-in user's own notifications, and
    | there is nothing here anybody else can see.
    */
    Route::controller(NotificationController::class)->prefix('my-notifications')->name('admin.myNotifications.')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('/unread', 'unread')->name('unread');
        Route::post('/{id}/read', 'markAsRead')->name('read');
        Route::post('/read-all', 'markAllAsRead')->name('read-all');
    });

    /*
    |--------------------------------------------------------------------------
    | Language
    |--------------------------------------------------------------------------
    */
    Route::controller(LanguageController::class)->group(function () {
        Route::get('/language', 'index')->middleware('permission:language.view')->name('admin.language.index');
        Route::get('/language/search', 'search')->middleware('permission:language.view')->name('admin.language.search');
        Route::get('/language/create', 'create')->middleware('permission:language.create')->name('admin.language.create');
        Route::post('/language/store', 'store')->middleware('permission:language.create')->name('admin.language.store');
        Route::get('/language/show/{id}', 'show')->middleware('permission:language.view')->name('admin.language.show');
        Route::get('/language/edit/{id}', 'edit')->middleware('permission:language.update')->name('admin.language.edit');
        Route::put('/language/update/{id}', 'update')->middleware('permission:language.update')->name('admin.language.update');
        Route::delete('/language/delete/{id}', 'destroy')->middleware('permission:language.delete')->name('admin.language.delete');

        Route::get('set-language/{lang}', 'setLanguage')->name('language.set-current');

        Route::get('/language/panel/{id}', 'showPanel')->middleware('permission:language.update')->name('admin.language.panel');
        Route::post('/language/panel/update/{id}', 'updatePanel')->middleware('permission:language.update')->name('admin.language.panel.update');

        Route::get('/language/mobile/{id}', 'showMobile')->middleware('permission:language.update')->name('admin.language.mobile');
        Route::post('/language/mobile/update/{id}', 'updateMobile')->middleware('permission:language.update')->name('admin.language.mobile.update');

        Route::get('/language/web/{id}', 'showWeb')->middleware('permission:language.update')->name('admin.language.web');
        Route::post('/language/web/update/{id}', 'updateWeb')->middleware('permission:language.update')->name('admin.language.web.update');

        Route::get('/language/download/{type}/{code}', 'downloadJson')
            ->middleware('permission:language.view')
            ->name('admin.language.download');
    });

    /*
    |--------------------------------------------------------------------------
    | Users
    |--------------------------------------------------------------------------
    */
    Route::controller(UserController::class)->group(function () {
        Route::get('/user', 'index')->middleware('permission:user.view')->name('admin.user.index');
        Route::get('/user/search', 'search')->middleware('permission:user.view')->name('admin.user.search');
        Route::get('/user/create', 'create')->middleware('permission:user.create')->name('admin.user.create');
        Route::post('/user/store', 'store')->middleware('permission:user.create')->name('admin.user.store');
        Route::get('/user/show/{id}', 'show')->middleware('permission:user.view')->name('admin.user.show');
        Route::get('/user/edit/{id}', 'edit')->middleware('permission:user.update')->name('admin.user.edit');
        Route::put('/user/update/{id}', 'update')->middleware('permission:user.update')->name('admin.user.update');
        Route::delete('/user/delete/{id}', 'destroy')->middleware('permission:user.delete')->name('admin.user.delete');
        Route::post('/user/status/{id}', 'toggleStatus')->middleware('permission:user.toggle')->name('admin.user.toggleStatus');
    });

    /*
    |--------------------------------------------------------------------------
    | Intro
    |--------------------------------------------------------------------------
    */
    Route::controller(IntroController::class)->group(function () {
        Route::get('/intro', 'index')->middleware('permission:intro.view')->name('admin.intro.index');
        Route::get('/intro/search', 'search')->middleware('permission:intro.view')->name('admin.intro.search');
        Route::get('/intro/create', 'create')->middleware('permission:intro.create')->name('admin.intro.create');
        Route::post('/intro/store', 'store')->middleware('permission:intro.create')->name('admin.intro.store');
        Route::get('/intro/show/{id}', 'show')->middleware('permission:intro.view')->name('admin.intro.show');
        Route::get('/intro/edit/{id}', 'edit')->middleware('permission:intro.update')->name('admin.intro.edit');
        Route::put('/intro/update/{id}', 'update')->middleware('permission:intro.update')->name('admin.intro.update');
        Route::delete('/intro/delete/{id}', 'destroy')->middleware('permission:intro.delete')->name('admin.intro.delete');
        Route::post('/intro/status/{id}', 'toggleStatus')->middleware('permission:intro.toggle')->name('admin.intro.toggleStatus');
    });

    /*
    |--------------------------------------------------------------------------
    | Banner
    |--------------------------------------------------------------------------
    */
    /*
    |--------------------------------------------------------------------------
    | Journey step — «رحلتك معنا بسيطة» on the app's home screen
    |--------------------------------------------------------------------------
    */
    Route::controller(JourneyStepController::class)->group(function () {
        Route::get('/journey-step', 'index')->middleware('permission:journey_step.view')->name('admin.journey_step.index');
        Route::get('/journey-step/search', 'search')->middleware('permission:journey_step.view')->name('admin.journey_step.search');
        Route::get('/journey-step/create', 'create')->middleware('permission:journey_step.create')->name('admin.journey_step.create');
        Route::post('/journey-step/store', 'store')->middleware('permission:journey_step.create')->name('admin.journey_step.store');
        Route::get('/journey-step/show/{id}', 'show')->middleware('permission:journey_step.view')->name('admin.journey_step.show');
        Route::get('/journey-step/edit/{id}', 'edit')->middleware('permission:journey_step.update')->name('admin.journey_step.edit');
        Route::put('/journey-step/update/{id}', 'update')->middleware('permission:journey_step.update')->name('admin.journey_step.update');
        // Named `.delete` while the method is `destroy`: `x-action-buttons`
        // builds its link from `route("$routePrefix.delete", $id)`.
        Route::delete('/journey-step/delete/{id}', 'destroy')->middleware('permission:journey_step.delete')->name('admin.journey_step.delete');
        Route::post('/journey-step/status/{id}', 'toggleStatus')->middleware('permission:journey_step.toggle')->name('admin.journey_step.toggleStatus');
    });

    /*
    |--------------------------------------------------------------------------
    | Offer — «عروض متميزة» on the app's home screen
    |--------------------------------------------------------------------------
    */
    Route::controller(OfferController::class)->group(function () {
        Route::get('/offer', 'index')->middleware('permission:offer.view')->name('admin.offer.index');
        Route::get('/offer/search', 'search')->middleware('permission:offer.view')->name('admin.offer.search');
        Route::get('/offer/create', 'create')->middleware('permission:offer.create')->name('admin.offer.create');
        Route::post('/offer/store', 'store')->middleware('permission:offer.create')->name('admin.offer.store');
        Route::get('/offer/show/{id}', 'show')->middleware('permission:offer.view')->name('admin.offer.show');
        Route::get('/offer/edit/{id}', 'edit')->middleware('permission:offer.update')->name('admin.offer.edit');
        Route::put('/offer/update/{id}', 'update')->middleware('permission:offer.update')->name('admin.offer.update');
        // Named `.delete` while the method is `destroy`: `x-action-buttons`
        // builds its link from `route("$routePrefix.delete", $id)`.
        Route::delete('/offer/delete/{id}', 'destroy')->middleware('permission:offer.delete')->name('admin.offer.delete');
        Route::post('/offer/status/{id}', 'toggleStatus')->middleware('permission:offer.toggle')->name('admin.offer.toggleStatus');
    });

    Route::controller(BannerController::class)->group(function () {
        Route::get('/banner', 'index')->middleware('permission:banner.view')->name('admin.banner.index');
        Route::get('/banner/search', 'search')->middleware('permission:banner.view')->name('admin.banner.search');
        Route::get('/banner/create', 'create')->middleware('permission:banner.create')->name('admin.banner.create');
        Route::post('/banner/store', 'store')->middleware('permission:banner.create')->name('admin.banner.store');
        Route::get('/banner/show/{id}', 'show')->middleware('permission:banner.view')->name('admin.banner.show');
        Route::get('/banner/edit/{id}', 'edit')->middleware('permission:banner.update')->name('admin.banner.edit');
        Route::put('/banner/update/{id}', 'update')->middleware('permission:banner.update')->name('admin.banner.update');
        Route::delete('/banner/delete/{id}', 'destroy')->middleware('permission:banner.delete')->name('admin.banner.delete');
        Route::post('/banner/status/{id}', 'toggleStatus')->middleware('permission:banner.toggle')->name('admin.banner.toggleStatus');
    });


    /*
    |--------------------------------------------------------------------------
    | Country
    |--------------------------------------------------------------------------
    */
    Route::controller(CountryController::class)->group(function () {
        Route::get('/country', 'index')->middleware('permission:country.view')->name('admin.country.index');
        Route::get('/country/search', 'search')->middleware('permission:country.view')->name('admin.country.search');
        Route::get('/country/create', 'create')->middleware('permission:country.create')->name('admin.country.create');
        Route::post('/country/store', 'store')->middleware('permission:country.create')->name('admin.country.store');
        Route::get('/country/show/{id}', 'show')->middleware('permission:country.view')->name('admin.country.show');
        Route::get('/country/edit/{id}', 'edit')->middleware('permission:country.update')->name('admin.country.edit');
        Route::put('/country/update/{id}', 'update')->middleware('permission:country.update')->name('admin.country.update');
        Route::delete('/country/delete/{id}', 'destroy')->middleware('permission:country.delete')->name('admin.country.delete');
        Route::post('/country/status/{id}', 'toggleStatus')->middleware('permission:country.toggle')->name('admin.country.toggleStatus');
    });

    /*
    |--------------------------------------------------------------------------
    | City
    |--------------------------------------------------------------------------
    */
    Route::controller(CityController::class)->group(function () {
        Route::get('/city', 'index')->middleware('permission:city.view')->name('admin.city.index');
        Route::get('/city/search', 'search')->middleware('permission:city.view')->name('admin.city.search');
        Route::get('/city/create', 'create')->middleware('permission:city.create')->name('admin.city.create');
        Route::post('/city/store', 'store')->middleware('permission:city.create')->name('admin.city.store');
        Route::get('/city/show/{id}', 'show')->middleware('permission:city.view')->name('admin.city.show');
        Route::get('/city/edit/{id}', 'edit')->middleware('permission:city.update')->name('admin.city.edit');
        Route::put('/city/update/{id}', 'update')->middleware('permission:city.update')->name('admin.city.update');
        Route::delete('/city/delete/{id}', 'destroy')->middleware('permission:city.delete')->name('admin.city.delete');
        Route::post('/city/status/{id}', 'toggleStatus')->middleware('permission:city.toggle')->name('admin.city.toggleStatus');
    });

    /*
    |--------------------------------------------------------------------------
    | Moderator
    |--------------------------------------------------------------------------
    */
    Route::controller(ModeratorController::class)->group(function () {
        Route::get('/moderator', 'index')->middleware('permission:moderator.view')->name('admin.moderator.index');
        Route::get('/moderator/search', 'search')->middleware('permission:moderator.view')->name('admin.moderator.search');
        Route::get('/moderator/create', 'create')->middleware('permission:moderator.create')->name('admin.moderator.create');
        Route::post('/moderator/store', 'store')->middleware('permission:moderator.create')->name('admin.moderator.store');
        Route::get('/moderator/show/{id}', 'show')->middleware('permission:moderator.view')->name('admin.moderator.show');
        Route::get('/moderator/edit/{id}', 'edit')->middleware('permission:moderator.update')->name('admin.moderator.edit');
        Route::put('/moderator/update/{id}', 'update')->middleware('permission:moderator.update')->name('admin.moderator.update');
        Route::delete('/moderator/delete/{id}', 'destroy')->middleware('permission:moderator.delete')->name('admin.moderator.delete');
        Route::post('/moderator/status/{id}', 'toggleStatus')->middleware('permission:moderator.toggle')->name('admin.moderator.toggleStatus');
    });

    /*
    |--------------------------------------------------------------------------
    | Laundries (tenants)
    |--------------------------------------------------------------------------
    */
    Route::controller(LaundryController::class)->group(function () {
        Route::get('/laundry', 'index')->middleware('permission:laundry.view')->name('admin.laundry.index');
        Route::get('/laundry/search', 'search')->middleware('permission:laundry.view')->name('admin.laundry.search');
        Route::get('/laundry/create', 'create')->middleware('permission:laundry.create')->name('admin.laundry.create');
        Route::post('/laundry/store', 'store')->middleware('permission:laundry.create')->name('admin.laundry.store');
        Route::get('/laundry/show/{id}', 'show')->middleware('permission:laundry.view')->name('admin.laundry.show');
        Route::get('/laundry/edit/{id}', 'edit')->middleware('permission:laundry.update')->name('admin.laundry.edit');
        Route::put('/laundry/update/{id}', 'update')->middleware('permission:laundry.update')->name('admin.laundry.update');
        Route::delete('/laundry/delete/{id}', 'destroy')->middleware('permission:laundry.delete')->name('admin.laundry.delete');
        Route::post('/laundry/status/{id}', 'toggleStatus')->middleware('permission:laundry.toggle')->name('admin.laundry.toggleStatus');
    });

    /*
    |--------------------------------------------------------------------------
    | Laundry Staff
    |--------------------------------------------------------------------------
    */
    Route::controller(LaundryStaffController::class)->group(function () {
        Route::get('/laundry-staff', 'index')->middleware('permission:laundry_staff.view')->name('admin.laundry_staff.index');
        Route::get('/laundry-staff/search', 'search')->middleware('permission:laundry_staff.view')->name('admin.laundry_staff.search');
        Route::get('/laundry-staff/create', 'create')->middleware('permission:laundry_staff.create')->name('admin.laundry_staff.create');
        Route::post('/laundry-staff/store', 'store')->middleware('permission:laundry_staff.create')->name('admin.laundry_staff.store');
        Route::get('/laundry-staff/show/{id}', 'show')->middleware('permission:laundry_staff.view')->name('admin.laundry_staff.show');
        Route::get('/laundry-staff/edit/{id}', 'edit')->middleware('permission:laundry_staff.update')->name('admin.laundry_staff.edit');
        Route::put('/laundry-staff/update/{id}', 'update')->middleware('permission:laundry_staff.update')->name('admin.laundry_staff.update');
        Route::delete('/laundry-staff/delete/{id}', 'destroy')->middleware('permission:laundry_staff.delete')->name('admin.laundry_staff.delete');
        Route::post('/laundry-staff/status/{id}', 'toggleStatus')->middleware('permission:laundry_staff.toggle')->name('admin.laundry_staff.toggleStatus');
    });

    /*
    |--------------------------------------------------------------------------
    | Catalog — Services
    |--------------------------------------------------------------------------
    */
    Route::controller(ServiceController::class)->group(function () {
        Route::get('/service', 'index')->middleware('permission:service.view')->name('admin.service.index');
        Route::get('/service/search', 'search')->middleware('permission:service.view')->name('admin.service.search');
        Route::get('/service/create', 'create')->middleware('permission:service.create')->name('admin.service.create');
        Route::post('/service/store', 'store')->middleware('permission:service.create')->name('admin.service.store');
        Route::get('/service/show/{id}', 'show')->middleware('permission:service.view')->name('admin.service.show');
        Route::get('/service/edit/{id}', 'edit')->middleware('permission:service.update')->name('admin.service.edit');
        Route::put('/service/update/{id}', 'update')->middleware('permission:service.update')->name('admin.service.update');
        Route::delete('/service/delete/{id}', 'destroy')->middleware('permission:service.delete')->name('admin.service.delete');
        Route::post('/service/status/{id}', 'toggleStatus')->middleware('permission:service.toggle')->name('admin.service.toggleStatus');
    });

    /*
    |--------------------------------------------------------------------------
    | Catalog — Item Categories
    |--------------------------------------------------------------------------
    */
    Route::controller(ItemCategoryController::class)->group(function () {
        Route::get('/item-category', 'index')->middleware('permission:item_category.view')->name('admin.item_category.index');
        Route::get('/item-category/search', 'search')->middleware('permission:item_category.view')->name('admin.item_category.search');
        Route::get('/item-category/create', 'create')->middleware('permission:item_category.create')->name('admin.item_category.create');
        Route::post('/item-category/store', 'store')->middleware('permission:item_category.create')->name('admin.item_category.store');
        Route::get('/item-category/show/{id}', 'show')->middleware('permission:item_category.view')->name('admin.item_category.show');
        Route::get('/item-category/edit/{id}', 'edit')->middleware('permission:item_category.update')->name('admin.item_category.edit');
        Route::put('/item-category/update/{id}', 'update')->middleware('permission:item_category.update')->name('admin.item_category.update');
        Route::delete('/item-category/delete/{id}', 'destroy')->middleware('permission:item_category.delete')->name('admin.item_category.delete');
        Route::post('/item-category/status/{id}', 'toggleStatus')->middleware('permission:item_category.toggle')->name('admin.item_category.toggleStatus');
    });

    /*
    |--------------------------------------------------------------------------
    | Catalog — Items
    |--------------------------------------------------------------------------
    */
    Route::controller(ItemController::class)->group(function () {
        Route::get('/item', 'index')->middleware('permission:item.view')->name('admin.item.index');
        Route::get('/item/search', 'search')->middleware('permission:item.view')->name('admin.item.search');
        Route::get('/item/create', 'create')->middleware('permission:item.create')->name('admin.item.create');
        Route::post('/item/store', 'store')->middleware('permission:item.create')->name('admin.item.store');
        Route::get('/item/show/{id}', 'show')->middleware('permission:item.view')->name('admin.item.show');
        Route::get('/item/edit/{id}', 'edit')->middleware('permission:item.update')->name('admin.item.edit');
        Route::put('/item/update/{id}', 'update')->middleware('permission:item.update')->name('admin.item.update');
        Route::delete('/item/delete/{id}', 'destroy')->middleware('permission:item.delete')->name('admin.item.delete');
        Route::post('/item/status/{id}', 'toggleStatus')->middleware('permission:item.toggle')->name('admin.item.toggleStatus');
    });

    /*
    |--------------------------------------------------------------------------
    | Catalog — Price matrix
    |--------------------------------------------------------------------------
    | A grid editor, not a CRUD resource: the whole matrix saves in one post.
    */
    Route::controller(PricingController::class)->group(function () {
        Route::get('/pricing', 'index')->middleware('permission:item_price.view')->name('admin.pricing.index');
        Route::put('/pricing/update', 'update')->middleware('permission:item_price.update')->name('admin.pricing.update');
    });

    /*
    |--------------------------------------------------------------------------
    | Laundry offerings — which services a tenant provides
    |--------------------------------------------------------------------------
    */
    Route::controller(LaundryServiceController::class)->group(function () {
        Route::get('/laundry-service', 'index')->middleware('permission:laundry_service.view')->name('admin.laundry_service.index');
        Route::put('/laundry-service/update', 'update')->middleware('permission:laundry_service.update')->name('admin.laundry_service.update');
    });

    /*
    |--------------------------------------------------------------------------
    | Drivers (المناديب) — accounts are created here, never self-registered
    |--------------------------------------------------------------------------
    */
    Route::controller(DriverController::class)->group(function () {
        Route::get('/driver', 'index')->middleware('permission:driver.view')->name('admin.driver.index');
        Route::get('/driver/search', 'search')->middleware('permission:driver.view')->name('admin.driver.search');
        Route::get('/driver/create', 'create')->middleware('permission:driver.create')->name('admin.driver.create');
        Route::post('/driver/store', 'store')->middleware('permission:driver.create')->name('admin.driver.store');
        Route::get('/driver/show/{id}', 'show')->middleware('permission:driver.view')->name('admin.driver.show');
        Route::get('/driver/edit/{id}', 'edit')->middleware('permission:driver.update')->name('admin.driver.edit');
        Route::put('/driver/update/{id}', 'update')->middleware('permission:driver.update')->name('admin.driver.update');
        Route::delete('/driver/delete/{id}', 'destroy')->middleware('permission:driver.delete')->name('admin.driver.delete');
        Route::post('/driver/status/{id}', 'toggleStatus')->middleware('permission:driver.toggle')->name('admin.driver.toggleStatus');
    });

    /*
    |--------------------------------------------------------------------------
    | Orders (الطلبات)
    |--------------------------------------------------------------------------
    |
    | Read-only plus assignment. There is no create and no delete: an order is a
    | customer's agreement, not a row an operator invents or erases. Cancelling
    | goes through the state machine so it leaves a trace.
    |
    | The list is tenant-scoped by the Order model, so a laundry sees its own
    | orders here and a super admin sees all of them — including the unassigned
    | ones, which fall outside every tenant's scope by construction.
    |
    */
    Route::controller(OrderController::class)->group(function () {
        Route::get('/order', 'index')->middleware('permission:order.view')->name('admin.order.index');
        Route::get('/order/search', 'search')->middleware('permission:order.view')->name('admin.order.search');
        Route::get('/order/show/{id}', 'show')->middleware('permission:order.view')->name('admin.order.show');
        Route::put('/order/assign/{id}', 'assign')->middleware('permission:order.update')->name('admin.order.assign');
    });

    // «تحميل الفاتورة» — a printable page rather than a PDF, since no PDF package
    // is installed and adding a dependency is not a decision to slip into a phase.
    Route::get('/order/invoice/{id}', [InvoiceController::class, 'show'])
        ->middleware('permission:order.view')->name('admin.order.invoice');

    /*
    |--------------------------------------------------------------------------
    | Piece review (مراجعة القطع) — the laundry's core screen
    |--------------------------------------------------------------------------
    |
    | Where an order stops being an estimate. Gated on `order.update`, which the
    | laundry owner has and laundry staff do not, and scoped by the Order model
    | so a laundry can only price its own work.
    |
    */
    Route::controller(DashboardOrderReviewController::class)->group(function () {
        Route::post('/order/review/{id}', 'store')
            ->middleware('permission:order.update')->name('admin.order.review');
        Route::post('/order/query/{id}', 'answerQuery')
            ->middleware('permission:order.update')->name('admin.order.query.answer');
    });

    /*
    |--------------------------------------------------------------------------
    | Dispatch (المهام)
    |--------------------------------------------------------------------------
    |
    | Reassign and release only. A task is completed in the field with a scan and
    | a signature; ticking one off from a desk would destroy the only proof the
    | handover happened.
    |
    */
    Route::controller(OrderTaskController::class)->group(function () {
        Route::post('/order/task/assign/{id}', 'assign')
            ->middleware('permission:order.update')->name('admin.order.tasks.assign');
        Route::post('/order/task/release/{id}', 'release')
            ->middleware('permission:order.update')->name('admin.order.tasks.release');
        Route::post('/order/task/generate/{id}', 'generate')
            ->middleware('permission:order.update')->name('admin.order.tasks.generate');
    });

    /*
    |--------------------------------------------------------------------------
    | Discount codes (أكواد الخصم)
    |--------------------------------------------------------------------------
    |
    | Ordinary CRUD, with one exception worth knowing: a code somebody has used is
    | part of an order's history, so deleting it is refused and the operator is
    | told to deactivate it instead.
    |
    */
    Route::controller(CouponController::class)->group(function () {
        Route::get('/coupon', 'index')->middleware('permission:coupon.view')->name('admin.coupon.index');
        Route::get('/coupon/search', 'search')->middleware('permission:coupon.view')->name('admin.coupon.search');
        Route::get('/coupon/create', 'create')->middleware('permission:coupon.create')->name('admin.coupon.create');
        Route::post('/coupon/store', 'store')->middleware('permission:coupon.create')->name('admin.coupon.store');
        Route::get('/coupon/show/{id}', 'show')->middleware('permission:coupon.view')->name('admin.coupon.show');
        Route::get('/coupon/edit/{id}', 'edit')->middleware('permission:coupon.update')->name('admin.coupon.edit');
        Route::put('/coupon/update/{id}', 'update')->middleware('permission:coupon.update')->name('admin.coupon.update');
        Route::delete('/coupon/delete/{id}', 'destroy')->middleware('permission:coupon.delete')->name('admin.coupon.delete');
        Route::post('/coupon/status/{id}', 'toggleStatus')->middleware('permission:coupon.toggle')->name('admin.coupon.toggleStatus');
    });

    /*
    |--------------------------------------------------------------------------
    | Refunds (طلبات الاسترداد)
    |--------------------------------------------------------------------------
    |
    | «قيد المراجعة» made into a queue somebody works through. Until this existed
    | the services were unreachable: operations had no way to approve a refund
    | except through code.
    |
    */
    Route::controller(RefundController::class)->group(function () {
        Route::get('/refund', 'index')->middleware('permission:refund.view')->name('admin.refund.index');
        Route::get('/refund/search', 'search')->middleware('permission:refund.view')->name('admin.refund.search');
        Route::post('/refund/approve/{id}', 'approve')->middleware('permission:refund.update')->name('admin.refund.approve');
        Route::post('/refund/reject/{id}', 'reject')->middleware('permission:refund.update')->name('admin.refund.reject');
        Route::post('/refund/settle/{id}', 'settle')->middleware('permission:refund.update')->name('admin.refund.settle');
    });

    /*
    |--------------------------------------------------------------------------
    | Reports (التقارير)
    |--------------------------------------------------------------------------
    |
    | Two different access rules, and the difference matters. Revenue, orders and
    | laundry performance are **tenant-scoped by the Order model**, so a laundry
    | owner sees their own figures with no rule here to get wrong.
    |
    | Driver performance and operations health are **not** scoped — a driver works
    | across laundries — so they carry their own permission. `report.view` is the
    | tenant-safe set; `report.update` is used as the super-admin gate for the two
    | that are not.
    |
    */
    Route::controller(ReportController::class)->group(function () {
        Route::get('/report/revenue', 'revenue')
            ->middleware('permission:report.view')->name('admin.report.revenue');
        Route::get('/report/orders', 'orders')
            ->middleware('permission:report.view')->name('admin.report.orders');
        Route::get('/report/laundries', 'laundries')
            ->middleware('permission:report.view')->name('admin.report.laundries');

        Route::get('/report/drivers', 'drivers')
            ->middleware('permission:report.update')->name('admin.report.drivers');
        Route::get('/report/operations', 'operations')
            ->middleware('permission:report.update')->name('admin.report.operations');

        Route::get('/report/export/{report}', 'export')
            ->middleware('permission:report.view')->name('admin.report.export');
    });

    /*
    |--------------------------------------------------------------------------
    | Payments and driver earnings (المدفوعات والأرباح)
    |--------------------------------------------------------------------------
    |
    | Both were only reachable one order at a time. Payments could be seen inside
    | an order and nowhere else, so a day's takings could not be reconciled against
    | the gateway without querying the database; driver earnings existed only in the
    | driver's own app, so operations could not say what a driver was owed.
    |
    | Super admin only. A payment is the platform collecting money and an earning
    | is the platform owing it — a laundry is party to neither, and neither model
    | uses BelongsToLaundry, so the permission is the whole protection.
    */
    Route::controller(PaymentLedgerController::class)->group(function () {
        Route::get('/payment', 'payments')
            ->middleware('permission:payment.view')->name('admin.payment.index');
        Route::get('/payment/search', 'searchPayments')
            ->middleware('permission:payment.view')->name('admin.payment.search');

        Route::get('/earning', 'earnings')
            ->middleware('permission:driver_earning.view')->name('admin.earning.index');
        Route::get('/earning/search', 'searchEarnings')
            ->middleware('permission:driver_earning.view')->name('admin.earning.search');
    });

    /*
    |--------------------------------------------------------------------------
    | Complaints (الشكاوى)
    |--------------------------------------------------------------------------
    |
    | Operations answers by phone, per the owner's decision, so there is no reply
    | thread — the screen hands over a phone number, a status the complainant can
    | see, and an internal note they cannot.
    |
    | `complaint.*` is granted to the **super admin only**, and that is the whole
    | protection: `Complaint` carries a laundry_id for reporting but deliberately
    | does NOT use BelongsToLaundry, because the owner decided complaints are the
    | platform's to handle. Granting this to a laundry role opens every customer's
    | complaint to them.
    */
    Route::controller(ComplaintController::class)->group(function () {
        Route::get('/complaint', 'index')
            ->middleware('permission:complaint.view')->name('admin.complaint.index');
        Route::get('/complaint/search', 'search')
            ->middleware('permission:complaint.view')->name('admin.complaint.search');
        Route::get('/complaint/show/{id}', 'show')
            ->middleware('permission:complaint.view')->name('admin.complaint.show');
        Route::post('/complaint/transition/{id}', 'transition')
            ->middleware('permission:complaint.update')->name('admin.complaint.transition');
        Route::post('/complaint/note/{id}', 'note')
            ->middleware('permission:complaint.update')->name('admin.complaint.note');
    });

    /*
    |--------------------------------------------------------------------------
    | FAQ (الأسئلة الشائعة)
    |--------------------------------------------------------------------------
    |
    | The first item under «المساعدة والدعم» in both apps. The content had nowhere
    | to live at all before this — no screen, no endpoint, no table.
    |
    | `audience` is why this is not just another content list: the driver app shows
    | the same section and the answers differ, so an entry is aimed at one app or
    | shared by both.
    */
    Route::controller(FaqController::class)->group(function () {
        Route::get('/faq', 'index')->middleware('permission:faq.view')->name('admin.faq.index');
        Route::get('/faq/search', 'search')->middleware('permission:faq.view')->name('admin.faq.search');
        Route::get('/faq/create', 'create')->middleware('permission:faq.create')->name('admin.faq.create');
        Route::post('/faq/store', 'store')->middleware('permission:faq.create')->name('admin.faq.store');
        Route::get('/faq/show/{id}', 'show')->middleware('permission:faq.view')->name('admin.faq.show');
        Route::get('/faq/edit/{id}', 'edit')->middleware('permission:faq.update')->name('admin.faq.edit');
        Route::put('/faq/update/{id}', 'update')->middleware('permission:faq.update')->name('admin.faq.update');
        Route::delete('/faq/delete/{id}', 'destroy')->middleware('permission:faq.delete')->name('admin.faq.delete');
        Route::post('/faq/status/{id}', 'toggleStatus')
            ->middleware('permission:faq.toggle')->name('admin.faq.toggleStatus');
    });

    /*
    |--------------------------------------------------------------------------
    | Ratings (التقييمات)
    |--------------------------------------------------------------------------
    |
    | Every report before this measured speed and disputes; none could say whether
    | the customer was happy, which is what a laundry is actually judged on.
    |
    | Tenant-safe, unlike the driver and operations reports: `OrderRating` carries
    | a `laundry_id` and uses BelongsToLaundry, so a laundry owner reads its own
    | verdicts and nobody else's. That is why `rating.view` is granted to the
    | laundry roles rather than held back for the super admin.
    */
    Route::controller(RatingController::class)->group(function () {
        Route::get('/rating', 'index')
            ->middleware('permission:order_rating.view')->name('admin.rating.index');
        Route::get('/rating/search', 'search')
            ->middleware('permission:order_rating.view')->name('admin.rating.search');
    });

    /*
    |--------------------------------------------------------------------------
    | Repeat schedules (الاشتراكات)
    |--------------------------------------------------------------------------
    |
    | `recurrences` has had an API and a daily scheduled prompt since P6, and a
    | real notification since P11 — and no screen at any point. Nobody could say
    | how many customers were on a schedule, whether anyone answered, or why a
    | particular customer got a message every week; and support could not stop one.
    |
    | Gated on `order_recurrence.*`, granted to the super admin only: a schedule
    | belongs to a customer and names a service, carries no `laundry_id`, and the
    | tenant scope would therefore not stop one laundry reading another's customers.
    */
    Route::controller(RecurrenceController::class)->group(function () {
        Route::get('/recurrence', 'index')
            ->middleware('permission:order_recurrence.view')->name('admin.recurrence.index');
        Route::get('/recurrence/search', 'search')
            ->middleware('permission:order_recurrence.view')->name('admin.recurrence.search');
        Route::get('/recurrence/show/{id}', 'show')
            ->middleware('permission:order_recurrence.view')->name('admin.recurrence.show');

        // Intervening on a customer's own schedule, so it is a separate
        // permission from reading and every action is a POST.
        Route::post('/recurrence/pause/{id}', 'pause')
            ->middleware('permission:order_recurrence.update')->name('admin.recurrence.pause');
        Route::post('/recurrence/resume/{id}', 'resume')
            ->middleware('permission:order_recurrence.update')->name('admin.recurrence.resume');
        Route::post('/recurrence/cancel/{id}', 'cancel')
            ->middleware('permission:order_recurrence.delete')->name('admin.recurrence.cancel');
    });

    /*
    |--------------------------------------------------------------------------
    | Notification log (سجل الإشعارات)
    |--------------------------------------------------------------------------
    |
    | Read-only. It answers «did the customer get it?», which had no answer at all
    | before P11 — and its failures counter is how a device token that Firebase
    | invalidated months ago stops being invisible.
    |
    */
    Route::controller(NotificationLogController::class)->group(function () {
        Route::get('/notification', 'index')
            ->middleware('permission:notification_log.view')->name('admin.notification.index');
        Route::get('/notification/search', 'search')
            ->middleware('permission:notification_log.view')->name('admin.notification.search');
    });

    /*
    |--------------------------------------------------------------------------
    | Wallets (المحافظ)
    |--------------------------------------------------------------------------
    |
    | No route sets a balance, because nothing anywhere sets a balance — an
    | adjustment writes a transaction like every other change.
    |
    */
    Route::controller(WalletController::class)->group(function () {
        Route::get('/wallet', 'index')->middleware('permission:wallet.view')->name('admin.wallet.index');
        Route::get('/wallet/search', 'search')->middleware('permission:wallet.view')->name('admin.wallet.search');
        Route::get('/wallet/show/{id}', 'show')->middleware('permission:wallet.view')->name('admin.wallet.show');
        Route::post('/wallet/adjust/{id}', 'adjust')->middleware('permission:wallet.update')->name('admin.wallet.adjust');
        Route::post('/wallet/freeze/{id}', 'toggleFreeze')->middleware('permission:wallet.update')->name('admin.wallet.freeze');
    });

    /*
    |--------------------------------------------------------------------------
    | Zones (المنطقة inside a city)
    |--------------------------------------------------------------------------
    */
    Route::controller(ZoneController::class)->group(function () {
        Route::get('/zone', 'index')->middleware('permission:zone.view')->name('admin.zone.index');
        Route::get('/zone/search', 'search')->middleware('permission:zone.view')->name('admin.zone.search');
        Route::get('/zone/create', 'create')->middleware('permission:zone.create')->name('admin.zone.create');
        Route::post('/zone/store', 'store')->middleware('permission:zone.create')->name('admin.zone.store');
        Route::get('/zone/show/{id}', 'show')->middleware('permission:zone.view')->name('admin.zone.show');
        Route::get('/zone/edit/{id}', 'edit')->middleware('permission:zone.update')->name('admin.zone.edit');
        Route::put('/zone/update/{id}', 'update')->middleware('permission:zone.update')->name('admin.zone.update');
        Route::delete('/zone/delete/{id}', 'destroy')->middleware('permission:zone.delete')->name('admin.zone.delete');
        Route::post('/zone/status/{id}', 'toggleStatus')->middleware('permission:zone.toggle')->name('admin.zone.toggleStatus');
    });

    /*
    |--------------------------------------------------------------------------
    | Time slots (pickup / delivery windows)
    |--------------------------------------------------------------------------
    */
    Route::controller(TimeSlotController::class)->group(function () {
        Route::get('/time-slot', 'index')->middleware('permission:time_slot.view')->name('admin.time_slot.index');
        Route::get('/time-slot/create', 'create')->middleware('permission:time_slot.create')->name('admin.time_slot.create');
        Route::post('/time-slot/store', 'store')->middleware('permission:time_slot.create')->name('admin.time_slot.store');
        Route::get('/time-slot/show/{id}', 'show')->middleware('permission:time_slot.view')->name('admin.time_slot.show');
        Route::get('/time-slot/edit/{id}', 'edit')->middleware('permission:time_slot.update')->name('admin.time_slot.edit');
        Route::put('/time-slot/update/{id}', 'update')->middleware('permission:time_slot.update')->name('admin.time_slot.update');
        Route::delete('/time-slot/delete/{id}', 'destroy')->middleware('permission:time_slot.delete')->name('admin.time_slot.delete');
        Route::post('/time-slot/status/{id}', 'toggleStatus')->middleware('permission:time_slot.toggle')->name('admin.time_slot.toggleStatus');
    });

    /*
    |--------------------------------------------------------------------------
    | Laundry service areas — which zones a tenant covers
    |--------------------------------------------------------------------------
    */
    Route::controller(LaundryZoneController::class)->group(function () {
        Route::get('/laundry-zone', 'index')->middleware('permission:laundry_zone.view')->name('admin.laundry_zone.index');
        Route::put('/laundry-zone/update', 'update')->middleware('permission:laundry_zone.update')->name('admin.laundry_zone.update');
    });

    /*
    |--------------------------------------------------------------------------
    | roles
    |--------------------------------------------------------------------------
    */

    Route::controller(RoleController::class)->group(function () {

        Route::get('/roles', [RoleController::class, 'index'])
            ->middleware('permission:role.view')
            ->name('admin.roles.index');

        Route::post('/roles/{role}/permissions', [RoleController::class, 'updatePermissions'])
            ->middleware('permission:role.update')
            ->name('admin.roles.permissions.update');

        Route::post('/roles', [RoleController::class, 'store'])
            ->middleware('permission:role.create')
            ->name('admin.roles.store');
    });

    /*
    |--------------------------------------------------------------------------
    | Settings
    |--------------------------------------------------------------------------
    */
    Route::controller(SettingController::class)->group(function () {
        Route::get('/generalSetting', 'viewGeneralSetting')
            ->middleware('permission:setting.update')
            ->name('admin.generalSetting.viewGeneralSetting');

        Route::put('/generalSetting/update', 'updateGeneralSetting')
            ->middleware('permission:setting.update')
            ->name('admin.generalSetting.updateGeneralSetting');

        Route::get('/PrivacyAndTerms', 'viewPrivacyAndTerms')
            ->middleware('permission:setting.update')
            ->name('admin.generalSetting.viewPrivacyAndTerms');

        Route::put('/PrivacyAndTerms/update', 'updatePrivacyAndTerms')
            ->middleware('permission:setting.update')
            ->name('admin.generalSetting.updatePrivacyAndTerms');
    });
});
