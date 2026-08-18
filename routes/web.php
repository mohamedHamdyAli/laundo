<?php

use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\HomeController;
use App\Modules\Banner\Controllers\BannerController;
use App\Modules\Category\Controllers\CategoryController;
use App\Modules\City\Controllers\CityController;
use App\Modules\Country\Controllers\CountryController;
use App\Modules\Intro\Controllers\IntroController;
use App\Modules\Moderator\Controllers\ModeratorController;
use App\Modules\setting\Controllers\SettingController;
use App\Modules\User\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Admin\RoleController;

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
    | Notifications
    |--------------------------------------------------------------------------
    */
    Route::controller(NotificationController::class)->prefix('notifications')->name('admin.notifications.')->group(function () {
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
    | Category
    |--------------------------------------------------------------------------
    */
    Route::controller(CategoryController::class)->group(function () {
        Route::get('/category', 'index')->middleware('permission:category.view')->name('admin.category.index');
        Route::get('/category/search', 'search')->middleware('permission:category.view')->name('admin.category.search');
        Route::get('/category/create', 'create')->middleware('permission:category.create')->name('admin.category.create');
        Route::post('/category/store', 'store')->middleware('permission:category.create')->name('admin.category.store');
        Route::get('/category/show/{id}', 'show')->middleware('permission:category.view')->name('admin.category.show');
        Route::get('/category/showSubCategories/{id}', 'showSubCategories')->middleware('permission:category.view')->name('admin.category.showSubCategories');
        Route::get('/category/edit/{id}', 'edit')->middleware('permission:category.update')->name('admin.category.edit');
        Route::put('/category/update/{id}', 'update')->middleware('permission:category.update')->name('admin.category.update');
        Route::delete('/category/delete/{id}', 'destroy')->middleware('permission:category.delete')->name('admin.category.delete');
        Route::post('/category/status/{id}', 'toggleStatus')->middleware('permission:category.toggle')->name('admin.category.toggleStatus');
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
