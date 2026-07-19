<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\DeviceInterfaceMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BandwidthHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsIdentityUser(int $tenantId, array $roles = ['operator'], string $token = 'fake-token'): string
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

    protected function makeMetric(int $tenantId, string $polledAt, int $inBps, int $outBps): void
    {
        $device = Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Device',
            'ip_address' => '10.0.0.' . rand(1, 254),
            'type' => 'router',
            'status' => 'up',
        ]);

        $iface = DeviceInterface::create([
            'device_id' => $device->id,
            'tenant_id' => $tenantId,
            'if_index' => 1,
            'name' => 'eth0',
        ]);

        DeviceInterfaceMetric::create([
            'device_interface_id' => $iface->id,
            'tenant_id' => $tenantId,
            'oper_status' => 'up',
            'in_octets' => 1000,
            'out_octets' => 500,
            'in_bps' => $inBps,
            'out_bps' => $outBps,
            'polled_at' => $polledAt,
        ]);
    }

    public function test_returns_real_stored_bandwidth_history(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);

        $this->makeMetric(1, '2026-07-18 10:00:00', 1000, 500);
        $this->makeMetric(1, '2026-07-18 10:05:00', 2000, 800);

        $response = $this->getJson('/api/v1/dashboard/bandwidth-history', $this->authHeader($token));

        $response->assertStatus(200);
        $history = $response->json('history');

        $this->assertCount(2, $history);
        $this->assertSame(1000, $history[0]['in_bps']);
        $this->assertSame(2000, $history[1]['in_bps']);
    }

    public function test_history_is_tenant_scoped(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);

        $this->makeMetric(1, '2026-07-18 10:00:00', 1000, 500);
        $this->makeMetric(2, '2026-07-18 10:05:00', 9999, 9999);

        $response = $this->getJson('/api/v1/dashboard/bandwidth-history', $this->authHeader($token));

        $history = $response->json('history');
        $bpsValues = collect($history)->pluck('in_bps');

        $this->assertTrue($bpsValues->contains(1000));
        $this->assertFalse($bpsValues->contains(9999));
    }

    public function test_super_admin_sees_bandwidth_across_all_tenants(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1, roles: ['super-admin']);

        $this->makeMetric(1, '2026-07-18 10:00:00', 1000, 500);
        $this->makeMetric(2, '2026-07-18 10:05:00', 5000, 2000);

        $response = $this->getJson('/api/v1/dashboard/bandwidth-history', $this->authHeader($token));

        $history = $response->json('history');
        $bpsValues = collect($history)->pluck('in_bps');

        $this->assertTrue($bpsValues->contains(1000));
        $this->assertTrue($bpsValues->contains(5000));
    }

    public function test_returns_empty_array_when_no_metrics_exist(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);

        $response = $this->getJson('/api/v1/dashboard/bandwidth-history', $this->authHeader($token));

        $response->assertStatus(200);
        $this->assertSame([], $response->json('history'));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/dashboard/bandwidth-history');
        $response->assertStatus(401);
    }
}
