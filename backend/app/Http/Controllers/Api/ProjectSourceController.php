<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\StoreProjectSourcesRequest;
use App\Http\Resources\Api\ProjectSourceResource;
use App\Jobs\ProcessProjectSource;
use App\Models\ProjectSource;
use App\Models\WebsiteProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ProjectSourceController
{
    private const DISK = 'project_sources';

    private const MAX_SOURCES_PER_PROJECT = 25;

    public function index(WebsiteProject $project): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);

        return ProjectSourceResource::collection(
            $project->sources()->with('extraction')->latest()->get(),
        );
    }

    public function store(
        StoreProjectSourcesRequest $request,
        WebsiteProject $project,
    ): JsonResponse {
        Gate::authorize('view', $project);

        $validated = $request->validated();
        $files = array_values($validated['files']);
        $sourceTypes = $validated['source_types'] ?? [];

        $storedPaths = [];

        try {
            $sources = DB::transaction(function () use ($files, $sourceTypes, $project, &$storedPaths) {
                $project = WebsiteProject::query()
                    ->lockForUpdate()
                    ->findOrFail($project->getKey());

                if ($project->sources()->count() + count($files) > self::MAX_SOURCES_PER_PROJECT) {
                    throw ValidationException::withMessages([
                        'files' => ['A project can contain no more than 25 uploaded files.'],
                    ]);
                }

                $sources = [];

                foreach ($files as $index => $file) {
                    $extension = strtolower($file->extension());
                    $filename = Str::uuid().'.'.$extension;
                    $directory = (string) $project->getKey();
                    $path = $directory.'/'.$filename;
                    $storedPaths[] = $path;

                    if (! $file->storeAs($directory, $filename, self::DISK)) {
                        throw new RuntimeException('Unable to store project source file.');
                    }

                    $originalFilename = basename(str_replace('\\', '/', $file->getClientOriginalName()));

                    $sources[] = $project->sources()->create([
                        'original_filename' => mb_substr($originalFilename, 0, 255),
                        'stored_path' => $path,
                        'mime_type' => $file->getMimeType(),
                        'file_size' => $file->getSize(),
                        'source_type' => $sourceTypes[$index]
                            ?? ($file->getMimeType() === 'application/pdf' ? 'pdf' : 'image'),
                    ]);
                    $sources[array_key_last($sources)]->extraction()->create([
                        'status' => 'pending',
                    ]);
                }

                return $sources;
            });
        } catch (Throwable $exception) {
            try {
                if ($storedPaths !== [] && ! Storage::disk(self::DISK)->delete($storedPaths)) {
                    report(new RuntimeException('Unable to clean up project source files after upload failure.'));
                }
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }

            throw $exception;
        }

        foreach ($sources as $source) {
            try {
                $source->extraction()->update(['queued_at' => now()]);
                ProcessProjectSource::dispatch($source->id);
            } catch (Throwable $exception) {
                report($exception);
                $source->extraction()->update([
                    'status' => 'failed',
                    'queued_at' => null,
                    'error_code' => 'queue_unavailable',
                    'error_message' => 'Processing could not be queued. Please try again.',
                    'processed_at' => now(),
                ]);
                $source->update(['processing_status' => 'failed']);
            }
        }

        return ProjectSourceResource::collection(
            collect($sources)->map(fn (ProjectSource $source) => $source->load('extraction')),
        )
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(WebsiteProject $project, ProjectSource $source): JsonResponse
    {
        Gate::authorize('delete', $project);

        $source = $project->sources()->findOrFail($source->getKey());

        if (! Storage::disk(self::DISK)->delete($source->stored_path)) {
            throw new RuntimeException('Unable to remove project source file.');
        }

        $source->delete();

        return response()->json([
            'success' => true,
            'message' => 'Source deleted.',
        ]);
    }
}
