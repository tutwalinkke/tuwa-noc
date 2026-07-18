<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Maps token => identity payload. A single Http::fake() closure
     * (registered once per test, refreshed as identities are added)
     * inspects the actual Authorization header on each /me request and
     * returns the matching identity — unlike a static Http::fake()
     * response, which would return the SAME identity regardless of
     * which token was sent, silently breaking any test simulating two
     * different actors in the same test method.
     */
    protected array $identitiesByToken = [];

    protected function actingAsIdentityUser(int $tenantId, array $roles = ['operator'], ?string $token = null): string
    {
        $token = $token ?? 'token-' . count($this->identitiesByToken);

        $this->identitiesByToken[$token] = [
            'user' => [
                'id' => count($this->identitiesByToken) + 1,
                'tenant_id' => $tenantId,
                'name' => 'Test User',
                'email' => 'test@example.com',
                'status' => 'active',
            ],
            'roles' => $roles,
            'permissions' => [],
        ];

        Http::fake([
            '*/api/v1/me' => function ($request) {
                $authHeader = $request->header('Authorization')[0] ?? '';
                $sentToken = str_replace('Bearer ', '', $authHeader);

                if (isset($this->identitiesByToken[$sentToken])) {
                    return Http::response($this->identitiesByToken[$sentToken], 200);
                }

                return Http::response(['message' => 'Unauthenticated.'], 401);
            },
        ]);

        return $token;
    }

    protected function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_creating_a_customer_logs_activity_with_real_actor_attribution(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);

        $this->postJson('/api/v1/customers', ['name' => 'Jane'], $this->authHeader($token));

        $response = $this->getJson('/api/v1/activity', $this->authHeader($token));

        $response->assertStatus(200);
        $activity = collect($response->json('activities'))->first();

        $this->assertSame('Customer created: Jane', $activity['description']);
        $this->assertSame('Test User', $activity['actor_name']);
        $this->assertSame('test@example.com', $activity['actor_email']);
    }

    public function test_activity_is_tenant_scoped(): void
    {
        $tokenA = $this->actingAsIdentityUser(tenantId: 1);
        $this->postJson('/api/v1/customers', ['name' => 'Tenant A Customer'], $this->authHeader($tokenA));

        $tokenB = $this->actingAsIdentityUser(tenantId: 2);
        $this->postJson('/api/v1/customers', ['name' => 'Tenant B Customer'], $this->authHeader($tokenB));

        $responseA = $this->getJson('/api/v1/activity', $this->authHeader($tokenA));
        $descriptionsA = collect($responseA->json('activities'))->pluck('description');

        $this->assertTrue($descriptionsA->contains('Customer created: Tenant A Customer'));
        $this->assertFalse($descriptionsA->contains('Customer created: Tenant B Customer'));
    }

    public function test_super_admin_sees_activity_across_all_tenants(): void
    {
        $tokenA = $this->actingAsIdentityUser(tenantId: 1);
        $this->postJson('/api/v1/customers', ['name' => 'Tenant A Customer'], $this->authHeader($tokenA));

        $tokenB = $this->actingAsIdentityUser(tenantId: 2);
        $this->postJson('/api/v1/customers', ['name' => 'Tenant B Customer'], $this->authHeader($tokenB));

        $superToken = $this->actingAsIdentityUser(tenantId: 1, roles: ['super-admin']);
        $response = $this->getJson('/api/v1/activity', $this->authHeader($superToken));

        $descriptions = collect($response->json('activities'))->pluck('description');

        $this->assertTrue($descriptions->contains('Customer created: Tenant A Customer'));
        $this->assertTrue($descriptions->contains('Customer created: Tenant B Customer'));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/activity');
        $response->assertStatus(401);
    }
}
