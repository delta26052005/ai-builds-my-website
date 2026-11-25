<?php

namespace Tests\Feature\Api;

use App\Jobs\GenerateWebsite;
use App\Models\BusinessFactCandidate;
use App\Models\GeneratedWebsite;
use App\Models\User;
use App\Models\WebsiteProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class GeneratedWebsiteApiTest extends TestCase
{
    use RefreshDatabase;

    private const SPA_ORIGIN = 'http://localhost:5174';

    public function test_generation_requires_authentication_and_owner_confirmed_facts(): void
    {
        Queue::fake();
        $project = $this->makeProject();

        $this->postJson("/api/projects/{$project->id}/website/generate")->assertUnauthorized();

        $this->asUser($project->user)
            ->postJson("/api/projects/{$project->id}/website/generate")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['confirmed_facts']);

        $this->assertDatabaseCount('generated_websites', 0);
        Queue::assertNothingPushed();
    }

    public function test_only_project_owner_can_generate_or_read_the_website(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $project = $this->makeProject($owner);
        $this->confirmFact($project, 'business_name', 'business', 'Northstar Studio');

        $this->asUser($otherUser)
            ->postJson("/api/projects/{$project->id}/website/generate")
            ->assertForbidden();
        $this->asUser($otherUser)
            ->getJson("/api/projects/{$project->id}/website")
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_owner_can_queue_generation_once_and_read_an_empty_website(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $this->confirmFact($project, 'business_name', 'business', 'Northstar Studio');

        $this->asUser($user)
            ->getJson("/api/projects/{$project->id}/website")
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->asUser($user)
            ->postJson("/api/projects/{$project->id}/website/generate", [
                'template_key' => 'local_business',
            ])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending');

        $this->asUser($user)
            ->postJson("/api/projects/{$project->id}/website/generate")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending');

        Queue::assertPushed(GenerateWebsite::class, 1);
    }

    public function test_project_owner_can_save_valid_edits_and_create_a_revision(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $website = $this->makeWebsite($project);
        $content = $this->validContent();
        $content['hero']['heading'] = 'A better studio';
        $content['navigation'] = [['label' => 'Products & Services', 'href' => '#products']];

        $this->asUser($user)
            ->putJson("/api/projects/{$project->id}/website", [
                'content_json' => $content,
                'expected_version' => 1,
                'change_summary' => 'Updated the hero heading.',
            ])
            ->assertOk()
            ->assertJsonPath('data.current_version', 2)
            ->assertJsonPath('data.content_json.hero.heading', 'A better studio')
            ->assertJsonPath('data.content_json.navigation.0.href', '#products');

        $this->assertDatabaseHas('website_revisions', [
            'generated_website_id' => $website->id,
            'version_number' => 2,
            'change_summary' => 'Updated the hero heading.',
        ]);
    }

    public function test_owner_can_save_zero_one_or_multiple_products_with_optional_sections(): void
    {
        $owner = User::factory()->create();
        $project = $this->makeProject($owner);
        $this->makeWebsite($project);
        $productLists = [
            [],
            [['name' => 'Sourdough', 'description' => null]],
            [
                ['name' => 'Sourdough', 'description' => 'Naturally leavened bread.'],
                ['name' => 'Rye loaf', 'description' => null],
            ],
        ];

        foreach ($productLists as $index => $products) {
            $content = $this->validContent();
            $navigation = $index === 0
                ? []
                : [
                    ['label' => 'About', 'href' => '#about'],
                    ['label' => 'Products & Services', 'href' => '#products'],
                ];
            $content['navigation'] = $navigation;
            $content['services_or_products'] = $products;
            $content['gallery'] = [];
            $content['contact'] = [
                'address' => null,
                'phone' => null,
                'email' => null,
                'social_links' => [],
            ];

            $this->asUser($owner)
                ->putJson("/api/projects/{$project->id}/website", [
                    'content_json' => $content,
                    'expected_version' => $index + 1,
                ])
                ->assertOk()
                ->assertJsonPath('data.content_json.services_or_products', $products)
                ->assertJsonPath('data.content_json.navigation', $navigation)
                ->assertJsonPath('data.content_json.gallery', []);
        }
    }

    public function test_invalid_required_fields_and_partial_rows_do_not_overwrite_saved_content(): void
    {
        $owner = User::factory()->create();
        $project = $this->makeProject($owner);
        $website = $this->makeWebsite($project);
        $originalContent = $website->fresh()->content_json;
        $invalidPayloads = [];

        $emptyNavigation = $this->validContent();
        $emptyNavigation['navigation'][] = ['label' => '', 'href' => '#hero'];
        $invalidPayloads[] = [$emptyNavigation, 'navigation.1.label'];

        $partialProduct = $this->validContent();
        $partialProduct['services_or_products'] = [[
            'name' => '',
            'description' => 'A partially completed product.',
        ]];
        $invalidPayloads[] = [$partialProduct, 'services_or_products.0.name'];

        $missingBusinessName = $this->validContent();
        $missingBusinessName['business_name'] = '';
        $invalidPayloads[] = [$missingBusinessName, 'business_name'];

        foreach ($invalidPayloads as [$content, $field]) {
            $this->asUser($owner)
                ->putJson("/api/projects/{$project->id}/website", [
                    'content_json' => $content,
                    'expected_version' => 1,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field]);

            $this->assertSame($originalContent, $website->fresh()->content_json);
            $this->assertSame(1, $website->fresh()->current_version);
        }
    }

    public function test_owner_can_publish_and_unpublish_website_without_changing_content_or_version(): void
    {
        $owner = User::factory()->create();
        $project = $this->makeProject($owner);
        $website = $this->makeWebsite($project);
        $content = $website->content_json;

        $published = $this->asUser($owner)
            ->postJson("/api/projects/{$project->id}/website/publish")
            ->assertOk()
            ->assertJsonPath('data.is_published', true);
        $slug = $published->json('data.public_slug');
        $publishedAt = $published->json('data.published_at');

        $this->asUser($owner)
            ->postJson("/api/projects/{$project->id}/website/publish")
            ->assertOk()
            ->assertJsonPath('data.public_slug', $slug)
            ->assertJsonPath('data.published_at', $publishedAt);

        $this->asUser($owner)
            ->deleteJson("/api/projects/{$project->id}/website/publish")
            ->assertOk()
            ->assertJsonPath('data.is_published', false)
            ->assertJsonPath('data.published_at', null)
            ->assertJsonPath('data.public_slug', $slug);

        $this->assertSame($content, $website->fresh()->content_json);
        $this->assertSame(1, $website->fresh()->current_version);
        $this->assertDatabaseCount('website_revisions', 1);
    }

    public function test_publishing_is_owner_only_and_generates_unique_public_slugs(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $project = $this->makeProject($owner);
        $website = $this->makeWebsite($project);

        $this->asUser($otherUser)
            ->postJson("/api/projects/{$project->id}/website/publish")
            ->assertForbidden();
        $this->asUser($otherUser)
            ->deleteJson("/api/projects/{$project->id}/website/publish")
            ->assertForbidden();

        $firstSlug = $this->asUser($owner)
            ->postJson("/api/projects/{$project->id}/website/publish")
            ->assertOk()
            ->json('data.public_slug');

        $secondProject = $this->makeProject($owner);
        $this->makeWebsite($secondProject);
        $secondSlug = $this->asUser($owner)
            ->postJson("/api/projects/{$secondProject->id}/website/publish")
            ->assertOk()
            ->json('data.public_slug');

        $this->assertNotSame($firstSlug, $secondSlug);
        $this->assertSame($firstSlug, $website->fresh()->public_slug);
    }

    public function test_unpublished_and_invalid_websites_are_not_publicly_available(): void
    {
        $owner = User::factory()->create();
        $project = $this->makeProject($owner);
        $website = $this->makeWebsite($project);
        $slug = 'northstar-unpublished';
        $website->update(['public_slug' => $slug]);

        $this->getJson("/api/public/websites/{$slug}")->assertNotFound();

        $content = $this->validContent();
        $content['unexpected'] = 'must not publish';
        $website->update(['content_json' => $content]);

        $this->asUser($owner)
            ->postJson("/api/projects/{$project->id}/website/publish")
            ->assertUnprocessable();
        $this->assertFalse($website->fresh()->is_published);
    }

    public function test_public_page_and_gallery_expose_only_approved_project_images_until_unpublished(): void
    {
        Storage::fake('project_sources');
        $owner = User::factory()->create();
        $project = $this->makeProject($owner);
        $website = $this->makeWebsite($project);
        $approvedImage = $project->sources()->create([
            'original_filename' => 'approved.jpg',
            'stored_path' => "{$project->id}/approved.jpg",
            'mime_type' => 'image/jpeg',
            'file_size' => 4,
            'source_type' => 'image',
            'processing_status' => 'awaiting_vision',
        ]);
        $otherImage = $project->sources()->create([
            'original_filename' => 'unlisted.png',
            'stored_path' => "{$project->id}/unlisted.png",
            'mime_type' => 'image/png',
            'file_size' => 4,
            'source_type' => 'image',
            'processing_status' => 'awaiting_vision',
        ]);
        $pdf = $project->sources()->create([
            'original_filename' => 'private.pdf',
            'stored_path' => "{$project->id}/private.pdf",
            'mime_type' => 'application/pdf',
            'file_size' => 4,
            'source_type' => 'pdf',
            'processing_status' => 'completed',
        ]);
        Storage::disk('project_sources')->put($approvedImage->stored_path, 'image');
        Storage::disk('project_sources')->put($otherImage->stored_path, 'image');
        Storage::disk('project_sources')->put($pdf->stored_path, 'pdf!');

        $content = $this->validContent();
        $content['gallery'] = [[
            'image_url' => null,
            'alt' => 'Approved business image',
            'source_id' => $approvedImage->id,
        ]];
        $website->update(['content_json' => $content]);
        $slug = $this->asUser($owner)
            ->postJson("/api/projects/{$project->id}/website/publish")
            ->assertOk()
            ->json('data.public_slug');

        $this->getJson("/api/public/websites/{$slug}")
            ->assertOk()
            ->assertJsonPath('data.slug', $slug)
            ->assertJsonPath('data.content_json.business_name', 'Northstar Studio')
            ->assertJsonMissingPath('data.content_json.content_provenance')
            ->assertJsonMissingPath('data.website_project_id')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->get("/api/public/websites/{$slug}/images/{$approvedImage->id}")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson("/api/public/websites/{$slug}/images/{$otherImage->id}")->assertNotFound();
        $this->getJson("/api/public/websites/{$slug}/images/{$pdf->id}")->assertNotFound();
        $this->getJson("/api/public/websites/{$slug}/images/not-an-id")->assertNotFound();

        $this->asUser($owner)->deleteJson("/api/projects/{$project->id}/website/publish")->assertOk();
        $this->getJson("/api/public/websites/{$slug}")->assertNotFound();
        $this->getJson("/api/public/websites/{$slug}/images/{$approvedImage->id}")->assertNotFound();
    }

    public function test_unsupported_json_fields_and_stale_edits_are_rejected(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $this->makeWebsite($project);
        $content = $this->validContent();
        $content['script'] = '<script>alert(1)</script>';

        $this->asUser($user)
            ->putJson("/api/projects/{$project->id}/website", [
                'content_json' => $content,
            ])
            ->assertUnprocessable();

        $this->asUser($user)
            ->putJson("/api/projects/{$project->id}/website", [
                'content_json' => $this->validContent(),
                'expected_version' => 7,
            ])
            ->assertConflict();

        $this->assertDatabaseCount('website_revisions', 1);
    }

    public function test_owner_can_queue_an_allowlisted_section_regeneration(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $this->makeWebsite($project);
        $this->confirmFact($project, 'business_name', 'business', 'Northstar Studio');

        $this->asUser($user)
            ->postJson("/api/projects/{$project->id}/website/regenerate-section", [
                'section' => 'hero',
            ])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending');

        Queue::assertPushed(
            GenerateWebsite::class,
            fn (GenerateWebsite $job): bool => $job->operation === 'regenerate_section'
                && $job->section === 'hero',
        );
    }

    public function test_section_name_is_allowlisted(): void
    {
        $owner = User::factory()->create();
        $project = $this->makeProject($owner);

        $this->asUser($owner)
            ->postJson("/api/projects/{$project->id}/website/regenerate-section", [
                'section' => 'scripts',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['section']);
    }

    public function test_other_users_cannot_read_website_revisions(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $project = $this->makeProject($owner);
        $this->makeWebsite($project);

        $this->asUser($otherUser)
            ->getJson("/api/projects/{$project->id}/website/revisions")
            ->assertForbidden();
    }

    public function test_revisions_are_scoped_to_the_requested_project(): void
    {
        $owner = User::factory()->create();
        $project = $this->makeProject($owner);
        $otherProject = $this->makeProject($owner);
        $website = $this->makeWebsite($project);
        $revision = $website->revisions()->firstOrFail();

        $this->asUser($owner)
            ->getJson("/api/projects/{$otherProject->id}/website/revisions/{$revision->id}")
            ->assertNotFound();
        $this->asUser($owner)
            ->deleteJson("/api/projects/{$otherProject->id}/website/revisions/{$revision->id}")
            ->assertNotFound();
    }

    public function test_owner_can_delete_only_historical_revisions_without_changing_content_or_reusing_versions(): void
    {
        $owner = User::factory()->create();
        $project = $this->makeProject($owner);
        $website = $this->makeWebsite($project);
        $versionTwo = $website->revisions()->create([
            'version_number' => 2,
            'content_json' => $this->validContent(),
            'change_summary' => 'Second version.',
        ]);
        $website->update(['current_version' => 3]);
        $versionThree = $website->revisions()->create([
            'version_number' => 3,
            'content_json' => $this->validContent(),
            'change_summary' => 'Active version.',
        ]);
        $activeContent = $website->fresh()->content_json;

        $this->asUser($owner)
            ->deleteJson("/api/projects/{$project->id}/website/revisions/{$versionTwo->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('website_revisions', ['id' => $versionTwo->id]);
        $this->assertSame(3, $website->fresh()->current_version);
        $this->assertSame($activeContent, $website->fresh()->content_json);
        $this->assertDatabaseHas('website_revisions', ['id' => $versionThree->id, 'version_number' => 3]);

        $editedContent = $this->validContent();
        $editedContent['hero']['heading'] = 'Northstar design studio';
        $this->asUser($owner)
            ->putJson("/api/projects/{$project->id}/website", [
                'content_json' => $editedContent,
                'expected_version' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.current_version', 4);

        $this->assertDatabaseHas('website_revisions', [
            'generated_website_id' => $website->id,
            'version_number' => 4,
        ]);
        $this->assertDatabaseMissing('website_revisions', [
            'generated_website_id' => $website->id,
            'version_number' => 2,
        ]);
    }

    public function test_current_revision_cannot_be_deleted_and_other_users_cannot_delete_revisions(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $project = $this->makeProject($owner);
        $website = $this->makeWebsite($project);
        $revision = $website->revisions()->firstOrFail();

        $this->asUser($owner)
            ->deleteJson("/api/projects/{$project->id}/website/revisions/{$revision->id}")
            ->assertConflict();
        $this->asUser($otherUser)
            ->getJson("/api/projects/{$project->id}/website")
            ->assertForbidden();
        $this->asUser($otherUser)
            ->deleteJson("/api/projects/{$project->id}/website/revisions/{$revision->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('website_revisions', ['id' => $revision->id]);
        $this->assertSame($this->validContent(), $website->fresh()->content_json);
    }

    public function test_gallery_source_ids_must_belong_to_the_owned_project(): void
    {
        $owner = User::factory()->create();
        $project = $this->makeProject($owner);
        $otherProject = $this->makeProject($owner);
        $website = $this->makeWebsite($project);
        $foreignImage = $otherProject->sources()->create([
            'original_filename' => 'private.jpg',
            'stored_path' => 'private.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 100,
            'source_type' => 'image',
            'processing_status' => 'awaiting_vision',
        ]);
        $content = $this->validContent();
        $content['gallery'] = [[
            'image_url' => null,
            'alt' => 'Private image',
            'source_id' => $foreignImage->id,
        ]];

        $this->asUser($owner)
            ->putJson("/api/projects/{$project->id}/website", [
                'content_json' => $content,
                'expected_version' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['content_json.gallery']);

        $this->assertSame(1, $website->fresh()->current_version);
    }

    public function test_saved_copy_provenance_marks_changed_editor_text_as_owner_authored(): void
    {
        $owner = User::factory()->create();
        $project = $this->makeProject($owner);
        $website = $this->makeWebsite($project);
        $content = $this->validContent();
        $content['tagline'] = 'Independent brand and web design.';
        $content['content_provenance'] = [
            'tagline' => 'ai_generated_unverified',
            'about.description' => 'ai_generated_unverified',
        ];
        $website->update(['content_json' => $content]);
        $content['about']['description'] = 'An introduction written by the business owner.';

        $response = $this->asUser($owner)
            ->putJson("/api/projects/{$project->id}/website", [
                'content_json' => $content,
                'expected_version' => 1,
            ]);
        $response->assertOk()
            ->assertJsonPath('data.content_json.content_provenance.tagline', 'ai_generated_unverified');
        $this->assertSame(
            'owner_authored',
            $response->json('data.content_json.content_provenance')['about.description'],
            json_encode($response->json(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_uploaded_gallery_images_are_served_only_to_their_project_owner(): void
    {
        Storage::fake('project_sources');
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $project = $this->makeProject($owner);
        $source = $project->sources()->create([
            'original_filename' => 'brand.jpg',
            'stored_path' => "{$project->id}/brand.jpg",
            'mime_type' => 'image/jpeg',
            'file_size' => 4,
            'source_type' => 'image',
            'processing_status' => 'awaiting_vision',
        ]);
        Storage::disk('project_sources')->put($source->stored_path, 'test');

        $this->asUser($owner)
            ->get("/api/projects/{$project->id}/sources/{$source->id}/image")
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->asUser($otherUser)
            ->getJson("/api/projects/{$project->id}/sources/{$source->id}/image")
            ->assertForbidden();
    }

    private function asUser(User $user): static
    {
        Auth::forgetGuards();

        return $this->actingAs($user, 'web')->withHeader('Origin', self::SPA_ORIGIN);
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

    private function confirmFact(
        WebsiteProject $project,
        string $key,
        string $category,
        string $value,
        string $status = 'confirmed',
    ): BusinessFactCandidate {
        $fact = $project->businessFacts()->create([
            'fact_key' => $key,
            'category' => $category,
        ]);

        return $fact->candidates()->create([
            'candidate_value' => $value,
            'value_hash' => hash('sha256', mb_strtolower($value)),
            'verification_status' => $status,
        ]);
    }

    private function makeWebsite(WebsiteProject $project): GeneratedWebsite
    {
        $website = $project->generatedWebsite()->create([
            'title' => 'Northstar Studio',
            'template_key' => 'modern_business',
            'content_json' => $this->validContent(),
            'status' => 'completed',
            'current_version' => 1,
        ]);
        $website->revisions()->create([
            'version_number' => 1,
            'content_json' => $this->validContent(),
            'change_summary' => 'Initial version.',
        ]);

        return $website;
    }

    /**
     * @return array<string, mixed>
     */
    private function validContent(): array
    {
        return [
            'business_name' => 'Northstar Studio',
            'tagline' => null,
            'template_key' => 'modern_business',
            'theme' => [
                'primary_color' => '#1F2937',
                'secondary_color' => '#E5E7EB',
                'font_style' => 'modern',
            ],
            'navigation' => [['label' => 'About', 'href' => '#about']],
            'hero' => [
                'heading' => 'Northstar Studio',
                'description' => '',
                'image' => null,
                'button_text' => null,
            ],
            'about' => ['heading' => 'About', 'description' => ''],
            'services_or_products' => [],
            'highlights' => [],
            'gallery' => [],
            'contact' => [
                'address' => null,
                'phone' => null,
                'email' => null,
                'social_links' => [],
            ],
            'footer' => ['copyright_text' => null, 'show_business_name' => true],
        ];
    }
}
