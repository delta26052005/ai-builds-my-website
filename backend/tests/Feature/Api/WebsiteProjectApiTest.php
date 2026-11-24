<?php

namespace Tests\Feature\Api;

use App\Models\ProjectSource;
use App\Models\User;
use App\Models\WebsiteProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebsiteProjectApiTest extends TestCase
{
    use RefreshDatabase;

    private const SPA_ORIGIN = 'http://localhost:5174';

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/projects')->assertUnauthorized();
        $this->postJson('/api/projects', $this->projectPayload())->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_a_project(): void
    {
        $user = User::factory()->create();

        $this->asUser($user)
            ->postJson('/api/projects', $this->projectPayload())
            ->assertCreated()
            ->assertJsonPath('data.business_name', 'Northstar Studio')
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('website_projects', [
            'user_id' => $user->id,
            'business_name' => 'Northstar Studio',
            'status' => 'draft',
        ]);
    }

    public function test_required_business_fields_are_validated(): void
    {
        $this->asUser(User::factory()->create())
            ->postJson('/api/projects', [
                'business_name' => 'Northstar Studio',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'city',
                'country',
                'business_category',
            ]);
    }

    public function test_social_links_must_be_http_or_https_urls(): void
    {
        $payload = $this->projectPayload();
        $payload['social_links']['instagram'] = 'javascript:alert(1)';

        $this->asUser(User::factory()->create())
            ->postJson('/api/projects', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['social_links.instagram']);
    }

    public function test_authenticated_user_can_list_only_their_own_projects(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $project = $this->makeProject($user);
        $this->makeProject($otherUser, ['business_name' => 'Private project']);

        $this->asUser($user)
            ->getJson('/api/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $project->id);
    }

    public function test_user_can_view_a_project_and_its_source_metadata(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $project->sources()->create([
            'original_filename' => 'menu.pdf',
            'stored_path' => $project->id.'/generated-name.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 1200,
            'source_type' => 'price_list',
        ]);

        $this->asUser($user)
            ->getJson("/api/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.sources.0.original_filename', 'menu.pdf')
            ->assertJsonMissingPath('data.sources.0.stored_path');
    }

    public function test_user_can_update_their_project(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $this->asUser($user)
            ->patchJson("/api/projects/{$project->id}", [
                'business_name' => 'Northstar Creative',
            ])
            ->assertOk()
            ->assertJsonPath('data.business_name', 'Northstar Creative');

        $this->assertDatabaseHas('website_projects', [
            'id' => $project->id,
            'business_name' => 'Northstar Creative',
        ]);
    }

    public function test_user_cannot_access_another_users_project_or_sources(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $project = $this->makeProject($owner);
        $source = $project->sources()->create([
            'original_filename' => 'private.pdf',
            'stored_path' => $project->id.'/private.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'source_type' => 'pdf',
        ]);

        $this->asUser($otherUser)
            ->getJson("/api/projects/{$project->id}")
            ->assertForbidden();

        $this->asUser($otherUser)
            ->patchJson("/api/projects/{$project->id}", [
                'business_name' => 'Stolen project',
            ])
            ->assertForbidden();

        $this->asUser($otherUser)
            ->getJson("/api/projects/{$project->id}/sources")
            ->assertForbidden();

        $this->asUser($otherUser)
            ->deleteJson("/api/projects/{$project->id}/sources/{$source->id}")
            ->assertForbidden();

        $this->asUser($otherUser)
            ->deleteJson("/api/projects/{$project->id}")
            ->assertForbidden();
    }

    public function test_user_can_upload_valid_pdf_and_image_files(): void
    {
        Storage::fake('project_sources');

        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $files = [
            UploadedFile::fake()->create('price-list.pdf', 20, 'application/pdf'),
            UploadedFile::fake()->image('team-photo.png'),
        ];

        $this->asUser($user)
            ->post("/api/projects/{$project->id}/sources", [
                'files' => $files,
                'source_types' => ['price_list', 'image'],
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.original_filename', 'price-list.pdf')
            ->assertJsonMissingPath('data.0.stored_path');

        $this->assertDatabaseCount('project_sources', 2);
        $this->assertCount(2, Storage::disk('project_sources')->allFiles((string) $project->id));
        foreach (ProjectSource::where('website_project_id', $project->id)->get() as $source) {
            $this->assertMatchesRegularExpression(
                '~^'.preg_quote((string) $project->id, '~').'/[a-f0-9-]+\.(pdf|png)$~',
                $source->stored_path,
            );
        }
    }

    public function test_unsupported_upload_formats_are_rejected(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $this->asUser($user)
            ->post("/api/projects/{$project->id}/sources", [
                'files' => [
                    UploadedFile::fake()->create('installer.exe', 10, 'application/octet-stream'),
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['files.0']);

        $this->assertDatabaseCount('project_sources', 0);
    }

    public function test_project_source_count_is_limited(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        for ($index = 0; $index < 25; $index++) {
            $project->sources()->create([
                'original_filename' => "source-{$index}.pdf",
                'stored_path' => "{$project->id}/source-{$index}.pdf",
                'mime_type' => 'application/pdf',
                'file_size' => 100,
                'source_type' => 'pdf',
            ]);
        }

        $this->asUser($user)
            ->post("/api/projects/{$project->id}/sources", [
                'files' => [
                    UploadedFile::fake()->create('extra.pdf', 10, 'application/pdf'),
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['files']);

        $this->assertDatabaseCount('project_sources', 25);
    }

    public function test_user_can_delete_an_uploaded_source_and_its_file(): void
    {
        Storage::fake('project_sources');

        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $response = $this->asUser($user)
            ->post("/api/projects/{$project->id}/sources", [
                'files' => [
                    UploadedFile::fake()->create('brochure.pdf', 15, 'application/pdf'),
                ],
            ])
            ->assertCreated();

        $sourceId = $response->json('data.0.id');
        $source = ProjectSource::findOrFail($sourceId);

        Storage::disk('project_sources')->assertExists($source->stored_path);

        $this->asUser($user)
            ->deleteJson("/api/projects/{$project->id}/sources/{$source->id}")
            ->assertOk();

        $this->assertDatabaseMissing('project_sources', ['id' => $source->id]);
        Storage::disk('project_sources')->assertMissing($source->stored_path);
    }

    public function test_deleting_a_project_removes_its_uploaded_files(): void
    {
        Storage::fake('project_sources');

        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $path = $project->id.'/source.pdf';
        Storage::disk('project_sources')->put($path, 'private document');
        $source = $project->sources()->create([
            'original_filename' => 'source.pdf',
            'stored_path' => $path,
            'mime_type' => 'application/pdf',
            'file_size' => 16,
            'source_type' => 'pdf',
        ]);

        $this->asUser($user)
            ->deleteJson("/api/projects/{$project->id}")
            ->assertOk();

        $this->assertDatabaseMissing('website_projects', ['id' => $project->id]);
        $this->assertDatabaseMissing('project_sources', ['id' => $source->id]);
        Storage::disk('project_sources')->assertMissing($path);
    }

    private function asUser(User $user): static
    {
        return $this->actingAs($user, 'web')
            ->withHeader('Origin', self::SPA_ORIGIN);
    }

    /**
     * @return array<string, mixed>
     */
    private function projectPayload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Northstar Studio',
            'city' => 'Portland',
            'state' => 'Oregon',
            'country' => 'United States',
            'business_category' => 'Design studio',
            'business_description' => 'Independent branding and web design.',
            'business_email' => 'hello@northstar.example',
            'business_phone' => '+1 (503) 555-0198',
            'business_address' => '100 Market Street',
            'website_url' => 'https://northstar.example',
            'google_business_url' => 'https://maps.google.com/?q=northstar',
            'social_links' => [
                'instagram' => 'https://instagram.com/northstar',
                'facebook' => null,
                'linkedin' => null,
                'youtube' => null,
                'x' => null,
                'tiktok' => null,
            ],
        ], $overrides);
    }

    private function makeProject(User $user, array $overrides = []): WebsiteProject
    {
        return $user->websiteProjects()->create(array_merge([
            'business_name' => 'Northstar Studio',
            'slug' => 'northstar-'.Str::uuid(),
            'city' => 'Portland',
            'country' => 'United States',
            'business_category' => 'Design studio',
            'status' => 'draft',
        ], $overrides));
    }
}
