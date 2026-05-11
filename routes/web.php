<?php

use App\Http\Controllers\Api\GoogleCalendarAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Google OAuth callback — browser redirect, no Sanctum needed
Route::get('/google/callback', [GoogleCalendarAuthController::class, 'callback'])
    ->name('google.callback');
