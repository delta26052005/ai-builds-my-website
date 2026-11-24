<?php

namespace App\Jobs;

use App\Models\WebsiteProject;
use App\Services\ProviderException;
use App\Services\TavilyResearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ResearchBusinessProject implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 45;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60];

    public function __construct(public readonly int $projectId) {}

    public function handle(TavilyResearchService $researchService): void
    {
        $project = $this->beginRun();

        if ($project === null) {
            return;
        }

        try {
            $result = $researchService->research($project);
        } catch (ProviderException $exception) {
            $this->finishRun('failed', $exception->errorCode, $exception->getMessage());

            return;
        }

        $this->finishRun('completed', null, null, $result['source_count']);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            Log::warning('Business research job exhausted retries.', [
                'project_id' => $this->projectId,
                'exception_class' => $exception::class,
            ]);
        }

        $this->finishRun(
            'failed',
            'research_failed',
            'Business research failed unexpectedly. Please try again.',
        );
    }

    private function beginRun(): ?WebsiteProject
    {
        return DB::transaction(function (): ?WebsiteProject {
            $project = WebsiteProject::query()
                ->lockForUpdate()
                ->find($this->projectId);

            if ($project === null) {
                return null;
            }

            $run = $project->businessResearchRun()->firstOrCreate(
                ['website_project_id' => $project->id],
                ['status' => 'pending'],
            );

            if (in_array($run->status, ['completed', 'partial'], true)) {
                return null;
            }

            if ($run->status === 'processing' && $this->attempts() <= 1) {
                return null;
            }

            $run->update([
                'status' => 'processing',
                'queued_at' => null,
                'error_code' => null,
                'error_message' => null,
                'processed_at' => null,
            ]);

            return $project;
        });
    }

    private function finishRun(
        string $status,
        ?string $errorCode,
        ?string $errorMessage,
        ?int $sourceCount = null,
    ): void {
        DB::transaction(function () use ($status, $errorCode, $errorMessage, $sourceCount): void {
            $project = WebsiteProject::query()->find($this->projectId);

            if ($project === null) {
                return;
            }

            $run = $project->businessResearchRun;

            if ($run === null || $run->status !== 'processing') {
                return;
            }

            $run->update([
                'status' => $status,
                'source_count' => $sourceCount ?? $run->source_count,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'processed_at' => now(),
            ]);
        });
    }
}
