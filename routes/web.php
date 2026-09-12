<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::inertia('/', 'Organizations/Index')->name('home');

    Route::post('/organizations', [OrganizationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('organizations.store');
    Route::get('/organizations/{organization}', [OrganizationController::class, 'show'])
        ->whereNumber('organization')
        ->name('organizations.show');

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
