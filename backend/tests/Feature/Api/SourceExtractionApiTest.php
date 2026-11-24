<?php

namespace Tests\Feature\Api;

use App\Jobs\ProcessProjectSource;
use App\Models\ProjectSource;
use App\Models\User;
use App\Models\WebsiteProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SourceExtractionApiTest extends TestCase
{
    use RefreshDatabase;

    private const SPA_ORIGIN = 'http://localhost:5174';

    public function test_unauthenticated_extraction_requests_are_rejected(): void
    {
        $project = $this->makeProject();
        $source = $this->makeSource($project);

        $this->getJson("/api/projects/{$project->id}/sources/{$source->id}/extraction")
            ->assertUnauthorized();
        $this->postJson("/api/projects/{$project->id}/sources/{$source->id}/process")
            ->assertUnauthorized();
    }

    public function test_an_owner_can_read_extraction_status_and_content(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $source = $this->makeSource($project);
        $source->extraction()->create([
            'status' => 'completed',
            'extraction_method' => 'prinsfrank/pdfparser',
            'page_count' => 2,
            'extracted_text' => 'Private source text',
        ]);

        $this->asUser($user)
            ->getJson("/api/projects/{$project->id}/sources/{$source->id}/extraction")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.page_count', 2)
            ->assertJsonMissingPath('data.extracted_text');

        $this->asUser($user)
            ->getJson("/api/projects/{$project->id}/extractions")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'completed');

        $this->asUser($user)
            ->getJson("/api/projects/{$project->id}/sources/{$source->id}/extraction/content")
            ->assertOk()
            ->assertJsonPath('data.extracted_text', 'Private source text');
    }

    public function test_other_users_cannot_access_an_extraction(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $project = $this->makeProject($owner);
        $source = $this->makeSource($project);
        $source->extraction()->create([
            'status' => 'completed',
            'extracted_text' => 'Secret text',
        ]);

        $this->asUser($otherUser)
            ->getJson("/api/projects/{$project->id}/sources/{$source->id}/extraction")
            ->assertForbidden();

        $this->asUser($otherUser)
            ->getJson("/api/projects/{$project->id}/sources/{$source->id}/extraction/content")
            ->assertForbidden();

        $this->asUser($otherUser)
            ->postJson("/api/projects/{$project->id}/sources/{$source->id}/process")
            ->assertForbidden();

        $this->asUser($otherUser)
            ->getJson("/api/projects/{$project->id}/extractions")
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_a_source_must_belong_to_the_requested_project(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $otherProject = $this->makeProject($user);
        $source = $this->makeSource($otherProject);

        $this->asUser($user)
            ->getJson("/api/projects/{$project->id}/sources/{$source->id}/extraction")
            ->assertNotFound();
    }

    public function test_failed_sources_can_be_retried_without_duplicate_queue_dispatches(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $source = $this->makeSource($project);
        $source->extraction()->create([
            'status' => 'failed',
            'error_code' => 'unreadable_pdf',
        ]);

        $this->asUser($user)
            ->postJson("/api/projects/{$project->id}/sources/{$source->id}/process")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending');

        $this->asUser($user)
            ->postJson("/api/projects/{$project->id}/sources/{$source->id}/process")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending');

        Queue::assertPushed(ProcessProjectSource::class, 1);
        $this->assertNotNull($source->fresh()->extraction->queued_at);
    }

    public function test_uploads_dispatch_independent_source_jobs(): void
    {
        Queue::fake();
        Storage::fake('project_sources');
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $this->asUser($user)
            ->post("/api/projects/{$project->id}/sources", [
                'files' => [
                    UploadedFile::fake()->create('brochure.pdf', 10, 'application/pdf'),
                    UploadedFile::fake()->image('photo.png'),
                ],
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'data');

        Queue::assertPushed(ProcessProjectSource::class, 2);
        $this->assertDatabaseCount('source_extractions', 2);
    }

    private function asUser(User $user): static
    {
        return $this->actingAs($user, 'web')
            ->withHeader('Origin', self::SPA_ORIGIN);
    }

    private function makeProject(?User $user = null): WebsiteProject
    {
        $user ??= User::factory()->create();

        return $user->websiteProjects()->create([
            'business_name' => 'Northstar Studio',
            'slug' => 'northstar-'.Str::uuid(),
            'city' => 'Portland',
            'country' => 'United States',
            'business_category' => 'Design studio',
            'status' => 'draft',
        ]);
    }

    private function makeSource(WebsiteProject $project): ProjectSource
    {
        return $project->sources()->create([
            'original_filename' => 'source.pdf',
            'stored_path' => $project->id.'/source.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'source_type' => 'pdf',
        ]);
    }
}
