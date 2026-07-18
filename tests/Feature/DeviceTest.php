<?php

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeviceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Fake Identity's /me response so identity.auth middleware treats
     * the request as authenticated, without any real network call.
     */
    protected function actingAsIdentityUser(int $tenantId, array $roles = ['operator'], string $token = 'fake-test-token'): string
    {
        Http::fake([
            '*/api/v1/me' => Http::response([
                'user' => [
                    'id' => 1,
                    'tenant_id' => $tenantId,
                    'name' => 'Test User',
                    'email' => 'test@example.com',
                    'status' => 'active',
                ],
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

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/devices');

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_create_a_device(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);

        $response = $this->postJson('/api/v1/devices', [
            'name' => 'Test Router',
            'ip_address' => '10.0.0.1',
            'type' => 'router',
        ], $this->authHeader($token));

        $response->assertStatus(201)
            ->assertJsonPath('device.name', 'Test Router')
            ->assertJsonPath('device.tenant_id', 1);

        $this->assertDatabaseHas('devices', ['name' => 'Test Router', 'tenant_id' => 1]);
    }

    public function test_device_is_created_under_the_callers_tenant_regardless_of_request_body(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 5);

        // Deliberately try to sneak a different tenant_id in the body —
        // server must ignore it and use the authenticated caller's own.
        $response = $this->postJson('/api/v1/devices', [
            'name' => 'Sneaky Device',
            'ip_address' => '10.0.0.2',
            'type' => 'router',
            'tenant_id' => 999,
        ], $this->authHeader($token));

        $response->assertStatus(201)
            ->assertJsonPath('device.tenant_id', 5);
    }

    public function test_user_only_sees_devices_in_own_tenant(): void
    {
        Device::create(['tenant_id' => 1, 'name' => 'Tenant 1 Device', 'ip_address' => '10.0.0.1', 'type' => 'router', 'status' => 'unknown']);
        Device::create(['tenant_id' => 2, 'name' => 'Tenant 2 Device', 'ip_address' => '10.0.0.2', 'type' => 'router', 'status' => 'unknown']);

        $token = $this->actingAsIdentityUser(tenantId: 1);

        $response = $this->getJson('/api/v1/devices', $this->authHeader($token));

        $response->assertStatus(200);
        $names = collect($response->json('devices'))->pluck('name');

        $this->assertTrue($names->contains('Tenant 1 Device'));
        $this->assertFalse($names->contains('Tenant 2 Device'));
    }

    public function test_super_admin_sees_devices_across_all_tenants(): void
    {
        Device::create(['tenant_id' => 1, 'name' => 'Tenant 1 Device', 'ip_address' => '10.0.0.1', 'type' => 'router', 'status' => 'unknown']);
        Device::create(['tenant_id' => 2, 'name' => 'Tenant 2 Device', 'ip_address' => '10.0.0.2', 'type' => 'router', 'status' => 'unknown']);

        $token = $this->actingAsIdentityUser(tenantId: 1, roles: ['super-admin']);

        $response = $this->getJson('/api/v1/devices', $this->authHeader($token));

        $names = collect($response->json('devices'))->pluck('name');

        $this->assertTrue($names->contains('Tenant 1 Device'));
        $this->assertTrue($names->contains('Tenant 2 Device'));
    }

    public function test_user_cannot_view_device_from_different_tenant(): void
    {
        $device = Device::create(['tenant_id' => 2, 'name' => 'Other Tenant Device', 'ip_address' => '10.0.0.5', 'type' => 'router', 'status' => 'unknown']);

        $token = $this->actingAsIdentityUser(tenantId: 1);

        $response = $this->getJson("/api/v1/devices/{$device->id}", $this->authHeader($token));

        $response->assertStatus(404);
    }

    public function test_user_cannot_delete_device_from_different_tenant(): void
    {
        $device = Device::create(['tenant_id' => 2, 'name' => 'Other Tenant Device', 'ip_address' => '10.0.0.6', 'type' => 'router', 'status' => 'unknown']);

        $token = $this->actingAsIdentityUser(tenantId: 1);

        $response = $this->deleteJson("/api/v1/devices/{$device->id}", [], $this->authHeader($token));

        $response->assertStatus(404);
        $this->assertDatabaseHas('devices', ['id' => $device->id]);
    }

    public function test_user_can_update_own_tenants_device(): void
    {
        $device = Device::create(['tenant_id' => 1, 'name' => 'Old Name', 'ip_address' => '10.0.0.7', 'type' => 'router', 'status' => 'unknown']);

        $token = $this->actingAsIdentityUser(tenantId: 1);

        $response = $this->patchJson("/api/v1/devices/{$device->id}", ['name' => 'New Name'], $this->authHeader($token));

        $response->assertStatus(200)
            ->assertJsonPath('device.name', 'New Name');
    }

    public function test_creating_device_requires_valid_ip_address(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);

        $response = $this->postJson('/api/v1/devices', [
            'name' => 'Bad IP Device',
            'ip_address' => 'not-an-ip',
            'type' => 'router',
        ], $this->authHeader($token));

        $response->assertStatus(422);
    }

    public function test_invalid_token_is_rejected(): void
    {
        Http::fake([
            '*/api/v1/me' => Http::response(['message' => 'Unauthenticated.'], 401),
        ]);

        $response = $this->getJson('/api/v1/devices', $this->authHeader('invalid-token'));

        $response->assertStatus(401);
    }
}
