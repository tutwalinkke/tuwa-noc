<?php

namespace Tests\Feature;

use App\Console\Commands\PollDevices;
use App\Console\Commands\PollDeviceInterfaces;
use App\Mail\BandwidthThresholdAlert;
use App\Mail\DeviceDownAlert;
use App\Models\Device;
use App\Models\DeviceEvent;
use App\Models\MaintenanceWindow;
use App\Services\AlertService;
use App\Services\IncidentService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class MaintenanceWindowTest extends TestCase
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

    protected function fakeIdentityUsers(int $tenantId): void
    {
        Http::fake([
            '*/api/v1/users*' => Http::response([
                'users' => [
                    [
                        'id' => 1,
                        'tenant_id' => $tenantId,
                        'email' => 'admin@example.com',
                        'status' => 'active',
                        'roles' => [['name' => 'super-admin']],
                    ],
                ],
            ], 200),
        ]);
    }

    protected function callProtectedMethod(object $instance, string $method, array $args = [])
    {
        $reflection = new ReflectionMethod($instance, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($instance, $args);
    }

    protected function makeInterfacesCommandWithOutput(): PollDeviceInterfaces
    {
        $command = new PollDeviceInterfaces();
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput()));

        return $command;
    }

    // --- API: scheduling and managing windows ---

    public function test_can_schedule_a_maintenance_window(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);

        $device = Device::create([
            'tenant_id' => 1, 'name' => 'D', 'ip_address' => '10.0.0.1', 'type' => 'router', 'status' => 'up',
        ]);

        $response = $this->postJson('/api/v1/maintenance-windows', [
            'device_id' => $device->id,
            'starts_at' => now()->addHour()->toIso8601String(),
            'ends_at' => now()->addHours(3)->toIso8601String(),
            'reason' => 'Firmware upgrade',
        ], $this->authHeader($token));

        $response->assertStatus(201);
        $this->assertSame(1, MaintenanceWindow::count());
        $this->assertSame('Firmware upgrade', $response->json('maintenance_window.reason'));
    }

    public function test_end_date_must_be_after_start_date(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);

        $device = Device::create([
            'tenant_id' => 1, 'name' => 'D', 'ip_address' => '10.0.0.1', 'type' => 'router', 'status' => 'up',
        ]);

        $response = $this->postJson('/api/v1/maintenance-windows', [
            'device_id' => $device->id,
            'starts_at' => now()->addHours(3)->toIso8601String(),
            'ends_at' => now()->addHour()->toIso8601String(),
        ], $this->authHeader($token));

        $response->assertStatus(422);
    }

    public function test_cannot_schedule_maintenance_for_a_device_in_a_different_tenant(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);

        $otherTenantDevice = Device::create([
            'tenant_id' => 2, 'name' => 'D', 'ip_address' => '10.0.0.1', 'type' => 'router', 'status' => 'up',
        ]);

        $response = $this->postJson('/api/v1/maintenance-windows', [
            'device_id' => $otherTenantDevice->id,
            'starts_at' => now()->addHour()->toIso8601String(),
            'ends_at' => now()->addHours(3)->toIso8601String(),
        ], $this->authHeader($token));

        $response->assertStatus(404);
    }

    public function test_can_end_a_window_early(): void
    {
        $token = $this->actingAsIdentityUser(tenantId: 1);

        $device = Device::create([
            'tenant_id' => 1, 'name' => 'D', 'ip_address' => '10.0.0.1', 'type' => 'router', 'status' => 'up',
        ]);

        $window = MaintenanceWindow::create([
            'device_id' => $device->id,
            'tenant_id' => 1,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
        ]);

        $response = $this->postJson("/api/v1/maintenance-windows/{$window->id}/end-early", [], $this->authHeader($token));

        $response->assertStatus(200);
        $this->assertTrue($window->fresh()->ends_at->isPast());
    }

    // --- Device::isInMaintenance() ---

    public function test_device_correctly_reports_maintenance_status(): void
    {
        $device = Device::create([
            'tenant_id' => 1, 'name' => 'D', 'ip_address' => '10.0.0.1', 'type' => 'router', 'status' => 'up',
        ]);

        $this->assertFalse($device->isInMaintenance());

        MaintenanceWindow::create([
            'device_id' => $device->id,
            'tenant_id' => 1,
            'starts_at' => now()->subMinutes(10),
            'ends_at' => now()->addMinutes(10),
        ]);

        $this->assertTrue($device->isInMaintenance());
    }

    public function test_future_window_does_not_count_as_currently_in_maintenance(): void
    {
        $device = Device::create([
            'tenant_id' => 1, 'name' => 'D', 'ip_address' => '10.0.0.1', 'type' => 'router', 'status' => 'up',
        ]);

        MaintenanceWindow::create([
            'device_id' => $device->id,
            'tenant_id' => 1,
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(3),
        ]);

        $this->assertFalse($device->isInMaintenance());
    }

    // --- PollDevices: suppressing down alerts during maintenance ---

    public function test_device_down_during_maintenance_does_not_send_alert(): void
    {
        Mail::fake();
        $this->fakeIdentityUsers(tenantId: 1);

        $device = Device::create([
            'tenant_id' => 1,
            'name' => 'Unreachable Test Address',
            'ip_address' => '192.0.2.1',
            'type' => 'server',
            'status' => 'up',
        ]);

        MaintenanceWindow::create([
            'device_id' => $device->id,
            'tenant_id' => 1,
            'starts_at' => now()->subMinutes(5),
            'ends_at' => now()->addHours(2),
        ]);

        $this->artisan('devices:poll');

        Mail::assertNotQueued(DeviceDownAlert::class);

        // Still logged, at info (not critical) severity — a real record
        // of what happened, just not something to page anyone about.
        $event = DeviceEvent::first();
        $this->assertNotNull($event);
        $this->assertSame('info', $event->severity);
    }

    public function test_device_down_outside_maintenance_still_sends_alert(): void
    {
        Mail::fake();
        $this->fakeIdentityUsers(tenantId: 1);

        Device::create([
            'tenant_id' => 1,
            'name' => 'Unreachable Test Address',
            'ip_address' => '192.0.2.1',
            'type' => 'server',
            'status' => 'up',
        ]);

        $this->artisan('devices:poll');

        Mail::assertQueued(DeviceDownAlert::class);
    }

    // --- Bandwidth threshold: suppressing alerts during maintenance ---

    public function test_bandwidth_breach_during_maintenance_does_not_send_alert(): void
    {
        Mail::fake();
        $this->fakeIdentityUsers(tenantId: 1);

        $device = Device::create([
            'tenant_id' => 1, 'name' => 'D', 'ip_address' => '127.0.0.1', 'type' => 'server', 'status' => 'up',
            'alert_threshold_in_bps' => 1_000_000,
        ]);

        MaintenanceWindow::create([
            'device_id' => $device->id,
            'tenant_id' => 1,
            'starts_at' => now()->subMinutes(5),
            'ends_at' => now()->addHours(2),
        ]);

        $command = $this->makeInterfacesCommandWithOutput();
        $alertService = app(AlertService::class);
        $incidentService = app(IncidentService::class);

        $this->callProtectedMethod($command, 'checkThresholdDirection', [
            $device, 'in', 2_000_000, $device->alert_threshold_in_bps, $alertService, $incidentService,
        ]);

        Mail::assertNotQueued(BandwidthThresholdAlert::class);

        $event = DeviceEvent::first();
        $this->assertNotNull($event);
        $this->assertSame('info', $event->severity);
        $this->assertStringContainsString('maintenance window', $event->message);
    }
}
