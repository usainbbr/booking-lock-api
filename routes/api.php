<?php

use App\Http\Controllers\Api\BookingController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:booking-api')->group(function (): void {
    Route::get('/resources/{resource:slug}/availability', [BookingController::class, 'availability']);
    Route::post('/resources/{resource:slug}/bookings', [BookingController::class, 'store']);
    Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel']);
});
