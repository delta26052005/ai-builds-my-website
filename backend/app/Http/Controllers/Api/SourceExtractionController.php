<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\Api\SourceExtractionResource;
use App\Jobs\ProcessProjectSource;
use App\Models\ProjectSource;
use App\Models\WebsiteProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

class SourceExtractionController
{
    public function index(WebsiteProject $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $sources = $project->sources()
            ->with('extraction')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $sources->map(fn (ProjectSource $source): array => [
                'source_id' => $source->id,
                'original_filename' => $source->original_filename,
                'status' => $source->extraction?->status
                    ?? ($source->processing_status === 'pending' ? 'uploaded' : $source->processing_status),
                'extraction_method' => $source->extraction?->extraction_method,
                'page_count' => $source->extraction?->page_count,
                'error_code' => $source->extraction?->error_code,
                'error_message' => $source->extraction?->error_message,
                'processed_at' => $source->extraction?->processed_at,
            ]),
        ]);
    }

    public function process(WebsiteProject $project, ProjectSource $source): JsonResponse
    {
        $source = $this->ownedSource($project, $source);

        [$extraction, $shouldDispatch] = DB::transaction(function () use ($source): array {
            $lockedSource = ProjectSource::query()
                ->lockForUpdate()
                ->findOrFail($source->id);
            $extraction = $lockedSource->extraction()->firstOrCreate(
                ['project_source_id' => $lockedSource->id],
                ['status' => 'pending'],
            );

            if ($extraction->status === 'failed') {
                $extraction->update([
                    'status' => 'pending',
                    'queued_at' => null,
                    'extracted_text' => null,
                    'page_count' => null,
                    'metadata' => null,
                    'error_code' => null,
                    'error_message' => null,
                    'processed_at' => null,
                ]);
                $lockedSource->update(['processing_status' => 'pending']);
            }

            $shouldDispatch = $extraction->status === 'pending'
                && ($extraction->queued_at === null || $extraction->queued_at->lt(now()->subMinutes(10)));

            if ($shouldDispatch) {
                $extraction->update(['queued_at' => now()]);
            }

            return [$extraction->refresh(), $shouldDispatch];
        });

        if ($shouldDispatch) {
            try {
                ProcessProjectSource::dispatch($source->id);
            } catch (Throwable $exception) {
                report($exception);

                $extraction->update([
                    'status' => 'failed',
                    'queued_at' => null,
                    'error_code' => 'queue_unavailable',
                    'error_message' => 'Processing could not be queued. Please try again.',
                    'processed_at' => now(),
                ]);
                $source->update(['processing_status' => 'failed']);

                return response()->json([
                    'success' => false,
                    'message' => 'Processing could not be queued. Please try again.',
                    'data' => new SourceExtractionResource($extraction->refresh()),
                ], 503);
            }
        }

        return response()->json([
            'success' => true,
            'message' => $extraction->status === 'pending'
                ? 'Processing has been queued.'
                : 'The current processing status is returned.',
            'data' => new SourceExtractionResource($extraction->refresh()),
        ], $extraction->status === 'pending' ? 202 : 200);
    }

    public function show(WebsiteProject $project, ProjectSource $source): JsonResponse
    {
        $source = $this->ownedSource($project, $source);
        $extraction = $source->extraction;

        return response()->json([
            'success' => true,
            'data' => $extraction === null
                ? [
                    'id' => null,
                    'status' => $source->processing_status === 'pending'
                        ? 'uploaded'
                        : ($source->processing_status ?: 'uploaded'),
                    'extraction_method' => null,
                    'page_count' => null,
                    'metadata' => null,
                    'error_code' => null,
                    'error_message' => null,
                    'processed_at' => null,
                    'updated_at' => null,
                ]
                : new SourceExtractionResource($extraction),
        ]);
    }

    public function content(WebsiteProject $project, ProjectSource $source): JsonResponse
    {
        $source = $this->ownedSource($project, $source);
        $extraction = $source->extraction;

        if ($extraction === null || $extraction->status !== 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Extracted text is not available for this source.',
            ], 409);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'source_id' => $source->id,
                'status' => $extraction->status,
                'extracted_text' => $extraction->extracted_text,
                'updated_at' => $extraction->updated_at,
            ],
        ]);
    }

    private function ownedSource(WebsiteProject $project, ProjectSource $source): ProjectSource
    {
        Gate::authorize('view', $project);

        return $project->sources()->findOrFail($source->id);
    }
}
