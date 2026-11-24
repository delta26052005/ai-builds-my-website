<?php

namespace App\Jobs;

use App\Models\BusinessResearchSource;
use App\Models\ProjectSource;
use App\Models\WebsiteProject;
use App\Services\BusinessFactIngestionService;
use App\Services\GeminiFactExtractionService;
use App\Services\ProviderException;
use App\Services\SensitiveTextRedactor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExtractBusinessFacts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const MAX_CONTEXT_CHARACTERS = 60000;

    private const MAX_SOURCE_CHARACTERS = 7000;

    public int $tries = 2;

    public int $timeout = 60;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60];

    public function __construct(public readonly int $projectId) {}

    public function handle(
        GeminiFactExtractionService $extractor,
        BusinessFactIngestionService $ingestion,
        SensitiveTextRedactor $redactor,
    ): void {
        $project = $this->beginRun();

        if ($project === null) {
            return;
        }

        [$sources, $sourceMap] = $this->sourceContext($project, $redactor);

        try {
            $facts = $extractor->extract($project, $sources, $redactor);
        } catch (ProviderException $exception) {
            Log::warning('Gemini fact extraction failed.', [
                'project_id' => $this->projectId,
                'error_code' => $exception->errorCode,
            ]);

            $this->finishRun(
                'failed',
                $exception->errorCode,
                $exception->getMessage(),
            );

            return;
        }

        $result = $ingestion->store($project, $facts, $sourceMap);
        $warning = $result['skipped_count'] > 0
            ? 'Some candidates were discarded because their source evidence could not be verified.'
            : null;

        $this->finishRun(
            'completed',
            $warning === null ? null : 'unverified_candidates_skipped',
            $warning,
            $result['fact_count'],
            $result['skipped_count'],
        );
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            Log::warning('Business fact extraction job exhausted retries.', [
                'project_id' => $this->projectId,
                'exception_class' => $exception::class,
            ]);
        }

        $this->finishRun(
            'failed',
            'fact_extraction_failed',
            'Business fact extraction failed unexpectedly. Please try again.',
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

            $run = $project->businessFactRun()->firstOrCreate(
                ['website_project_id' => $project->id],
                ['status' => 'pending'],
            );

            if ($run->status === 'completed') {
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

    /**
     * @return array{list<array<string, string|null>>, array<string, array<string, mixed>>}
     */
    private function sourceContext(
        WebsiteProject $project,
        SensitiveTextRedactor $redactor,
    ): array {
        $context = [];
        $sourceMap = [];
        $totalCharacters = 0;

        $projectFields = [
            'business_name' => ['business', $project->business_name],
            'business_category' => ['business', $project->business_category],
            'business_description' => ['business', $project->business_description],
            'city' => ['location', $project->city],
            'state' => ['location', $project->state],
            'country' => ['location', $project->country],
            'business_address' => ['location', $project->business_address],
            'business_email' => ['contact', $project->business_email],
            'business_phone' => ['contact', $project->business_phone],
        ];

        foreach ($projectFields as $field => [$category, $value]) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $this->appendSource(
                $context,
                $sourceMap,
                $totalCharacters,
                'project.'.$field,
                'project',
                $field,
                $redactor->redact($field.': '.$value),
            );
        }

        $projectSources = ProjectSource::query()
            ->where('website_project_id', $project->id)
            ->whereHas('extraction', fn ($query) => $query->where('status', 'completed'))
            ->with('extraction')
            ->latest()
            ->limit(10)
            ->get();

        foreach ($projectSources as $source) {
            $content = $source->extraction?->extracted_text;

            if (is_string($content) && trim($content) !== '') {
                $this->appendSource(
                    $context,
                    $sourceMap,
                    $totalCharacters,
                    'pdf:'.$source->id,
                    'uploaded_pdf',
                    $source->original_filename,
                    $redactor->redact($content),
                    projectSourceId: $source->id,
                );
            }
        }

        $researchSources = BusinessResearchSource::query()
            ->where('website_project_id', $project->id)
            ->where('access_status', 'accessible')
            ->latest('fetched_at')
            ->limit(10)
            ->get();

        foreach ($researchSources as $source) {
            $content = $source->content ?: $source->snippet;

            if (is_string($content) && trim($content) !== '') {
                $this->appendSource(
                    $context,
                    $sourceMap,
                    $totalCharacters,
                    'research:'.$source->id,
                    in_array($source->source_type, ['social', 'website', 'google_business'], true)
                        ? $source->source_type
                        : 'tavily',
                    $source->title ?: $source->source_url,
                    $redactor->redact($content),
                    sourceUrl: $source->source_url,
                    researchSourceId: $source->id,
                );
            }
        }

        return [$context, $sourceMap];
    }

    /**
     * @param  list<array<string, string|null>>  $context
     * @param  array<string, array<string, mixed>>  $sourceMap
     */
    private function appendSource(
        array &$context,
        array &$sourceMap,
        int &$totalCharacters,
        string $sourceRef,
        string $sourceType,
        string $sourceReference,
        string $content,
        ?string $sourceUrl = null,
        ?int $researchSourceId = null,
        ?int $projectSourceId = null,
    ): void {
        $remaining = self::MAX_CONTEXT_CHARACTERS - $totalCharacters;

        if ($remaining <= 0) {
            return;
        }

        $content = mb_substr($content, 0, min(self::MAX_SOURCE_CHARACTERS, $remaining));

        if ($content === '') {
            return;
        }

        $context[] = [
            'source_ref' => $sourceRef,
            'source_type' => $sourceType,
            'source_url' => $sourceUrl,
            'content' => $content,
        ];

        $sourceMap[$sourceRef] = [
            'source_type' => $sourceType,
            'source_reference' => mb_substr($sourceReference, 0, 255),
            'source_url' => $sourceUrl,
            'research_source_id' => $researchSourceId,
            'project_source_id' => $projectSourceId,
            'content' => $content,
        ];

        $totalCharacters += mb_strlen($content);
    }

    private function finishRun(
        string $status,
        ?string $errorCode,
        ?string $errorMessage,
        ?int $factCount = null,
        ?int $skippedCount = null,
    ): void {
        DB::transaction(function () use ($status, $errorCode, $errorMessage, $factCount, $skippedCount): void {
            $project = WebsiteProject::query()->find($this->projectId);

            if ($project === null) {
                return;
            }

            $run = $project->businessFactRun;

            if ($run === null || $run->status !== 'processing') {
                return;
            }

            $run->update([
                'status' => $status,
                'fact_count' => $factCount ?? $run->fact_count,
                'skipped_count' => $skippedCount ?? $run->skipped_count,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'processed_at' => now(),
            ]);
        });
    }
}
