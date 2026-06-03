<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminDepotController;
use App\Http\Controllers\AdminDepotHoldingController;
use App\Http\Controllers\AdminPriceRefreshSettingsController;
use App\Http\Controllers\AdminProfileController;
use App\Http\Controllers\AdminRoleController;
use App\Http\Controllers\AdminStockSearchController;
use App\Http\Controllers\AdminUserController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'homepage')->name('homepage');
Route::view('/admin/login', 'app')->name('admin.login');

Route::post('/admin/login-code', [AdminAuthController::class, 'requestCode'])
    ->middleware('guest')
    ->name('admin.login-code');

Route::post('/admin/verify-code', [AdminAuthController::class, 'verifyCode'])
    ->middleware('guest')
    ->name('admin.verify-code');

Route::post('/admin/password-login', [AdminAuthController::class, 'passwordLogin'])
    ->middleware('guest')
    ->name('admin.password-login');

Route::middleware(['auth', 'role:admin|super_admin'])->group(function (): void {
    Route::view('/admin', 'app')->name('admin.dashboard');
    Route::view('/admin/dashboard', 'app')->name('admin.dashboard.view');
    Route::view('/admin/profile', 'app')->name('admin.profile.view');
    Route::view('/admin/menu/roles', 'app')
        ->middleware('role:super_admin')
        ->name('admin.menu.roles');
    Route::view('/admin/menu/depots', 'app')->name('admin.menu.depots');
    Route::view('/admin/menu/{adminSection}', 'app')
        ->where('adminSection', '[A-Za-z0-9_-]+')
        ->name('admin.menu');
    Route::get('/admin/me', [AdminAuthController::class, 'me'])->name('admin.me');
    Route::get('/admin/depots', [AdminDepotController::class, 'index'])->name('admin.depots.index');
    Route::get('/admin/depots/active', [AdminDepotController::class, 'active'])->name('admin.depots.active');
    Route::get('/admin/price-refresh-settings', [AdminPriceRefreshSettingsController::class, 'show'])->name('admin.price-refresh-settings.show');
    Route::patch('/admin/price-refresh-settings', [AdminPriceRefreshSettingsController::class, 'update'])->name('admin.price-refresh-settings.update');
    Route::post('/admin/depots', [AdminDepotController::class, 'store'])->name('admin.depots.store');
    Route::patch('/admin/depots/{depot}', [AdminDepotController::class, 'update'])->name('admin.depots.update');
    Route::patch('/admin/depots/{depot}/activate', [AdminDepotController::class, 'activate'])->name('admin.depots.activate');
    Route::get('/admin/active-depot/holdings', [AdminDepotHoldingController::class, 'index'])->name('admin.active-depot.holdings.index');
    Route::post('/admin/active-depot/holdings', [AdminDepotHoldingController::class, 'store'])->name('admin.active-depot.holdings.store');
    Route::post('/admin/active-depot/holdings/refresh-prices', [AdminDepotHoldingController::class, 'refreshPrices'])->name('admin.active-depot.holdings.refresh-prices');
    Route::get('/admin/active-depot/holdings/refresh-prices/{refreshId}', [AdminDepotHoldingController::class, 'refreshPriceStatus'])->name('admin.active-depot.holdings.refresh-prices.status');
    Route::delete('/admin/active-depot/holdings/{holding}', [AdminDepotHoldingController::class, 'destroy'])->name('admin.active-depot.holdings.destroy');
    Route::get('/admin/stocks/search', [AdminStockSearchController::class, 'index'])->name('admin.stocks.search');
    Route::patch('/admin/profile/name', [AdminProfileController::class, 'updateName'])->name('admin.profile.name');
    Route::patch('/admin/profile/password', [AdminProfileController::class, 'updatePassword'])->name('admin.profile.password');
    Route::match(['GET', 'POST'], '/admin/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

    Route::middleware('role:super_admin')->group(function (): void {
        Route::get('/admin/users', [AdminUserController::class, 'index'])->name('admin.users.index');
        Route::post('/admin/users', [AdminUserController::class, 'store'])->name('admin.users.store');
        Route::patch('/admin/users/{user}', [AdminUserController::class, 'update'])->name('admin.users.update');
        Route::delete('/admin/users/{user}', [AdminUserController::class, 'destroy'])->name('admin.users.destroy');
        Route::get('/admin/roles', [AdminRoleController::class, 'index'])->name('admin.roles.index');
        Route::post('/admin/roles', [AdminRoleController::class, 'store'])->name('admin.roles.store');
        Route::patch('/admin/roles/{role}', [AdminRoleController::class, 'update'])->name('admin.roles.update');
        Route::delete('/admin/roles/{role}', [AdminRoleController::class, 'destroy'])->name('admin.roles.destroy');
    });
});
