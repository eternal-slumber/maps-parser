<?php

use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\ReviewController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/organizations', [OrganizationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('organizations.store');
    Route::get('/organizations/{organization}', [OrganizationController::class, 'show'])
        ->whereNumber('organization')
        ->name('organizations.show');
    Route::get('/organizations/{organization}/reviews', [ReviewController::class, 'index'])
        ->whereNumber('organization')
        ->name('organizations.reviews.index');
});
