<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_a_json_status(): void
    {
        $origin = 'http://localhost:5174';
        $response = $this->getJson('/api/health', [
            'Origin' => $origin,
        ]);

        $response->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', $origin)
            ->assertHeader('Access-Control-Allow-Credentials', 'true')
            ->assertExactJson([
                'success' => true,
                'message' => 'API is healthy.',
                'application_name' => config('app.name'),
                'environment' => app()->environment(),
            ]);
    }

    public function test_user_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/user')
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_user_endpoint_returns_safe_fields_for_a_session_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->getJson('/api/user', [
                'Origin' => 'http://localhost:5174',
            ])
            ->assertOk()
            ->assertExactJson([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ]);
    }
}
