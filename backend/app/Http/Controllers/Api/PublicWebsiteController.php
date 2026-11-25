<?php

namespace App\Http\Controllers\Api;

use App\Models\GeneratedWebsite;
use App\Services\WebsiteContentValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class PublicWebsiteController
{
    public function show(string $slug, WebsiteContentValidator $validator): JsonResponse
    {
        $website = GeneratedWebsite::query()
            ->where('public_slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        if ($website->content_json === null || $website->current_version < 1) {
            abort(404);
        }

        $content = $validator->validate($website->content_json);
        unset($content['content_provenance']);

        return response()->json([
            'success' => true,
            'data' => [
                'slug' => $website->public_slug,
                'content_json' => $content,
                'published_at' => $website->published_at,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function image(string $slug, int $source)
    {
        $website = GeneratedWebsite::query()
            ->with('websiteProject')
            ->where('public_slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        $isInPublishedGallery = collect(data_get($website->content_json, 'gallery', []))
            ->contains(fn ($image): bool => is_array($image)
                && (int) ($image['source_id'] ?? 0) === $source);

        abort_unless($isInPublishedGallery, 404);

        $project = $website->websiteProject;
        abort_if($project === null, 404);

        $projectSource = $project->sources()
            ->whereKey($source)
            ->whereIn('mime_type', ['image/jpeg', 'image/png', 'image/webp'])
            ->firstOrFail();

        $disk = Storage::disk('project_sources');
        abort_unless($disk->exists($projectSource->stored_path), 404);

        return $disk->response(
            $projectSource->stored_path,
            'business-image',
            [
                'Cache-Control' => 'no-store',
                'Content-Type' => $projectSource->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ],
            'inline',
        );
    }
}
