<?php

use App\Http\Controllers\TestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::get('/test_get', [TestController::class, 'apiTestGet']);
Route::post('/test_post', [TestController::class, 'apiTestPost']);

/** Chrome Extension API */
Route::prefix('chrome')->group(function () {
    //Load Chrome  Extension API
    require_once __DIR__ . '/api/chrome.php';
});

/** ERP API */
Route::prefix('v1')->group(function () {
    Route::middleware(['set.locale'])->group(function () {
        //Load all routes v1
        $routes = glob(__DIR__ . '/api/v1/*.php');
        foreach ($routes as $route) require_once $route;
    });
});
