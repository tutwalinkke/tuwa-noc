<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceProvisioningCode;
use App\Services\WireGuardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeviceProvisioningCodeTest extends TestCase
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

    protected function mockWireGuardSuccess(string $ip = '10.20.0.5'): void
    {
        $this->partialMock(WireGuardService::class, function ($mock) use ($ip) {
            $mock->shouldReceive('nextAvailableIp')->andReturn($ip);
            $mock->shouldReceive('addPeer')->andReturn(true);
            $mock->shouldReceive('serverPublicKey')->andReturn('fake-server-public-key');
            $mock->shouldReceive('serverEndpoint')->andReturn('129.121.102.51:51821');
        });
    }

    // --- Generating codes (normal authenticated Portal action) ---

    public function test_can_generate_a_provisioning_code(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);

        $response = $this->postJson('/api/v1/provisioning-codes', [], $this->authHeader($token));

        $response->assertStatus(201);
        $this->assertSame(1, DeviceProvisioningCode::count());
        $this->assertNotEmpty($response->json('code'));
    }

    public function test_generating_a_code_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/provisioning-codes');
        $response->assertStatus(401);
    }

    // --- Redeeming codes (unauthenticated — the code IS the credential) ---

    public function test_redeem_endpoint_does_not_require_normal_authentication(): void
    {
        $this->mockWireGuardSuccess();

        $code = DeviceProvisioningCode::create([
            'code' => 'a-real-test-code',
            'tenant_id' => 1,
            'device_type' => 'router',
            'expires_at' => now()->addMinutes(15),
        ]);

        // No Authorization header at all — this is the whole point.
        $response = $this->postJson('/api/v1/provisioning-codes/redeem', [
            'code' => $code->code,
            'wireguard_public_key' => str_repeat('A', 44),
        ]);

        $response->assertStatus(201);
    }

    public function test_redeem_creates_a_device_and_returns_server_connection_details(): void
    {
        $this->mockWireGuardSuccess(ip: '10.20.0.7');

        $code = DeviceProvisioningCode::create([
            'code' => 'a-real-test-code',
            'tenant_id' => 1,
            'device_type' => 'router',
            'expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->postJson('/api/v1/provisioning-codes/redeem', [
            'code' => $code->code,
            'wireguard_public_key' => str_repeat('A', 44),
            'device_name' => 'My New Router',
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'assigned_ip' => '10.20.0.7',
            'server_public_key' => 'fake-server-public-key',
            'server_endpoint' => '129.121.102.51:51821',
        ]);

        $device = Device::first();
        $this->assertSame('My New Router', $device->name);
        $this->assertSame(1, $device->tenant_id);
        $this->assertSame('10.20.0.7', $device->wireguard_ip);
    }

    public function test_redeem_marks_the_code_as_used(): void
    {
        $this->mockWireGuardSuccess();

        $code = DeviceProvisioningCode::create([
            'code' => 'a-real-test-code',
            'tenant_id' => 1,
            'device_type' => 'router',
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->postJson('/api/v1/provisioning-codes/redeem', [
            'code' => $code->code,
            'wireguard_public_key' => str_repeat('A', 44),
        ]);

        $this->assertNotNull($code->fresh()->used_at);
    }

    public function test_cannot_redeem_the_same_code_twice(): void
    {
        $this->mockWireGuardSuccess();

        $code = DeviceProvisioningCode::create([
            'code' => 'a-real-test-code',
            'tenant_id' => 1,
            'device_type' => 'router',
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->postJson('/api/v1/provisioning-codes/redeem', [
            'code' => $code->code,
            'wireguard_public_key' => str_repeat('A', 44),
        ]);

        // Second attempt with the same code — must be rejected, and
        // must not create a second device.
        $response = $this->postJson('/api/v1/provisioning-codes/redeem', [
            'code' => $code->code,
            'wireguard_public_key' => str_repeat('B', 44),
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, Device::count());
    }

    public function test_cannot_redeem_an_expired_code(): void
    {
        $code = DeviceProvisioningCode::create([
            'code' => 'a-real-test-code',
            'tenant_id' => 1,
            'device_type' => 'router',
            'expires_at' => now()->subMinutes(1),
        ]);

        $response = $this->postJson('/api/v1/provisioning-codes/redeem', [
            'code' => $code->code,
            'wireguard_public_key' => str_repeat('A', 44),
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Device::count());
    }

    public function test_redeem_with_an_unknown_code_fails(): void
    {
        $response = $this->postJson('/api/v1/provisioning-codes/redeem', [
            'code' => 'this-code-does-not-exist',
            'wireguard_public_key' => str_repeat('A', 44),
        ]);

        $response->assertStatus(404);
    }

    public function test_redeem_fails_gracefully_when_no_wireguard_ips_remain(): void
    {
        $this->partialMock(WireGuardService::class, function ($mock) {
            $mock->shouldReceive('nextAvailableIp')->andReturn(null);
        });

        $code = DeviceProvisioningCode::create([
            'code' => 'a-real-test-code',
            'tenant_id' => 1,
            'device_type' => 'router',
            'expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->postJson('/api/v1/provisioning-codes/redeem', [
            'code' => $code->code,
            'wireguard_public_key' => str_repeat('A', 44),
        ]);

        $response->assertStatus(503);
        $this->assertSame(0, Device::count());
        $this->assertNull($code->fresh()->used_at);
    }

    public function test_redeem_fails_gracefully_when_adding_the_peer_fails(): void
    {
        $this->partialMock(WireGuardService::class, function ($mock) {
            $mock->shouldReceive('nextAvailableIp')->andReturn('10.20.0.5');
            $mock->shouldReceive('addPeer')->andReturn(false);
        });

        $code = DeviceProvisioningCode::create([
            'code' => 'a-real-test-code',
            'tenant_id' => 1,
            'device_type' => 'router',
            'expires_at' => now()->addMinutes(15),
        ]);

        $response = $this->postJson('/api/v1/provisioning-codes/redeem', [
            'code' => $code->code,
            'wireguard_public_key' => str_repeat('A', 44),
        ]);

        $response->assertStatus(500);
        $this->assertSame(0, Device::count());

        // The code must NOT be consumed if provisioning genuinely
        // failed — otherwise a transient WireGuard failure would
        // permanently burn a code the person would need to regenerate
        // anyway, with no way to retry the same one.
        $this->assertNull($code->fresh()->used_at);
    }
}
