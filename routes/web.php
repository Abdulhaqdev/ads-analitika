<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdController;
use App\Http\Controllers\AlertsController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\FacebookController;
use App\Http\Controllers\InsightsController;
use App\Http\Controllers\LaunchController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\RecommendationsController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::get('/', [OverviewController::class, 'index'])->name('dashboard');
    Route::get('/accounts/{account}/{section?}', [AccountController::class, 'show'])->name('accounts.show');
    Route::get('/launch', [LaunchController::class, 'index'])->name('launch');
    Route::post('/accounts/{account}/launch', [LaunchController::class, 'store'])->name('accounts.launch');
    Route::get('/recommendations', [RecommendationsController::class, 'index'])->name('recommendations');
    Route::get('/alerts', [AlertsController::class, 'index'])->name('alerts');
    Route::get('/ads/{ad}', [AdController::class, 'show'])->name('ads.show');
    Route::get('/insights', [InsightsController::class, 'index'])->name('insights');

    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');

    Route::prefix('admin/facebook')->name('facebook.')->group(function () {
        Route::get('/', [FacebookController::class, 'index'])->name('index');
        Route::get('/redirect', [FacebookController::class, 'redirect'])->name('redirect');
        Route::get('/callback', [FacebookController::class, 'callback'])->name('callback');
        Route::patch('/accounts/{account}', [FacebookController::class, 'updateAccount'])->name('accounts.update');
        Route::post('/accounts/{account}/sync', [FacebookController::class, 'syncAccount'])->name('accounts.sync');
        Route::post('/{connection}/sync', [FacebookController::class, 'sync'])->name('sync');
        Route::delete('/{connection}', [FacebookController::class, 'destroy'])->name('destroy');
    });
});
