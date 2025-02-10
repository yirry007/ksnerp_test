<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\v1\ItemController;

Route::middleware(['verify.token'])->group(function () {
    Route::prefix('item')->middleware(['verify.permission'])->group(function () {
        Route::resource('item', ItemController::class);
        Route::post('discover', [ItemController::class, 'discover']);
        Route::post('update_item_image/{id}', [ItemController::class, 'updateItemImage']);
        Route::post('update_item_supply_id', [ItemController::class, 'updateItemSupplyId']);
        Route::post('change_status/{id}', [ItemController::class, 'changeStatus']);
        Route::post('merge', [ItemController::class, 'merge']);
        Route::get('supply_more/{supply_id}', [ItemController::class, 'supplyMore']);
        Route::post('update_supply_more/{id}/{mode?}', [ItemController::class, 'updateSupplyMore']);
        Route::post('delete_supply_more/{id}/{mode?}', [ItemController::class, 'deleteSupplyMore']);

        Route::get('sourcing', [ItemController::class, 'sourcing']);
        Route::post('map_item', [ItemController::class, 'mapItem']);
        Route::get('get_supply_url/{item_id}', [ItemController::class, 'getSupplyUrl']);
        Route::post('remap_item', [ItemController::class, 'remapItem']);
        Route::post('add_supply_more', [ItemController::class, 'addSupplyMore']);
        Route::post('update_supply_memo', [ItemController::class, 'updateSupplyMemo']);
        Route::post('update_logistic', [ItemController::class, 'updateLogistic']);
        Route::post('update_shipping_option', [ItemController::class, 'updateShippingOption']);
        Route::get('sourcing_view', [ItemController::class, 'sourcingView']);
    });
});
