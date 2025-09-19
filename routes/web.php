<?php

use App\Http\Controllers\Admin\banner\BannerController;
use App\Http\Controllers\Admin\Category\CategoryController;
use App\Http\Controllers\Admin\intro\IntroController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\setting\SettingController;
use App\Http\Controllers\Admin\user\UserController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;



Route::get('/', static function () {
    if (Auth::user()) {
        return redirect('/admin/home');
    }
    return view('auth.login');
});
Auth::routes(['register' => false]);

Route::middleware(['auth', 'user-role:admin'])->prefix('/admin')->group(function () {
    Route::get('/home', [HomeController::class, 'index'])->name('home');

    Route::get('change-password', [HomeController::class, 'changePasswordIndex'])->name('change-password.index');
    Route::post('change-password', [HomeController::class, 'changePasswordUpdate'])->name('change-password.update');

    Route::controller(LanguageController::class)->group(function () {
        Route::get('/language', 'index')->name('admin.language.index');
        Route::get('/language/search', 'search')->name('admin.language.search');
        Route::get('/language/create', 'create')->name('admin.language.create');
        Route::post('/language/store', 'store')->name('admin.language.store');
        Route::get('/language/show/{id}', 'show')->name('admin.language.show');
        Route::get('/language/edit/{id}', 'edit')->name('admin.language.edit');
        Route::put('/language/update/{id}', 'update')->name('admin.language.update');
        Route::delete('/language/delete/{id}', 'destroy')->name('admin.language.delete');

        Route::get('set-language/{lang}', 'setLanguage')->name('language.set-current');

        Route::get('/language/panel/{id}', 'showPanel')->name('admin.language.panel');
        Route::post('/language/panel/update/{id}', 'updatePanel')->name('admin.language.panel.update');

        Route::get('/language/mobile/{id}', 'showMobile')->name('admin.language.mobile');
        Route::post('/language/mobile/update/{id}', 'updateMobile')->name('admin.language.mobile.update');

        Route::get('/language/web/{id}', 'showWeb')->name('admin.language.web');
        Route::post('/language/web/update/{id}', 'updateWeb')->name('admin.language.web.update');
    });

    // start user route
    Route::controller(UserController::class)->group(function () {
        Route::get('/user', 'index')->name('admin.user.index');
        Route::get('/user/search', 'search')->name('admin.user.search');
        Route::get('/user/create', 'create')->name('admin.user.create');
        Route::post('/user/store', 'store')->name('admin.user.store');
        Route::get('/user/show/{id}', 'show')->name('admin.user.show');
        Route::get('/user/edit/{id}', 'edit')->name('admin.user.edit');
        Route::put('/user/update/{id}', 'update')->name('admin.user.update');
        Route::delete('/user/delete/{id}', 'destroy')->name('admin.user.delete');
        Route::post('/user/status/{id}', 'toggleStatus')->name('admin.user.toggleStatus');
    });
    // end user route

    // start Intros route
    Route::controller(IntroController::class)->group(function () {
        Route::get('/intro', 'index')->name('admin.intro.index');
        Route::get('/intro/search', 'search')->name('admin.intro.search');
        Route::get('/intro/create', 'create')->name('admin.intro.create');
        Route::post('/intro/store', 'store')->name('admin.intro.store');
        Route::get('/intro/show/{id}', 'show')->name('admin.intro.show');
        Route::get('/intro/edit/{id}', 'edit')->name('admin.intro.edit');
        Route::put('/intro/update/{id}', 'update')->name('admin.intro.update');
        Route::delete('/intro/delete/{id}', 'destroy')->name('admin.intro.delete');
        Route::post('/intro/status/{id}', 'toggleStatus')->name('admin.intro.toggleStatus');
    });
    // end Intros route

    // start Banner route
    Route::controller(BannerController::class)->group(function () {
        Route::get('/banner', 'index')->name('admin.banner.index');
        Route::get('/banner/create', 'create')->name('admin.banner.create');
        Route::post('/banner/store', 'store')->name('admin.banner.store');
        Route::get('/banner/show/{id}', 'show')->name('admin.banner.show');
        Route::get('/banner/search', 'search')->name('admin.banner.search');
        Route::get('/banner/edit/{id}', 'edit')->name('admin.banner.edit');
        Route::put('/banner/update/{id}', 'update')->name('admin.banner.update');
        Route::delete('/banner/delete/{id}', 'destroy')->name('admin.banner.delete');
        Route::post('/banner/status/{id}', 'toggleStatus')->name('admin.banner.toggleStatus');
    });
    // end Banner route

    /* Start generalSetting Route */
    Route::controller(SettingController::class)->group(function () {
        // generalSetting
        Route::get('/generalSetting', 'viewGeneralSetting')->name('admin.generalSetting.viewGeneralSetting');
        Route::put('/generalSetting/update', 'updateGeneralSetting')->name('admin.generalSetting.updateGeneralSetting');

        //Privacy And Terms
        Route::get('/PrivacyAndTerms', 'viewPrivacyAndTerms')->name('admin.generalSetting.viewPrivacyAndTerms');
        Route::put('/PrivacyAndTerms/update', 'updatePrivacyAndTerms')->name('admin.generalSetting.updatePrivacyAndTerms');
    });
    /* End generalSetting Route */
    // start CategoryController route
    Route::controller(CategoryController::class)->group(function () {
        Route::get('/category', 'index')->name('admin.category.index');
        Route::get('/category/search', 'search')->name('admin.category.search');
        Route::get('/category/create', 'create')->name('admin.category.create');
        Route::post('/category/store', 'store')->name('admin.category.store');
        Route::get('/category/show/{id}', 'show')->name('admin.category.show');
        Route::get('/category/showSubCategories/{id}', 'showSubCategories')->name('admin.category.showSubCategories');
        Route::get('/category/edit/{id}', 'edit')->name('admin.category.edit');
        Route::put('/category/update/{id}', 'update')->name('admin.category.update');
        Route::delete('/category/delete/{id}', 'destroy')->name('admin.category.delete');
        Route::post('/category/status/{id}', 'toggleStatus')->name('admin.category.toggleStatus');
    });
    // end CategoryController route
});
