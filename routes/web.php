<?php

use App\Http\Controllers\AdminAuthController;
use App\Http\Controllers\AdminClientController;
use App\Http\Controllers\AdminCompanyController;
use App\Http\Controllers\AdminHomepageColorSchemeController;
use App\Http\Controllers\AdminProfileController;
use App\Http\Controllers\AdminRoleController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AdminWebsiteAnalysisController;
use App\Http\Controllers\ClientHomepageController;
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
    Route::view('/admin/formate', 'app')->name('admin.formate.index');
    Route::view('/admin/formate/{formatSection}', 'app')
        ->where('formatSection', '[A-Za-z0-9_-]+')
        ->name('admin.formate');
    Route::view('/admin/helpers/analyse', 'app')->name('admin.helpers.analyse');
    Route::view('/admin/helpers/analyse/{analysis}', 'app')->name('admin.helpers.analyse.show');
    Route::view('/admin/menu/clients-overview', 'app')
        ->middleware('role:super_admin')
        ->name('admin.menu.clients-overview');
    Route::view('/admin/menu/roles', 'app')
        ->middleware('role:super_admin')
        ->name('admin.menu.roles');
    Route::view('/admin/menu/{adminSection}', 'app')
        ->where('adminSection', '[A-Za-z0-9_-]+')
        ->name('admin.menu');
    Route::get('/admin/me', [AdminAuthController::class, 'me'])->name('admin.me');
    Route::get('/admin/clients', [AdminClientController::class, 'index'])->name('admin.clients.index');
    Route::post('/admin/clients/{client:id}/color-schemes', [AdminHomepageColorSchemeController::class, 'store'])->name('admin.clients.color-schemes.store');
    Route::get('/admin/website-analyses', [AdminWebsiteAnalysisController::class, 'index'])->name('admin.website-analyses.index');
    Route::get('/admin/website-analyses/{analysis}', [AdminWebsiteAnalysisController::class, 'show'])->name('admin.website-analyses.show');
    Route::post('/admin/website-analyses/check-url', [AdminWebsiteAnalysisController::class, 'checkUrl'])->name('admin.website-analyses.check-url');
    Route::post('/admin/website-analyses', [AdminWebsiteAnalysisController::class, 'store'])->name('admin.website-analyses.store');
    Route::post('/admin/website-analyses/{analysis}/rerun', [AdminWebsiteAnalysisController::class, 'rerun'])->name('admin.website-analyses.rerun');
    Route::post('/admin/website-analyses/{analysis}/cancel', [AdminWebsiteAnalysisController::class, 'cancel'])->name('admin.website-analyses.cancel');
    Route::delete('/admin/website-analyses/{analysis}', [AdminWebsiteAnalysisController::class, 'destroy'])->name('admin.website-analyses.destroy');
    Route::patch('/admin/profile/name', [AdminProfileController::class, 'updateName'])->name('admin.profile.name');
    Route::patch('/admin/profile/password', [AdminProfileController::class, 'updatePassword'])->name('admin.profile.password');
    Route::match(['GET', 'POST'], '/admin/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');
    Route::patch('/admin/clients/{client:id}/activate', [AdminClientController::class, 'activate'])->name('admin.clients.activate');

    Route::middleware('role:super_admin')->group(function (): void {
        Route::get('/admin/companies', [AdminCompanyController::class, 'index'])->name('admin.companies.index');
        Route::get('/admin/companies/search', [AdminCompanyController::class, 'search'])->name('admin.companies.search');
        Route::post('/admin/companies', [AdminCompanyController::class, 'store'])->name('admin.companies.store');
        Route::patch('/admin/companies/{company}', [AdminCompanyController::class, 'update'])->name('admin.companies.update');
        Route::patch('/admin/companies/{company}/activate', [AdminCompanyController::class, 'activate'])->name('admin.companies.activate');
        Route::delete('/admin/companies/{company}', [AdminCompanyController::class, 'destroy'])->name('admin.companies.destroy');
        Route::post('/admin/clients', [AdminClientController::class, 'store'])->name('admin.clients.store');
        Route::patch('/admin/clients/{client:id}', [AdminClientController::class, 'update'])->name('admin.clients.update');
        Route::delete('/admin/clients/{client:id}', [AdminClientController::class, 'destroy'])->name('admin.clients.destroy');
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

Route::get('/{client}', [ClientHomepageController::class, 'show'])
    ->where('client', '^(?!admin$|up$)[a-z0-9][a-z0-9-]*$')
    ->name('clients.show');
