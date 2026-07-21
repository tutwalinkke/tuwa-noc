<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TopologyTest extends TestCase
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

    protected function makeDevice(int $tenantId = 1, string $name = 'D'): Device
    {
        return Device::create([
            'tenant_id' => $tenantId, 'name' => $name, 'ip_address' => '10.0.0.' . rand(1, 254), 'type' => 'router', 'status' => 'up',
        ]);
    }

    public function test_index_returns_devices_and_links(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);
        $a = $this->makeDevice(1, 'Router A');
        $b = $this->makeDevice(1, 'Switch B');

        DeviceLink::create(['device_a_id' => $a->id, 'device_b_id' => $b->id, 'tenant_id' => 1]);

        $response = $this->getJson('/api/v1/topology', $this->authHeader($token));

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('devices'));
        $this->assertCount(1, $response->json('links'));
    }

    public function test_topology_is_tenant_scoped(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);
        $this->makeDevice(1, 'Own Device');
        $this->makeDevice(2, 'Other Tenant Device');

        $response = $this->getJson('/api/v1/topology', $this->authHeader($token));

        $names = collect($response->json('devices'))->pluck('name');
        $this->assertTrue($names->contains('Own Device'));
        $this->assertFalse($names->contains('Other Tenant Device'));
    }

    public function test_can_create_a_link_between_two_devices(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);
        $a = $this->makeDevice(1, 'A');
        $b = $this->makeDevice(1, 'B');

        $response = $this->postJson('/api/v1/topology/links', [
            'device_a_id' => $a->id,
            'device_b_id' => $b->id,
            'link_type' => 'fiber',
        ], $this->authHeader($token));

        $response->assertStatus(201);
        $this->assertSame(1, DeviceLink::count());
    }

    public function test_link_is_normalized_regardless_of_declared_order(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);
        $a = $this->makeDevice(1, 'A');
        $b = $this->makeDevice(1, 'B');

        // Declare it "backwards" (higher ID first) — should still be
        // stored consistently with the lower ID as device_a_id.
        $this->postJson('/api/v1/topology/links', [
            'device_a_id' => max($a->id, $b->id),
            'device_b_id' => min($a->id, $b->id),
        ], $this->authHeader($token));

        $link = DeviceLink::first();
        $this->assertSame(min($a->id, $b->id), $link->device_a_id);
        $this->assertSame(max($a->id, $b->id), $link->device_b_id);
    }

    public function test_duplicate_link_is_rejected_regardless_of_declared_order(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);
        $a = $this->makeDevice(1, 'A');
        $b = $this->makeDevice(1, 'B');

        $this->postJson('/api/v1/topology/links', [
            'device_a_id' => $a->id,
            'device_b_id' => $b->id,
        ], $this->authHeader($token));

        // Same link, declared in reverse — must be rejected as a duplicate.
        $response = $this->postJson('/api/v1/topology/links', [
            'device_a_id' => $b->id,
            'device_b_id' => $a->id,
        ], $this->authHeader($token));

        $response->assertStatus(422);
        $this->assertSame(1, DeviceLink::count());
    }

    public function test_cannot_link_devices_from_different_tenants(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1, roles: ['super-admin']);
        $a = $this->makeDevice(1, 'A');
        $b = $this->makeDevice(2, 'B');

        $response = $this->postJson('/api/v1/topology/links', [
            'device_a_id' => $a->id,
            'device_b_id' => $b->id,
        ], $this->authHeader($token));

        $response->assertStatus(422);
    }

    public function test_cannot_link_a_device_to_itself(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);
        $a = $this->makeDevice(1, 'A');

        $response = $this->postJson('/api/v1/topology/links', [
            'device_a_id' => $a->id,
            'device_b_id' => $a->id,
        ], $this->authHeader($token));

        $response->assertStatus(422);
    }

    public function test_can_delete_a_link(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);
        $a = $this->makeDevice(1, 'A');
        $b = $this->makeDevice(1, 'B');
        $link = DeviceLink::create(['device_a_id' => $a->id, 'device_b_id' => $b->id, 'tenant_id' => 1]);

        $response = $this->deleteJson("/api/v1/topology/links/{$link->id}", [], $this->authHeader($token));

        $response->assertStatus(200);
        $this->assertSame(0, DeviceLink::count());
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/topology');
        $response->assertStatus(401);
    }
}
