<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private const SPA_ORIGIN = 'http://localhost:5174';

    public function test_csrf_cookie_endpoint_allows_the_trusted_spa_origin(): void
    {
        foreach ([self::SPA_ORIGIN, 'http://127.0.0.1:5174'] as $origin) {
            $this->get('/sanctum/csrf-cookie', [
                'Origin' => $origin,
            ])->assertNoContent()
                ->assertHeader('Access-Control-Allow-Origin', $origin)
                ->assertHeader('Access-Control-Allow-Credentials', 'true');
        }
    }

    public function test_api_preflight_allows_credentialed_json_and_xsrf_requests(): void
    {
        $this->options('/api/login', [], [
            'Origin' => self::SPA_ORIGIN,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,x-xsrf-token',
        ])->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', self::SPA_ORIGIN)
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_registration_creates_and_authenticates_a_user(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Taylor Example',
            'email' => 'taylor@example.com',
            'password' => 'ExamplePass123!',
            'password_confirmation' => 'ExamplePass123!',
        ], [
            'Origin' => self::SPA_ORIGIN,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.name', 'Taylor Example')
            ->assertJsonPath('user.email', 'taylor@example.com')
            ->assertJsonMissingPath('user.password');

        $user = User::where('email', 'taylor@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertTrue(Hash::check('ExamplePass123!', $user->password));
    }

    public function test_registration_rejects_duplicate_email_addresses(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/register', [
            'name' => 'Taylor Example',
            'email' => 'taken@example.com',
            'password' => 'ExamplePass123!',
            'password_confirmation' => 'ExamplePass123!',
        ], [
            'Origin' => self::SPA_ORIGIN,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_registration_rejects_invalid_data(): void
    {
        $this->postJson('/api/register', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different',
        ], [
            'Origin' => self::SPA_ORIGIN,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'name',
                'email',
                'password',
            ]);
    }

    public function test_login_authenticates_a_user_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'taylor@example.com',
            'password' => 'ExamplePass123!',
        ]);

        $this->postJson('/api/login', [
            'email' => 'taylor@example.com',
            'password' => 'ExamplePass123!',
        ], [
            'Origin' => self::SPA_ORIGIN,
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonMissingPath('user.password');

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_login_rejects_invalid_credentials_safely(): void
    {
        User::factory()->create([
            'email' => 'taylor@example.com',
            'password' => 'ExamplePass123!',
        ]);

        $this->postJson('/api/login', [
            'email' => 'taylor@example.com',
            'password' => 'incorrect',
        ], [
            'Origin' => self::SPA_ORIGIN,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'The provided credentials are incorrect.')
            ->assertJsonValidationErrors(['email']);

        $this->assertGuest('web');
    }

    public function test_logout_invalidates_the_authenticated_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->postJson('/api/logout', [], [
                'Origin' => self::SPA_ORIGIN,
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Logout successful.',
            ]);

        $this->assertGuest('web');
        Auth::forgetGuards();
        $this->getJson('/api/user', [
            'Origin' => self::SPA_ORIGIN,
        ])->assertUnauthorized();
    }
}
