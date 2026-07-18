<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Subnet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SubnetTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsIdentityUser(int $tenantId, array $roles = ['operator'], string $token = 'fake-test-token'): string
    {
        Http::fake([
            '*/api/v1/me' => Http::response([
                'user' => ['id' => 1, 'tenant_id' => $tenantId, 'name' => 'Test User', 'email' => 'test@example.com', 'status' => 'active'],
                'roles' => $roles,
                'permissions' => [],
            ], 200),
        ]);

        return $token;
    }

    protected function authHeader(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    public function test_creating_subnet_generates_addresses_and_returns_count(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);

        $response = $this->postJson('/api/v1/subnets', [
            'cidr' => '10.0.0.0/29',
        ], $this->authHeader($token));

        $response->assertStatus(201)
            ->assertJsonPath('addresses_generated', 6)
            ->assertJsonPath('subnet.tenant_id', 1);
    }

    public function test_oversized_subnet_is_rejected_with_422(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);

        $response = $this->postJson('/api/v1/subnets', [
            'cidr' => '10.0.0.0/16',
        ], $this->authHeader($token));

        $response->assertStatus(422);
    }

    public function test_user_only_sees_subnets_in_own_tenant(): void
    {
        Subnet::create(['tenant_id' => 1, 'cidr' => '10.0.0.0/29']);
        Subnet::create(['tenant_id' => 2, 'cidr' => '10.0.1.0/29']);

        $token = $this->actingAsIdentityUser(tenantId: 1);

        $response = $this->getJson('/api/v1/subnets', $this->authHeader($token));

        $cidrs = collect($response->json('subnets'))->pluck('cidr');
        $this->assertTrue($cidrs->contains('10.0.0.0/29'));
        $this->assertFalse($cidrs->contains('10.0.1.0/29'));
    }

    public function test_user_cannot_view_subnet_from_different_tenant(): void
    {
        $subnet = Subnet::create(['tenant_id' => 2, 'cidr' => '10.0.1.0/29']);

        $token = $this->actingAsIdentityUser(tenantId: 1);

        $response = $this->getJson("/api/v1/subnets/{$subnet->id}", $this->authHeader($token));

        $response->assertStatus(404);
    }

    public function test_allocate_endpoint_returns_next_available_ip(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);

        $createResponse = $this->postJson('/api/v1/subnets', ['cidr' => '10.0.0.0/29'], $this->authHeader($token));
        $subnetId = $createResponse->json('subnet.id');

        $response = $this->postJson("/api/v1/subnets/{$subnetId}/allocate", [
            'label' => 'Test allocation',
        ], $this->authHeader($token));

        $response->assertStatus(201)
            ->assertJsonPath('ip_address.address', '10.0.0.1')
            ->assertJsonPath('ip_address.status', 'allocated');
    }

    public function test_cannot_allocate_to_device_in_different_tenant(): void
    {
        $otherTenantDevice = Device::create([
            'tenant_id' => 2, 'name' => 'Other Tenant Device', 'ip_address' => '192.168.0.1', 'type' => 'router', 'status' => 'up',
        ]);

        $token = $this->actingAsIdentityUser(tenantId: 1);

        $createResponse = $this->postJson('/api/v1/subnets', ['cidr' => '10.0.0.0/29'], $this->authHeader($token));
        $subnetId = $createResponse->json('subnet.id');

        $response = $this->postJson("/api/v1/subnets/{$subnetId}/allocate", [
            'device_id' => $otherTenantDevice->id,
        ], $this->authHeader($token));

        $response->assertStatus(422);
    }

    public function test_exhausted_subnet_returns_409(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);

        $createResponse = $this->postJson('/api/v1/subnets', ['cidr' => '10.0.0.0/31'], $this->authHeader($token));
        $subnetId = $createResponse->json('subnet.id');

        $this->postJson("/api/v1/subnets/{$subnetId}/allocate", [], $this->authHeader($token));
        $this->postJson("/api/v1/subnets/{$subnetId}/allocate", [], $this->authHeader($token));

        $response = $this->postJson("/api/v1/subnets/{$subnetId}/allocate", [], $this->authHeader($token));

        $response->assertStatus(409);
    }

    public function test_release_makes_address_available_again(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);

        $createResponse = $this->postJson('/api/v1/subnets', ['cidr' => '10.0.0.0/29'], $this->authHeader($token));
        $subnetId = $createResponse->json('subnet.id');

        $allocateResponse = $this->postJson("/api/v1/subnets/{$subnetId}/allocate", [], $this->authHeader($token));
        $ipId = $allocateResponse->json('ip_address.id');

        $response = $this->postJson("/api/v1/subnets/{$subnetId}/release/{$ipId}", [], $this->authHeader($token));

        $response->assertStatus(200)
            ->assertJsonPath('ip_address.status', 'available')
            ->assertJsonPath('ip_address.device_id', null);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/subnets');

        $response->assertStatus(401);
    }
}
