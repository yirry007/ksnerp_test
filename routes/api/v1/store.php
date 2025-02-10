<?php

use Illuminate\Support\Facades\Route;
use \App\Http\Controllers\Api\v1\StoreController;

Route::middleware(['verify.token'])->group(function () {
    Route::prefix('store')->middleware(['verify.permission'])->group(function () {
        Route::get('store_info', [StoreController::class, 'storeInfo']);
        Route::get('get_sku_keys', [StoreController::class, 'getSkuKeys']);
        Route::get('get_logistics', [StoreController::class, 'getLogistics']);
        Route::get('store_requesting', [StoreController::class, 'storeRequesting']);
        Route::post('store_request', [StoreController::class, 'storeRequest']);
        Route::post('update_requesting', [StoreController::class, 'updateRequesting']);
        Route::post('cancel_requesting', [StoreController::class, 'cancelRequesting']);
        Route::get('get_goods_items/{code}', [StoreController::class, 'getGoodsItems']);
        Route::post('store_goods_delete/{id}', [StoreController::class, 'storeGoodsDelete']);
        Route::post('add_store_out_list', [StoreController::class, 'addStoreOutList']);
        Route::get('store_out_requesting', [StoreController::class, 'storeOutRequesting']);
        Route::get('store_out_list', [StoreController::class, 'storeOutList']);
        Route::post('cancel_out_requesting/{id}', [StoreController::class, 'cancelOutRequesting']);
        Route::post('delete_out_list/{id}', [StoreController::class, 'deleteOutList']);
        Route::post('store_out_request', [StoreController::class, 'storeOutRequest']);
        Route::get('get_goods_item_by_sku/{sku}', [StoreController::class, 'getGoodsItemBySku']);
        Route::post('store_transfer', [StoreController::class, 'storeTransfer']);
        Route::get('store_in_logs', [StoreController::class, 'storeInLogs']);
        Route::get('store_out_logs', [StoreController::class, 'storeOutLogs']);
    });
});
