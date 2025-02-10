<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\v1\OrderController;
use App\Http\Controllers\Api\v1\EmailTemplateController;

Route::middleware(['verify.token'])->group(function () {
    Route::prefix('order')->middleware(['verify.permission'])->group(function () {
        Route::get('order', [OrderController::class, 'index']);
        Route::post('orders_update', [OrderController::class, 'ordersUpdate']);
        Route::get('check_order_process', [OrderController::class, 'checkOrderProcess']);
        Route::get('order/{id}', [OrderController::class, 'show']);
        Route::get('order_update_confirm', [OrderController::class, 'orderUpdateConfirm']);
        Route::put('order', [OrderController::class, 'update']);
        Route::post('order_reserve/{id}', [OrderController::class, 'orderReserve']);
        Route::post('order_cancel/{id}', [OrderController::class, 'orderCancel']);

        Route::get('email_send_logs_with_templates/{id}/{type}', [OrderController::class, 'emailSendLogsWithTemplates']);
        Route::post('resend_email/{id}/{template_id}', [OrderController::class, 'resendEmail']);
        Route::post('update_order_item/{id}', [OrderController::class, 'updateOrderItem']);
        Route::post('update_shipping_data/{id}', [OrderController::class, 'updateShippingData']);
        Route::get('order_item_more/{id}', [OrderController::class, 'orderItemMore']);
        Route::post('update_order_item_more/{id}', [OrderController::class, 'updateOrderItemMore']);

        Route::get('histories', [OrderController::class, 'histories']);
        Route::get('history_view/{id}', [OrderController::class, 'historyView']);
        Route::get('shipping_info/{order_item_id}', [OrderController::class, 'shippingInfo']);

        Route::post('unity_update_order_item', [OrderController::class, 'unityUpdateOrderItem']);

        Route::resource('email_template', EmailTemplateController::class);
        Route::get('email_templates/{market}', [EmailTemplateController::class, 'getEmailTemplates']);
        Route::get('shop_email_templates/{shop_id}', [EmailTemplateController::class, 'getShopEmailTemplate']);
    });
});
