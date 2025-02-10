<?php

use App\Http\Controllers\Api\v1\MainController;

Route::middleware(['verify.token'])->group(function () {
    Route::prefix('main')->group(function () {
        Route::get('version_info', [MainController::class, 'versionInfo']);
        Route::post('version_update', [MainController::class, 'versionUpdate']);
        Route::get('version_update_logs', [MainController::class, 'versionUpdateLogs']);

        Route::get('order_num_last_month', [MainController::class, 'orderNumLastMonth']);
        Route::get('order_price_last_month', [MainController::class, 'orderPriceLastMonth']);
        Route::get('order_num_percent', [MainController::class, 'orderNumPercent']);
        Route::get('order_price_percent', [MainController::class, 'orderPricePercent']);
    });
});
