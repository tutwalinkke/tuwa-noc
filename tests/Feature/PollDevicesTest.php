<?php

namespace Tests\Feature;

use App\Mail\DeviceDownAlert;
use App\Models\Device;
use App\Models\DeviceEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PollDevicesTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_polling_a_reachable_device_marks_it_up(): void
    {
        $device = Device::create([
            'tenant_id' => 1,
            'name' => 'Loopback',
            'ip_address' => '127.0.0.1',
            'type' => 'server',
            'status' => 'unknown',
        ]);

        $this->artisan('devices:poll')->assertExitCode(0);

        $device->refresh();
        $this->assertSame('up', $device->status);
        $this->assertNotNull($device->last_checked_at);
        $this->assertNotNull($device->last_seen_up_at);
    }

    public function test_polling_an_unreachable_device_marks_it_down(): void
    {
        $device = Device::create([
            'tenant_id' => 1,
            'name' => 'Unreachable Test Address',
            // 192.0.2.0/24 is IANA TEST-NET-1 — reserved for documentation,
            // guaranteed never to respond, unlike a real IP that could flake.
            'ip_address' => '192.0.2.1',
            'type' => 'server',
            'status' => 'unknown',
        ]);

        $this->artisan('devices:poll')->assertExitCode(0);

        $device->refresh();
        $this->assertSame('down', $device->status);
        $this->assertNotNull($device->last_checked_at);
        $this->assertNull($device->last_seen_up_at);
    }

    public function test_status_change_logs_an_event(): void
    {
        $device = Device::create([
            'tenant_id' => 1,
            'name' => 'Loopback',
            'ip_address' => '127.0.0.1',
            'type' => 'server',
            'status' => 'unknown',
        ]);

        $this->assertSame(0, DeviceEvent::count());

        $this->artisan('devices:poll');

        $this->assertSame(1, DeviceEvent::count());
        $event = DeviceEvent::first();
        $this->assertSame('unknown', $event->previous_status);
        $this->assertSame('up', $event->new_status);
    }

    public function test_no_event_logged_when_status_does_not_change(): void
    {
        $device = Device::create([
            'tenant_id' => 1,
            'name' => 'Loopback',
            'ip_address' => '127.0.0.1',
            'type' => 'server',
            'status' => 'up',
            'last_checked_at' => now(),
            'last_seen_up_at' => now(),
        ]);

        $this->artisan('devices:poll');

        // Already 'up' before poll, still 'up' after — no transition, no event.
        $this->assertSame(0, DeviceEvent::count());
    }

    public function test_device_going_down_sends_alert_email(): void
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

    public function test_service_account_is_excluded_from_alert_recipients(): void
    {
        Mail::fake();

        // Two candidate recipients: a genuine human admin, and the
        // known NOC service account — which technically holds
        // super-admin for API access purposes but must never actually
        // receive alert emails. Real bug found and fixed: this
        // exclusion did not exist before, and the service account's
        // address (not a real monitored inbox) was silently failing
        // delivery for every alert, forever.
        Http::fake([
            '*/api/v1/users*' => Http::response([
                'users' => [
                    [
                        'id' => 1,
                        'tenant_id' => 1,
                        'email' => 'real-admin@example.com',
                        'status' => 'active',
                        'roles' => [['name' => 'super-admin']],
                    ],
                    [
                        'id' => 2,
                        'tenant_id' => 1,
                        'email' => 'noc-service@tuwalink.com',
                        'status' => 'active',
                        'roles' => [['name' => 'super-admin']],
                    ],
                ],
            ], 200),
        ]);

        Device::create([
            'tenant_id' => 1,
            'name' => 'Unreachable Test Address',
            'ip_address' => '192.0.2.1',
            'type' => 'server',
            'status' => 'up',
        ]);

        $this->artisan('devices:poll');

        Mail::assertQueued(DeviceDownAlert::class, function ($mail) {
            return $mail->hasTo('real-admin@example.com');
        });

        Mail::assertNotQueued(DeviceDownAlert::class, function ($mail) {
            return $mail->hasTo('noc-service@tuwalink.com');
        });
    }

    public function test_device_recovering_does_not_send_alert_email(): void
    {
        Mail::fake();
        $this->fakeIdentityUsers(tenantId: 1);

        Device::create([
            'tenant_id' => 1,
            'name' => 'Loopback',
            'ip_address' => '127.0.0.1',
            'type' => 'server',
            'status' => 'down',
        ]);

        $this->artisan('devices:poll');

        Mail::assertNotQueued(DeviceDownAlert::class);
    }

    public function test_polling_with_no_devices_does_not_error(): void
    {
        $this->artisan('devices:poll')->assertExitCode(0);

        $this->assertSame(0, DeviceEvent::count());
    }
}
