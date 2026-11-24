<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\SaveWebsiteProjectRequest;
use App\Http\Resources\Api\WebsiteProjectResource;
use App\Models\WebsiteProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class WebsiteProjectController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', WebsiteProject::class);

        $projects = $request->user()
            ->websiteProjects()
            ->latest()
            ->paginate(12);

        return WebsiteProjectResource::collection($projects);
    }

    public function store(SaveWebsiteProjectRequest $request): WebsiteProjectResource
    {
        Gate::authorize('create', WebsiteProject::class);

        $validated = $request->validated();
        $name = Str::slug($validated['business_name']) ?: 'website';

        $project = $request->user()->websiteProjects()->create([
            ...$validated,
            'slug' => $name.'-'.Str::lower((string) Str::uuid()),
            'status' => 'draft',
        ]);

        return new WebsiteProjectResource($project);
    }

    public function show(WebsiteProject $project): WebsiteProjectResource
    {
        Gate::authorize('view', $project);

        return new WebsiteProjectResource(
            $project->load(['sources' => fn ($query) => $query->latest()]),
        );
    }

    public function update(
        SaveWebsiteProjectRequest $request,
        WebsiteProject $project,
    ): WebsiteProjectResource {
        Gate::authorize('update', $project);

        $project->fill($request->validated());
        $project->save();

        return new WebsiteProjectResource($project->refresh());
    }

    public function destroy(WebsiteProject $project): JsonResponse
    {
        Gate::authorize('delete', $project);

        $paths = $project->sources()->pluck('stored_path')->all();

        if ($paths !== [] && ! Storage::disk('project_sources')->delete($paths)) {
            throw new RuntimeException('Unable to remove project source files.');
        }

        $project->delete();

        return response()->json([
            'success' => true,
            'message' => 'Project deleted.',
        ]);
    }
}
