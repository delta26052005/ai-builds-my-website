<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\StartBusinessIntelligenceRequest;
use App\Http\Resources\Api\BusinessFactResource;
use App\Http\Resources\Api\BusinessFactRunResource;
use App\Http\Resources\Api\BusinessResearchRunResource;
use App\Http\Resources\Api\BusinessResearchSourceResource;
use App\Jobs\ExtractBusinessFacts;
use App\Jobs\ResearchBusinessProject;
use App\Models\BusinessFact;
use App\Models\WebsiteProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

class BusinessIntelligenceController
{
    public function startResearch(
        StartBusinessIntelligenceRequest $request,
        WebsiteProject $project,
    ): JsonResponse {
        Gate::authorize('view', $project);

        [$run, $shouldDispatch] = DB::transaction(function () use ($project, $request): array {
            $lockedProject = WebsiteProject::query()
                ->lockForUpdate()
                ->findOrFail($project->id);
            $run = $lockedProject->businessResearchRun()->firstOrCreate(
                ['website_project_id' => $lockedProject->id],
                ['status' => 'pending'],
            );

            if ($run->status === 'failed'
                || (in_array($run->status, ['completed', 'partial'], true) && $request->boolean('refresh'))) {
                $run->update([
                    'status' => 'pending',
                    'queued_at' => null,
                    'error_code' => null,
                    'error_message' => null,
                    'processed_at' => null,
                ]);
            }

            $shouldDispatch = $run->status === 'pending'
                && ($run->queued_at === null || $run->queued_at->lt(now()->subMinutes(10)));

            if ($shouldDispatch) {
                $run->update(['queued_at' => now()]);
            }

            return [$run->refresh(), $shouldDispatch];
        });

        if ($shouldDispatch) {
            try {
                ResearchBusinessProject::dispatch($project->id);
            } catch (Throwable $exception) {
                report($exception);
                $run->update([
                    'status' => 'failed',
                    'queued_at' => null,
                    'error_code' => 'queue_unavailable',
                    'error_message' => 'Business research could not be queued. Please try again.',
                    'processed_at' => now(),
                ]);

                return $this->runResponse(
                    new BusinessResearchRunResource($run->refresh()),
                    'Business research could not be queued. Please try again.',
                    false,
                    503,
                );
            }
        }

        return $this->runResponse(
            new BusinessResearchRunResource($run->refresh()),
            $shouldDispatch ? 'Business research has been queued.' : 'The current research status is returned.',
            true,
            $run->status === 'pending' ? 202 : 200,
        );
    }

    public function researchStatus(WebsiteProject $project): JsonResponse
    {
        Gate::authorize('view', $project);
        $run = $project->businessResearchRun;

        return response()->json([
            'success' => true,
            'data' => $run === null
                ? [
                    'status' => 'not_started',
                    'source_count' => 0,
                    'error_code' => null,
                    'error_message' => null,
                    'processed_at' => null,
                    'updated_at' => null,
                ]
                : (new BusinessResearchRunResource($run))->resolve(),
        ]);
    }

    public function researchSources(WebsiteProject $project)
    {
        Gate::authorize('view', $project);

        return BusinessResearchSourceResource::collection(
            $project->businessResearchSources()->latest()->get(),
        );
    }

    public function startFactExtraction(
        StartBusinessIntelligenceRequest $request,
        WebsiteProject $project,
    ): JsonResponse {
        Gate::authorize('view', $project);

        [$run, $shouldDispatch] = DB::transaction(function () use ($project, $request): array {
            $lockedProject = WebsiteProject::query()
                ->lockForUpdate()
                ->findOrFail($project->id);
            $run = $lockedProject->businessFactRun()->firstOrCreate(
                ['website_project_id' => $lockedProject->id],
                ['status' => 'pending'],
            );

            if ($run->status === 'failed'
                || ($run->status === 'completed' && $request->boolean('refresh'))) {
                $run->update([
                    'status' => 'pending',
                    'queued_at' => null,
                    'fact_count' => 0,
                    'skipped_count' => 0,
                    'error_code' => null,
                    'error_message' => null,
                    'processed_at' => null,
                ]);
            }

            $shouldDispatch = $run->status === 'pending'
                && ($run->queued_at === null || $run->queued_at->lt(now()->subMinutes(10)));

            if ($shouldDispatch) {
                $run->update(['queued_at' => now()]);
            }

            return [$run->refresh(), $shouldDispatch];
        });

        if ($shouldDispatch) {
            try {
                ExtractBusinessFacts::dispatch($project->id);
            } catch (Throwable $exception) {
                report($exception);
                $run->update([
                    'status' => 'failed',
                    'queued_at' => null,
                    'error_code' => 'queue_unavailable',
                    'error_message' => 'Fact extraction could not be queued. Please try again.',
                    'processed_at' => now(),
                ]);

                return $this->runResponse(
                    new BusinessFactRunResource($run->refresh()),
                    'Fact extraction could not be queued. Please try again.',
                    false,
                    503,
                );
            }
        }

        return $this->runResponse(
            new BusinessFactRunResource($run->refresh()),
            $shouldDispatch ? 'Fact extraction has been queued.' : 'The current extraction status is returned.',
            true,
            $run->status === 'pending' ? 202 : 200,
        );
    }

    public function factStatus(WebsiteProject $project): JsonResponse
    {
        Gate::authorize('view', $project);
        $run = $project->businessFactRun;

        return response()->json([
            'success' => true,
            'data' => $run === null
                ? [
                    'status' => 'not_started',
                    'fact_count' => 0,
                    'skipped_count' => 0,
                    'error_code' => null,
                    'error_message' => null,
                    'processed_at' => null,
                    'updated_at' => null,
                ]
                : (new BusinessFactRunResource($run))->resolve(),
        ]);
    }

    public function facts(WebsiteProject $project)
    {
        Gate::authorize('view', $project);

        $facts = $project->businessFacts()
            ->with(['candidates.evidence'])
            ->orderBy('category')
            ->orderBy('fact_key')
            ->get();

        return BusinessFactResource::collection($facts);
    }

    public function showFact(WebsiteProject $project, BusinessFact $fact): BusinessFactResource
    {
        Gate::authorize('view', $project);

        $fact = $project->businessFacts()
            ->with(['candidates.evidence'])
            ->findOrFail($fact->id);

        return new BusinessFactResource($fact);
    }

    private function runResponse(
        BusinessResearchRunResource|BusinessFactRunResource $resource,
        string $message,
        bool $success,
        int $status,
    ): JsonResponse {
        return response()->json([
            'success' => $success,
            'message' => $message,
            'data' => $resource->resolve(),
        ], $status);
    }
}
