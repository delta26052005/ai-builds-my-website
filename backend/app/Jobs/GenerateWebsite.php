<?php

namespace App\Jobs;

use App\Models\GeneratedWebsite;
use App\Models\WebsiteProject;
use App\Services\ProviderException;
use App\Services\WebsiteGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateWebsite implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    /**
     * @var array<int, int>
     */
    public array $backoff = [20];

    public function __construct(
        public readonly int $websiteId,
        public readonly string $generationToken,
        public readonly int $baseVersion,
        public readonly string $operation = 'generate',
        public readonly ?string $templateKey = null,
        public readonly ?string $section = null,
    ) {}

    public function handle(WebsiteGenerationService $generator): void
    {
        $context = $this->beginGeneration();

        if ($context === null) {
            return;
        }

        [$website, $project] = $context;
        $facts = $generator->confirmedFacts($project);

        if ($facts === []) {
            $this->failGeneration(
                'confirmed_facts_required',
                'Confirm business information before generating a website.',
            );

            return;
        }

        try {
            $content = match ($this->operation) {
                'generate' => $generator->generate(
                    $project,
                    $facts,
                    $this->templateKey ?? 'modern_business',
                ),
                'regenerate_section' => $website->content_json === null || $this->section === null
                    ? throw new ProviderException(
                        'website_content_missing',
                        'Generate a website before regenerating a section.',
                    )
                    : $generator->regenerateSection(
                        $project,
                        $facts,
                        $website->content_json,
                        $this->section,
                    ),
                default => throw new ProviderException(
                    'invalid_generation_operation',
                    'The requested website operation is not supported.',
                ),
            };
        } catch (ProviderException $exception) {
            if (in_array($exception->errorCode, [
                'provider_timeout',
                'provider_rate_limited',
                'provider_unavailable',
            ], true) && $this->attempts() < $this->tries) {
                throw $exception;
            }

            Log::warning('Website generation failed.', [
                'website_id' => $this->websiteId,
                'error_code' => $exception->errorCode,
            ]);
            $this->failGeneration($exception->errorCode, $exception->getMessage());

            return;
        }

        $this->persistGeneration($content);
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception !== null) {
            Log::warning('Website generation job exhausted retries.', [
                'website_id' => $this->websiteId,
                'exception_class' => $exception::class,
            ]);
        }

        $this->failGeneration(
            'website_generation_failed',
            'Website generation failed unexpectedly. Please try again.',
        );
    }

    /**
     * @return array{GeneratedWebsite, WebsiteProject}|null
     */
    private function beginGeneration(): ?array
    {
        return DB::transaction(function (): ?array {
            $website = GeneratedWebsite::query()
                ->with('websiteProject')
                ->lockForUpdate()
                ->find($this->websiteId);

            if ($website === null
                || $website->generation_token !== $this->generationToken
                || ! in_array($website->status, ['pending', 'processing'], true)
                || $website->websiteProject === null) {
                return null;
            }

            $website->update([
                'status' => 'processing',
                'queued_at' => null,
                'error_code' => null,
                'error_message' => null,
            ]);

            return [$website->refresh(), $website->websiteProject];
        });
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function persistGeneration(array $content): void
    {
        DB::transaction(function () use ($content): void {
            $website = GeneratedWebsite::query()
                ->lockForUpdate()
                ->find($this->websiteId);

            if ($website === null || $website->generation_token !== $this->generationToken) {
                return;
            }

            if ($website->current_version !== $this->baseVersion) {
                $website->update([
                    'status' => 'failed',
                    'generation_token' => null,
                    'error_code' => 'website_version_changed',
                    'error_message' => 'The website changed while generation was in progress. Try again.',
                ]);

                return;
            }

            $version = $website->current_version + 1;
            $website->update([
                'title' => $content['business_name'],
                'template_key' => $content['template_key'],
                'content_json' => $content,
                'status' => 'completed',
                'current_version' => $version,
                'generation_token' => null,
                'queued_at' => null,
                'error_code' => null,
                'error_message' => null,
                'generated_at' => now(),
            ]);
            $website->revisions()->create([
                'version_number' => $version,
                'content_json' => $content,
                'change_summary' => $this->operation === 'regenerate_section'
                    ? 'Regenerated the '.str_replace('_', ' ', (string) $this->section).' section.'
                    : ($version === 1 ? 'Initial website generation.' : 'Regenerated website content.'),
            ]);
        });
    }

    private function failGeneration(string $errorCode, string $errorMessage): void
    {
        GeneratedWebsite::query()
            ->whereKey($this->websiteId)
            ->where('generation_token', $this->generationToken)
            ->update([
                'status' => 'failed',
                'generation_token' => null,
                'queued_at' => null,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'updated_at' => now(),
            ]);
    }
}
