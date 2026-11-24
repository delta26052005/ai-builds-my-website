<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ProjectSourceController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WebsiteProjectController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::middleware('throttle:5,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::get('/user', UserController::class)->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('projects', WebsiteProjectController::class)
        ->parameters(['projects' => 'project']);

    Route::get('/projects/{project}/sources', [ProjectSourceController::class, 'index']);
    Route::post('/projects/{project}/sources', [ProjectSourceController::class, 'store']);
    Route::delete('/projects/{project}/sources/{source}', [ProjectSourceController::class, 'destroy']);
});
