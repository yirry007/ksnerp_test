<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ChromeController;

Route::middleware(['verify.token'])->group(function () {
    /** chrome extension popup api */
    Route::get('main_data', [ChromeController::class, 'mainData']);
    Route::get('item_list', [ChromeController::class, 'itemList']);
    Route::get('item_info', [ChromeController::class, 'itemInfo']);

    /** chrome extension content api */
    Route::get('item_count', [ChromeController::class, 'itemCount']);
    Route::get('get_sourcing_items', [ChromeController::class, 'getSourcingItems']);
    Route::post('update_order_item/{id}', [ChromeController::class, 'updateOrderItem']);
    Route::get('has_matched/{supply_code}', [ChromeController::class, 'hasMatched']);
    Route::get('get_matched_item/{supply_code}', [ChromeController::class, 'getMatchedItem']);
    Route::get('get_sourced_items', [ChromeController::class, 'getSourcedItems']);
    Route::post('update_order', [ChromeController::class, 'updateOrder']);
    Route::post('item_match', [ChromeController::class, 'itemMatch']);
    Route::post('unity_update_order_item/{id}', [ChromeController::class, 'unityUpdateOrderItem']);
});
