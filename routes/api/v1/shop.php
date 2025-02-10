<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\v1\ShopController;
use App\Http\Controllers\Api\v1\UserController;
use App\Http\Controllers\Api\v1\AdminController;
use App\Http\Controllers\Api\v1\ExcelTemplateController;

Route::middleware(['verify.token'])->group(function () {
    Route::prefix('shop')->middleware(['verify.permission'])->group(function () {
        Route::resource('shop', ShopController::class);

        Route::post('check_connection/{id}', [ShopController::class, 'checkConnection']);
        Route::post('check_all_connection', [ShopController::class, 'checkAllConnection']);

        Route::resource('user', UserController::class);

        Route::get('menu', [UserController::class, 'getMenu']);
        Route::get('user_menu/{user_id}', [UserController::class, 'getUserMenu']);

        Route::get('shops', [UserController::class, 'getShops']);
        Route::get('user_shops/{user_id}', [UserController::class, 'getUserShops']);

        Route::resource('excel_template', ExcelTemplateController::class);
        Route::get('get_export_fields/{type}', [ExcelTemplateController::class, 'getExportFields']);
        Route::get('get_export_data/{template_id}', [ExcelTemplateController::class, 'getExportData']);
        Route::post('import_excel', [ExcelTemplateController::class, 'importExcel']);
    });

    Route::prefix('admin')->middleware(['verify.super'])->group(function () {
        Route::resource('admin', AdminController::class);
        Route::get('get_language_data', [AdminController::class, 'getLanguageData']);
        Route::post('update_language_data', [AdminController::class, 'updateLanguageData']);
        Route::post('language_add', [AdminController::class, 'languageAdd']);
    });
});
