<?php

use App\Http\Controllers\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Organizations/Index')->name('home');

Route::middleware('auth')->group(function () {
    Route::post('/organizations', [OrganizationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('organizations.store');
    Route::get('/organizations/{organization}', [OrganizationController::class, 'show'])
        ->whereNumber('organization')
        ->name('organizations.show');
});
