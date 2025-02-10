<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\v1\SupplierController;

Route::post('update_supplier_info', [SupplierController::class, 'updateSupplierInfo']);

Route::middleware(['verify.token'])->group(function () {
    Route::prefix('supplier')->group(function () {
        Route::get('get_supplier_infos', [SupplierController::class, 'getSupplierInfos']);
        Route::post('supplier_account_auth', [SupplierController::class, 'supplierAccountAuth']);
        Route::post('supplier_cancel_auth', [SupplierController::class, 'supplierCancelAuth']);

        Route::middleware(['verify.supply'])->group(function () {
            Route::get('get_supplier_encrypted', [SupplierController::class, 'getSupplierEncrypted']);
            Route::get('get_item/{item_id}', [SupplierController::class, 'getItem']);
            Route::get('get_address', [SupplierController::class, 'getAddress']);
            Route::get('get_order_items', [SupplierController::class, 'getOrderItems']);
            Route::post('create_orders', [SupplierController::class, 'createOrders']);
        });
    });
});
