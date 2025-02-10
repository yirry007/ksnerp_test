<?php

use App\Http\Controllers\TestController;
use App\Http\Controllers\Web\IndexController;
use App\Http\Controllers\Web\YahooController;
use App\Http\Controllers\Web\ChromeController;
use App\Http\Controllers\Web\SupplierController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/test_get', [TestController::class, 'testGet']);
Route::post('/test_post', [TestController::class, 'testPost']);

Route::get('/', [IndexController::class, 'index']);

Route::get('/yahoo_auth/{shop_id}', [YahooController::class, 'auth']);
Route::get('/yahoo_redirect/{shop_id}', [YahooController::class, 'redirect']);

Route::get('/supplier_auth/{key}', [SupplierController::class, 'auth']);
Route::get('/supplier_redirect', [SupplierController::class, 'redirect']);
Route::post('/supplier_notify', [SupplierController::class, 'notify']);

Route::get('/privacy_policy', [ChromeController::class, 'privacyPolicy']);
