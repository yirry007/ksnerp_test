<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\v1\AuthController;

Route::post('auth', [AuthController::class, 'auth']);
Route::post('refresh_token', [AuthController::class, 'refreshToken']);
Route::get('get_language_package', [AuthController::class, 'getLanguagePackage']);

Route::middleware(['verify.token'])->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
});
