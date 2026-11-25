<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BusinessFactDecisionController;
use App\Http\Controllers\Api\BusinessIntelligenceController;
use App\Http\Controllers\Api\GeneratedWebsiteController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\ProjectSourceController;
use App\Http\Controllers\Api\PublicWebsiteController;
use App\Http\Controllers\Api\SourceExtractionController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WebsiteProjectController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::get('/public/websites/{slug}', [PublicWebsiteController::class, 'show'])
    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*');
Route::get('/public/websites/{slug}/images/{source}', [PublicWebsiteController::class, 'image'])
    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')
    ->whereNumber('source');

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
    Route::get('/projects/{project}/sources/{source}/image', [ProjectSourceController::class, 'image'])
        ->name('projects.sources.image');

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

    Route::get('/projects/{project}/website', [GeneratedWebsiteController::class, 'show']);
    Route::post('/projects/{project}/website/publish', [GeneratedWebsiteController::class, 'publish'])
        ->middleware('throttle:10,1');
    Route::delete('/projects/{project}/website/publish', [GeneratedWebsiteController::class, 'unpublish'])
        ->middleware('throttle:10,1');
    Route::post('/projects/{project}/website/generate', [GeneratedWebsiteController::class, 'generate'])
        ->middleware('throttle:5,1');
    Route::put('/projects/{project}/website', [GeneratedWebsiteController::class, 'update'])
        ->middleware('throttle:20,1');
    Route::post('/projects/{project}/website/regenerate-section', [GeneratedWebsiteController::class, 'regenerateSection'])
        ->middleware('throttle:5,1');
    Route::get('/projects/{project}/website/revisions', [GeneratedWebsiteController::class, 'revisions']);
    Route::get(
        '/projects/{project}/website/revisions/{revision}',
        [GeneratedWebsiteController::class, 'revision'],
    )->whereNumber('revision');
    Route::delete(
        '/projects/{project}/website/revisions/{revision}',
        [GeneratedWebsiteController::class, 'destroyRevision'],
    )->whereNumber('revision');
});
