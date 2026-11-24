<?php

namespace Tests\Feature\Api;

use App\Jobs\ExtractBusinessFacts;
use App\Jobs\ResearchBusinessProject;
use App\Models\BusinessFact;
use App\Models\BusinessFactCandidate;
use App\Models\BusinessResearchSource;
use App\Models\User;
use App\Models\WebsiteProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class BusinessIntelligenceApiTest extends TestCase
{
    use RefreshDatabase;

    private const SPA_ORIGIN = 'http://localhost:5174';

    public function test_unauthenticated_business_intelligence_requests_are_rejected(): void
    {
        $project = $this->makeProject();

        $this->postJson("/api/projects/{$project->id}/research")->assertUnauthorized();
        $this->getJson("/api/projects/{$project->id}/research/status")->assertUnauthorized();
        $this->postJson("/api/projects/{$project->id}/facts/extract")->assertUnauthorized();
        $this->getJson("/api/projects/{$project->id}/facts")->assertUnauthorized();
    }

    public function test_project_owner_can_start_research_and_prevent_duplicate_active_jobs(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $this->asUser($user)
            ->postJson("/api/projects/{$project->id}/research")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending');

        $this->asUser($user)
            ->postJson("/api/projects/{$project->id}/research")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending');

        Queue::assertPushed(ResearchBusinessProject::class, 1);
    }

    public function test_completed_research_requires_an_explicit_refresh(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $project->businessResearchRun()->create([
            'status' => 'completed',
            'source_count' => 4,
            'processed_at' => now(),
        ]);

        $this->asUser($user)
            ->postJson("/api/projects/{$project->id}/research")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
        Queue::assertNothingPushed();

        $this->asUser($user)
            ->postJson("/api/projects/{$project->id}/research", ['refresh' => true])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending');

        Queue::assertPushed(ResearchBusinessProject::class, 1);
    }

    public function test_project_owner_can_start_fact_extraction_once_while_active(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $project = $this->makeProject($user);

        $this->asUser($user)
            ->postJson("/api/projects/{$project->id}/facts/extract")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending');

        $this->asUser($user)
            ->postJson("/api/projects/{$project->id}/facts/extract")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending');

        Queue::assertPushed(ExtractBusinessFacts::class, 1);
    }

    public function test_other_users_cannot_start_or_read_project_research_and_facts(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $project = $this->makeProject($owner);

        $this->asUser($otherUser)
            ->postJson("/api/projects/{$project->id}/research")
            ->assertForbidden();
        $this->asUser($otherUser)
            ->getJson("/api/projects/{$project->id}/research/sources")
            ->assertForbidden();
        $this->asUser($otherUser)
            ->postJson("/api/projects/{$project->id}/facts/extract")
            ->assertForbidden();
        $this->asUser($otherUser)
            ->getJson("/api/projects/{$project->id}/facts")
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_owner_can_view_research_sources_with_access_status(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        $source = $this->makeResearchSource($project);

        $this->asUser($user)
            ->getJson("/api/projects/{$project->id}/research/sources")
            ->assertOk()
            ->assertJsonPath('data.0.id', $source->id)
            ->assertJsonPath('data.0.access_status', 'inaccessible')
            ->assertJsonPath('data.0.source_url', $source->source_url);
    }

    public function test_confirming_a_candidate_rejects_competing_values_and_keeps_evidence(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        [$fact, $confirmed, $competing] = $this->makeConflictingCandidates($project);

        $this->asUser($user)
            ->patchJson("/api/projects/{$project->id}/facts/{$fact->id}/candidates/{$confirmed->id}", [
                'action' => 'confirm',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertSame('confirmed', $confirmed->fresh()->verification_status);
        $this->assertSame('rejected', $competing->fresh()->verification_status);
        $this->assertDatabaseCount('business_fact_evidence', 2);
    }

    public function test_rejecting_one_conflict_returns_the_remaining_candidate_to_pending(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        [$fact, $rejected, $remaining] = $this->makeConflictingCandidates($project);

        $this->asUser($user)
            ->patchJson("/api/projects/{$project->id}/facts/{$fact->id}/candidates/{$rejected->id}", [
                'action' => 'reject',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $this->assertSame('rejected', $rejected->fresh()->verification_status);
        $this->assertSame('pending', $remaining->fresh()->verification_status);
    }

    public function test_owner_cannot_decide_a_fact_from_another_project(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $project = $this->makeProject($user);
        $otherProject = $this->makeProject($otherUser);
        [$fact, $candidate] = $this->makeConflictingCandidates($otherProject);

        $this->asUser($user)
            ->patchJson("/api/projects/{$project->id}/facts/{$fact->id}/candidates/{$candidate->id}", [
                'action' => 'confirm',
            ])
            ->assertNotFound();
    }

    public function test_fact_decision_action_is_validated(): void
    {
        $user = User::factory()->create();
        $project = $this->makeProject($user);
        [$fact, $candidate] = $this->makeConflictingCandidates($project);

        $this->asUser($user)
            ->patchJson("/api/projects/{$project->id}/facts/{$fact->id}/candidates/{$candidate->id}", [
                'action' => 'approve',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['action']);
    }

    private function asUser(User $user): static
    {
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

    private function makeResearchSource(WebsiteProject $project): BusinessResearchSource
    {
        $url = 'https://instagram.com/northstar';

        return $project->businessResearchSources()->create([
            'source_type' => 'social',
            'source_url' => $url,
            'url_hash' => hash('sha256', $url),
            'access_status' => 'inaccessible',
            'access_message' => 'Public content was not available.',
        ]);
    }

    /**
     * @return array{BusinessFact, BusinessFactCandidate, BusinessFactCandidate}
     */
    private function makeConflictingCandidates(WebsiteProject $project): array
    {
        $fact = $project->businessFacts()->create([
            'fact_key' => 'business_address',
            'category' => 'location',
        ]);
        $first = $fact->candidates()->create([
            'candidate_value' => '10 Main Street',
            'value_hash' => hash('sha256', '10 main street'),
            'verification_status' => 'conflicting',
        ]);
        $second = $fact->candidates()->create([
            'candidate_value' => '20 Oak Avenue',
            'value_hash' => hash('sha256', '20 oak avenue'),
            'verification_status' => 'conflicting',
        ]);

        foreach ([$first, $second] as $index => $candidate) {
            $candidate->evidence()->create([
                'source_type' => $index === 0 ? 'project' : 'tavily',
                'source_reference' => 'business address',
                'source_url' => $index === 0 ? null : 'https://example.com/contact',
                'supporting_evidence' => $candidate->candidate_value,
                'evidence_hash' => hash('sha256', $candidate->candidate_value),
            ]);
        }

        return [$fact, $first, $second];
    }
}
