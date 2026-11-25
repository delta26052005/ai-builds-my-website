<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\RegenerateWebsiteSectionRequest;
use App\Http\Requests\Api\StartWebsiteGenerationRequest;
use App\Http\Requests\Api\UpdateGeneratedWebsiteRequest;
use App\Http\Resources\Api\GeneratedWebsiteResource;
use App\Http\Resources\Api\WebsiteRevisionResource;
use App\Jobs\GenerateWebsite;
use App\Models\GeneratedWebsite;
use App\Models\WebsiteProject;
use App\Services\WebsiteContentValidator;
use App\Services\WebsiteGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class GeneratedWebsiteController
{
    public function show(WebsiteProject $project): JsonResponse
    {
        Gate::authorize('view', $project);
        $website = $project->generatedWebsite;

        return response()->json([
            'success' => true,
            'data' => $website === null
                ? null
                : (new GeneratedWebsiteResource($website))->resolve(),
        ]);
    }

    public function publish(
        WebsiteProject $project,
        WebsiteContentValidator $validator,
    ): GeneratedWebsiteResource {
        Gate::authorize('update', $project);

        $website = DB::transaction(function () use ($project, $validator): GeneratedWebsite {
            $lockedProject = WebsiteProject::query()
                ->lockForUpdate()
                ->findOrFail($project->id);
            $website = $lockedProject->generatedWebsite()
                ->lockForUpdate()
                ->firstOrFail();

            if ($website->content_json === null || $website->current_version < 1) {
                abort(409, 'Generate a website before publishing it.');
            }

            $content = $validator->validate($website->content_json);
            $this->validateGallerySources($lockedProject, $content);

            if (! $website->is_published || blank($website->public_slug)) {
                $website->update([
                    'is_published' => true,
                    'public_slug' => $website->public_slug
                        ?: $this->uniquePublicSlug($website->title),
                    'published_at' => now(),
                ]);
            }

            return $website->refresh();
        });

        return new GeneratedWebsiteResource($website);
    }

    public function unpublish(WebsiteProject $project): GeneratedWebsiteResource
    {
        Gate::authorize('update', $project);

        $website = DB::transaction(function () use ($project): GeneratedWebsite {
            $lockedProject = WebsiteProject::query()
                ->lockForUpdate()
                ->findOrFail($project->id);
            $website = $lockedProject->generatedWebsite()
                ->lockForUpdate()
                ->firstOrFail();

            $website->update([
                'is_published' => false,
                'published_at' => null,
            ]);

            return $website->refresh();
        });

        return new GeneratedWebsiteResource($website);
    }

    public function generate(
        StartWebsiteGenerationRequest $request,
        WebsiteProject $project,
        WebsiteGenerationService $generator,
    ): JsonResponse {
        Gate::authorize('update', $project);
        $facts = $generator->confirmedFacts($project);

        if ($facts === []) {
            throw ValidationException::withMessages([
                'confirmed_facts' => ['Confirm business information before generating a website.'],
            ]);
        }

        if (! collect($facts)->contains(fn (array $fact): bool => $fact['fact_key'] === 'business_name')) {
            throw ValidationException::withMessages([
                'confirmed_facts' => ['Confirm the business name before generating a website.'],
            ]);
        }

        $templateKey = $request->validated('template_key', 'modern_business');
        $token = (string) Str::uuid();
        [$website, $shouldDispatch] = DB::transaction(function () use ($project, $token): array {
            $lockedProject = WebsiteProject::query()
                ->lockForUpdate()
                ->findOrFail($project->id);
            $website = $lockedProject->generatedWebsite()->first();

            if ($website !== null
                && in_array($website->status, ['pending', 'processing'], true)
                && $website->updated_at?->gt(now()->subMinutes(10))) {
                return [$website, false];
            }

            if ($website === null) {
                $website = $lockedProject->generatedWebsite()->create([
                    'title' => 'Business website',
                    'template_key' => 'modern_business',
                    'status' => 'pending',
                    'current_version' => 0,
                    'generation_token' => $token,
                    'queued_at' => now(),
                ]);
            } else {
                $website->update([
                    'status' => 'pending',
                    'generation_token' => $token,
                    'queued_at' => now(),
                    'error_code' => null,
                    'error_message' => null,
                ]);
            }

            return [$website->refresh(), true];
        });

        if ($shouldDispatch) {
            try {
                GenerateWebsite::dispatch(
                    $website->id,
                    $token,
                    $website->current_version,
                    'generate',
                    $templateKey,
                );
            } catch (Throwable $exception) {
                report($exception);
                GeneratedWebsite::query()
                    ->whereKey($website->id)
                    ->where('generation_token', $token)
                    ->update([
                        'status' => 'failed',
                        'generation_token' => null,
                        'queued_at' => null,
                        'error_code' => 'queue_unavailable',
                        'error_message' => 'Website generation could not be queued. Please try again.',
                    ]);

                return $this->generationResponse($website->refresh(), false, 503);
            }
        }

        return $this->generationResponse($website, true, 202);
    }

    public function update(
        UpdateGeneratedWebsiteRequest $request,
        WebsiteProject $project,
        WebsiteContentValidator $validator,
    ): GeneratedWebsiteResource {
        Gate::authorize('update', $project);
        $validated = $request->validated();
        $content = $validator->validate($validated['content_json']);

        $website = DB::transaction(function () use ($project, $validated, $content): GeneratedWebsite {
            $lockedProject = WebsiteProject::query()
                ->lockForUpdate()
                ->findOrFail($project->id);
            $website = $lockedProject->generatedWebsite()
                ->lockForUpdate()
                ->firstOrFail();

            if ($website->content_json === null || $website->current_version < 1) {
                abort(409, 'Generate a website before saving edits.');
            }

            if (isset($validated['expected_version'])
                && (int) $validated['expected_version'] !== $website->current_version) {
                abort(409, 'This website changed since it was loaded. Reload it before saving.');
            }

            $this->validateGallerySources($lockedProject, $content);
            $content['content_provenance'] = $this->updatedProvenance(
                $website->content_json ?? [],
                $content,
            );
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
                'change_summary' => $validated['change_summary'] ?? 'Owner edited website content.',
            ]);

            return $website->refresh();
        });

        return new GeneratedWebsiteResource($website);
    }

    public function regenerateSection(
        RegenerateWebsiteSectionRequest $request,
        WebsiteProject $project,
        WebsiteGenerationService $generator,
    ): JsonResponse {
        Gate::authorize('update', $project);
        $facts = $generator->confirmedFacts($project);

        if ($facts === []) {
            throw ValidationException::withMessages([
                'confirmed_facts' => ['Confirm business information before regenerating website content.'],
            ]);
        }

        if (! collect($facts)->contains(fn (array $fact): bool => $fact['fact_key'] === 'business_name')) {
            throw ValidationException::withMessages([
                'confirmed_facts' => ['Confirm the business name before regenerating website content.'],
            ]);
        }

        [$website, $token, $shouldDispatch] = DB::transaction(function () use ($project): array {
            $lockedProject = WebsiteProject::query()
                ->lockForUpdate()
                ->findOrFail($project->id);
            $website = $lockedProject->generatedWebsite()
                ->lockForUpdate()
                ->firstOrFail();

            if ($website->content_json === null || $website->current_version < 1) {
                abort(409, 'Generate a website before regenerating a section.');
            }

            if (in_array($website->status, ['pending', 'processing'], true)
                && $website->updated_at?->gt(now()->subMinutes(10))) {
                return [$website, $website->generation_token, false];
            }

            $token = (string) Str::uuid();
            $website->update([
                'status' => 'pending',
                'generation_token' => $token,
                'queued_at' => now(),
                'error_code' => null,
                'error_message' => null,
            ]);

            return [$website->refresh(), $token, true];
        });

        if (! $shouldDispatch) {
            return $this->generationResponse($website, true, 202);
        }

        try {
            GenerateWebsite::dispatch(
                $website->id,
                $token,
                $website->current_version,
                'regenerate_section',
                $website->template_key,
                $request->validated('section'),
            );
        } catch (Throwable $exception) {
            report($exception);
            GeneratedWebsite::query()
                ->whereKey($website->id)
                ->where('generation_token', $token)
                ->update([
                    'status' => 'failed',
                    'generation_token' => null,
                    'queued_at' => null,
                    'error_code' => 'queue_unavailable',
                    'error_message' => 'Section regeneration could not be queued. Please try again.',
                ]);

            return $this->generationResponse($website->refresh(), false, 503);
        }

        return $this->generationResponse($website, true, 202);
    }

    public function revisions(WebsiteProject $project): JsonResponse
    {
        Gate::authorize('view', $project);
        $website = $project->generatedWebsite;

        return response()->json([
            'success' => true,
            'data' => $website === null
                ? []
                : WebsiteRevisionResource::collection(
                    $website->revisions()->latest('version_number')->get(),
                )->resolve(),
        ]);
    }

    public function revision(WebsiteProject $project, int $revision): WebsiteRevisionResource
    {
        Gate::authorize('view', $project);
        $website = $project->generatedWebsite()->firstOrFail();
        $revision = $website->revisions()->findOrFail($revision);

        return new WebsiteRevisionResource($revision);
    }

    public function destroyRevision(WebsiteProject $project, int $revision): JsonResponse
    {
        Gate::authorize('update', $project);

        DB::transaction(function () use ($project, $revision): void {
            $website = $project->generatedWebsite()
                ->lockForUpdate()
                ->firstOrFail();
            $selectedRevision = $website->revisions()
                ->whereKey($revision)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $selectedRevision->version_number === (int) $website->current_version) {
                abort(409, 'The active website version cannot be deleted.');
            }

            $selectedRevision->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Website revision deleted.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function validateGallerySources(WebsiteProject $project, array $content): void
    {
        $sourceIds = collect($content['gallery'])
            ->filter(fn (array $image): bool => ! empty($image['source_id']))
            ->pluck('source_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        foreach ($content['gallery'] as $index => $image) {
            if (! empty($image['source_id']) && ! empty($image['image_url'])) {
                throw ValidationException::withMessages([
                    "content_json.gallery.{$index}" => ['Use either an uploaded image or an image URL, not both.'],
                ]);
            }
        }

        if ($sourceIds->isEmpty()) {
            return;
        }

        $ownedImageCount = $project->sources()
            ->whereIn('id', $sourceIds)
            ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp'])
            ->count();

        if ($ownedImageCount !== $sourceIds->count()) {
            throw ValidationException::withMessages([
                'content_json.gallery' => ['Gallery images must belong to this project.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $content
     * @return array<string, string>
     */
    private function updatedProvenance(array $previous, array $content): array
    {
        $paths = [
            'business_name',
            'tagline',
            'hero.heading',
            'hero.description',
            'hero.button_text',
            'about.heading',
            'about.description',
            'contact.address',
            'contact.phone',
            'contact.email',
            'footer.copyright_text',
        ];

        foreach ($content['navigation'] as $index => $item) {
            $paths[] = "navigation.{$index}.label";
        }

        foreach ($content['services_or_products'] as $index => $item) {
            $paths[] = "services_or_products.{$index}.name";
            $paths[] = "services_or_products.{$index}.description";
        }

        foreach ($content['highlights'] as $index => $item) {
            $paths[] = "highlights.{$index}";
        }

        foreach ($content['gallery'] as $index => $item) {
            $paths[] = "gallery.{$index}.alt";
        }

        foreach ($content['contact']['social_links'] as $index => $item) {
            $paths[] = "contact.social_links.{$index}.label";
            $paths[] = "contact.social_links.{$index}.url";
        }

        $previousProvenance = $previous['content_provenance'] ?? [];
        $provenance = [];

        foreach ($paths as $path) {
            $value = data_get($content, $path);

            if (! is_string($value)) {
                continue;
            }

            $sameValue = data_get($previous, $path) === $value;
            $origin = $previousProvenance[$path] ?? 'legacy_unclassified';
            $provenance[$path] = $sameValue
                ? (in_array($origin, ['ai_generated_unverified', 'owner_authored'], true)
                    ? $origin
                    : 'legacy_unclassified')
                : 'owner_authored';
        }

        return $provenance;
    }

    private function generationResponse(
        GeneratedWebsite $website,
        bool $success,
        int $status,
    ): JsonResponse {
        return response()->json([
            'success' => $success,
            'message' => $success
                ? 'Website generation has been queued.'
                : 'Website generation could not be queued.',
            'data' => (new GeneratedWebsiteResource($website))->resolve(),
        ], $status);
    }

    private function uniquePublicSlug(string $title): string
    {
        $base = Str::slug(mb_substr($title, 0, 140));
        $base = $base !== '' ? $base : 'business-website';

        do {
            $slug = $base.'-'.Str::lower(Str::random(10));
        } while (GeneratedWebsite::query()->where('public_slug', $slug)->exists());

        return $slug;
    }
}
