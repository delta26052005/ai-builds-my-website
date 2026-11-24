<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusinessFactDecisionController;
use App\Http\Controllers\Api\BusinessIntelligenceController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ProjectSourceController;
use App\Http\Controllers\Api\SourceExtractionController;
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

    Route::get('/projects/{project}/extractions', [SourceExtractionController::class, 'index']);
    Route::post('/projects/{project}/sources/{source}/process', [SourceExtractionController::class, 'process'])
        ->middleware('throttle:10,1');
    Route::get('/projects/{project}/sources/{source}/extraction', [SourceExtractionController::class, 'show']);
    Route::get('/projects/{project}/sources/{source}/extraction/content', [SourceExtractionController::class, 'content']);

    Route::post('/projects/{project}/research', [BusinessIntelligenceController::class, 'startResearch'])
        ->middleware('throttle:5,1');
    Route::get('/projects/{project}/research/status', [BusinessIntelligenceController::class, 'researchStatus']);
    Route::get('/projects/{project}/research/sources', [BusinessIntelligenceController::class, 'researchSources']);
    Route::post('/projects/{project}/facts/extract', [BusinessIntelligenceController::class, 'startFactExtraction'])
        ->middleware('throttle:5,1');
    Route::get('/projects/{project}/facts/status', [BusinessIntelligenceController::class, 'factStatus']);
    Route::get('/projects/{project}/facts', [BusinessIntelligenceController::class, 'facts']);
    Route::get('/projects/{project}/facts/{fact}', [BusinessIntelligenceController::class, 'showFact']);
    Route::patch(
        '/projects/{project}/facts/{fact}/candidates/{candidate}',
        [BusinessFactDecisionController::class, 'update'],
    );
});
