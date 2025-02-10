<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ImageSearchController;

Route::post('image_save', [ImageSearchController::class, 'imageSave']);
Route::post('image_upload', [ImageSearchController::class, 'imageUpload']);
Route::post('image_search', [ImageSearchController::class, 'imageSearch']);
