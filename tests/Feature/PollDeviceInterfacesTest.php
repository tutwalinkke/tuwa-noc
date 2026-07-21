<?php

namespace Tests\Feature;

use App\Console\Commands\PollDeviceInterfaces;
use App\Mail\BandwidthThresholdAlert;
use App\Models\Device;
use App\Models\DeviceEvent;
use App\Models\DeviceInterface;
use App\Models\DeviceInterfaceMetric;
use App\Services\AlertService;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Console\OutputStyle;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class PollDeviceInterfacesTest extends TestCase
{
    use RefreshDatabase;

    protected function callProtectedMethod(object $instance, string $method, array $args = [])
    {
        $reflection = new ReflectionMethod($instance, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($instance, $args);
    }

    /**
     * Threshold-checking methods call $this->line() for console
     * output, which requires the command to have a bound output
     * stream — normally set up by Laravel's console kernel when a
     * command actually runs via artisan. Directly instantiating the
     * command and reflectively invoking protected methods (needed to
     * unit-test the threshold logic in isolation, same pattern as the
     * existing computeRates tests) skips that setup entirely, so it
     * has to be done manually here.
     */
    protected function makeCommandWithOutput(): PollDeviceInterfaces
    {
        $command = new PollDeviceInterfaces();
        $command->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput()));

        return $command;
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

    // --- Integration: real SNMP walk against local snmpd on 127.0.0.1 ---

    public function test_devices_without_snmp_community_are_skipped(): void
    {
        Device::create([
            'tenant_id' => 1,
            'name' => 'No SNMP Device',
            'ip_address' => '127.0.0.1',
            'type' => 'server',
            'status' => 'unknown',
            'snmp_community' => null,
        ]);

        $this->artisan('devices:poll-snmp')->assertExitCode(0);

        $this->assertSame(0, DeviceInterface::count());
    }

    public function test_polling_reachable_snmp_device_creates_interface_records(): void
    {
        Device::create([
            'tenant_id' => 1,
            'name' => 'Local SNMP Device',
            'ip_address' => '127.0.0.1',
            'type' => 'server',
            'status' => 'unknown',
            'snmp_community' => 'public',
        ]);

        $this->artisan('devices:poll-snmp')->assertExitCode(0);

        $this->assertGreaterThan(0, DeviceInterface::count());
        $this->assertGreaterThan(0, DeviceInterfaceMetric::count());

        $upInterface = DeviceInterfaceMetric::where('oper_status', 'up')->first();
        $this->assertNotNull($upInterface, 'Expected at least one interface reporting up status.');
    }

    public function test_unreachable_snmp_device_does_not_crash_the_command(): void
    {
        Device::create([
            'tenant_id' => 1,
            'name' => 'Wrong Community Device',
            'ip_address' => '127.0.0.1',
            'type' => 'server',
            'status' => 'unknown',
            // Deliberately wrong community — snmpd will reject this,
            // simulating an unreachable/misconfigured SNMP target.
            'snmp_community' => 'definitely-not-the-real-community',
        ]);

        $this->artisan('devices:poll-snmp')->assertExitCode(0);

        $this->assertSame(0, DeviceInterface::count());
    }

    // --- Unit-style: deterministic tests of the tricky parsing/rate logic ---

    public function test_clean_snmp_value_strips_string_type_prefix(): void
    {
        $command = new PollDeviceInterfaces();

        $result = $this->callProtectedMethod($command, 'cleanSnmpValue', ['STRING: "eth0"']);

        $this->assertSame('eth0', $result);
    }

    public function test_clean_snmp_value_strips_counter32_type_prefix(): void
    {
        $command = new PollDeviceInterfaces();

        $result = $this->callProtectedMethod($command, 'cleanSnmpValue', ['Counter32: 123456789']);

        $this->assertSame('123456789', $result);
    }

    public function test_clean_snmp_value_strips_integer_type_prefix(): void
    {
        $command = new PollDeviceInterfaces();

        $result = $this->callProtectedMethod($command, 'cleanSnmpValue', ['INTEGER: 1']);

        $this->assertSame('1', $result);
    }

    public function test_compute_rates_returns_null_with_no_previous_metric(): void
    {
        $command = new PollDeviceInterfaces();

        [$inBps, $outBps] = $this->callProtectedMethod($command, 'computeRates', [
            null, 1000, 2000, now(),
        ]);

        $this->assertNull($inBps);
        $this->assertNull($outBps);
    }

    public function test_compute_rates_calculates_correct_bits_per_second(): void
    {
        $command = new PollDeviceInterfaces();

        $interface = DeviceInterface::create([
            'device_id' => Device::create([
                'tenant_id' => 1, 'name' => 'D', 'ip_address' => '127.0.0.1', 'type' => 'server', 'status' => 'up',
            ])->id,
            'tenant_id' => 1,
            'if_index' => 1,
            'name' => 'test0',
        ]);

        // Round down to a whole second BEFORE use — Eloquent's
        // datetime cast truncates microseconds on save, so comparing a
        // microsecond-precision timestamp against a post-save (truncated)
        // one would introduce sub-second drift into the elapsed-time math.
        $fixedNow = now()->startOfSecond();
        $previous = DeviceInterfaceMetric::create([
            'device_interface_id' => $interface->id,
            'tenant_id' => 1,
            'oper_status' => 'up',
            'in_octets' => 1000,
            'out_octets' => 500,
            'polled_at' => $fixedNow->copy()->subSeconds(10),
        ]);

        // 10 seconds elapsed, 10000 bytes in = 80000 bits / 10s = 8000 bps.
        // Using one fixed timestamp for both sides avoids real wall-clock
        // drift between test setup and assertion producing a slightly
        // different elapsed time than intended.
        [$inBps, $outBps] = $this->callProtectedMethod($command, 'computeRates', [
            $previous, 11000, 5500, $fixedNow,
        ]);

        $this->assertSame(8000, $inBps);
        $this->assertSame(4000, $outBps);
    }

    public function test_compute_rates_returns_null_when_counter_wraps_or_resets(): void
    {
        $command = new PollDeviceInterfaces();

        $interface = DeviceInterface::create([
            'device_id' => Device::create([
                'tenant_id' => 1, 'name' => 'D', 'ip_address' => '127.0.0.1', 'type' => 'server', 'status' => 'up',
            ])->id,
            'tenant_id' => 1,
            'if_index' => 1,
            'name' => 'test0',
        ]);

        $previous = DeviceInterfaceMetric::create([
            'device_interface_id' => $interface->id,
            'tenant_id' => 1,
            'oper_status' => 'up',
            'in_octets' => 5000,
            'out_octets' => 5000,
            'polled_at' => now()->subSeconds(10),
        ]);

        // New value is LOWER than previous — simulates a device reboot
        // or 32-bit counter wraparound. Must not report a bogus rate.
        [$inBps, $outBps] = $this->callProtectedMethod($command, 'computeRates', [
            $previous, 100, 100, now(),
        ]);

        $this->assertNull($inBps);
        $this->assertNull($outBps);
    }

    // --- Bandwidth threshold alerting ---

    public function test_threshold_check_does_nothing_when_no_threshold_set(): void
    {
        $device = Device::create([
            'tenant_id' => 1, 'name' => 'D', 'ip_address' => '127.0.0.1', 'type' => 'server', 'status' => 'up',
            'alert_threshold_in_bps' => null,
        ]);

        $command = $this->makeCommandWithOutput();
        $alertService = app(AlertService::class);
        $incidentService = app(IncidentService::class);

        $this->callProtectedMethod($command, 'checkThresholdDirection', [
            $device, 'in', 5_000_000, $device->alert_threshold_in_bps, $alertService, $incidentService,
        ]);

        $this->assertSame(0, DeviceEvent::count());
    }

    public function test_breaching_threshold_creates_warning_event_and_sends_alert(): void
    {
        Mail::fake();
        $this->fakeIdentityUsers(tenantId: 1);

        $device = Device::create([
            'tenant_id' => 1, 'name' => 'D', 'ip_address' => '127.0.0.1', 'type' => 'server', 'status' => 'up',
            'alert_threshold_in_bps' => 1_000_000,
        ]);

        $command = $this->makeCommandWithOutput();
        $alertService = app(AlertService::class);
        $incidentService = app(IncidentService::class);

        $this->callProtectedMethod($command, 'checkThresholdDirection', [
            $device, 'in', 2_000_000, $device->alert_threshold_in_bps, $alertService, $incidentService,
        ]);

        $event = DeviceEvent::first();
        $this->assertNotNull($event);
        $this->assertSame('warning', $event->severity);
        $this->assertSame('bandwidth_threshold_in', $event->type);
        $this->assertSame('breached', $event->new_status);

        Mail::assertQueued(BandwidthThresholdAlert::class);
    }

    public function test_staying_under_threshold_does_not_create_an_event(): void
    {
        Mail::fake();

        $device = Device::create([
            'tenant_id' => 1, 'name' => 'D', 'ip_address' => '127.0.0.1', 'type' => 'server', 'status' => 'up',
            'alert_threshold_in_bps' => 5_000_000,
        ]);

        $command = $this->makeCommandWithOutput();
        $alertService = app(AlertService::class);
        $incidentService = app(IncidentService::class);

        $this->callProtectedMethod($command, 'checkThresholdDirection', [
            $device, 'in', 1_000_000, $device->alert_threshold_in_bps, $alertService, $incidentService,
        ]);

        $this->assertSame(0, DeviceEvent::count());
        Mail::assertNotQueued(BandwidthThresholdAlert::class);
    }

    public function test_sustained_breach_does_not_repeat_the_alert_every_poll(): void
    {
        Mail::fake();
        $this->fakeIdentityUsers(tenantId: 1);

        $device = Device::create([
            'tenant_id' => 1, 'name' => 'D', 'ip_address' => '127.0.0.1', 'type' => 'server', 'status' => 'up',
            'alert_threshold_in_bps' => 1_000_000,
        ]);

        $command = $this->makeCommandWithOutput();
        $alertService = app(AlertService::class);
        $incidentService = app(IncidentService::class);

        // First poll over threshold — genuine transition, should alert.
        $this->callProtectedMethod($command, 'checkThresholdDirection', [
            $device, 'in', 2_000_000, $device->alert_threshold_in_bps, $alertService, $incidentService,
        ]);

        // Second poll, still over threshold — sustained condition, not
        // a new transition. Must not create a second event or resend.
        $this->callProtectedMethod($command, 'checkThresholdDirection', [
            $device, 'in', 2_500_000, $device->alert_threshold_in_bps, $alertService, $incidentService,
        ]);

        $this->assertSame(1, DeviceEvent::count());
        Mail::assertQueued(BandwidthThresholdAlert::class, 1);
    }

    public function test_recovering_below_threshold_logs_an_info_event_without_alerting(): void
    {
        Mail::fake();
        $this->fakeIdentityUsers(tenantId: 1);

        $device = Device::create([
            'tenant_id' => 1, 'name' => 'D', 'ip_address' => '127.0.0.1', 'type' => 'server', 'status' => 'up',
            'alert_threshold_in_bps' => 1_000_000,
        ]);

        $command = $this->makeCommandWithOutput();
        $alertService = app(AlertService::class);
        $incidentService = app(IncidentService::class);

        // Breach, then recover.
        $this->callProtectedMethod($command, 'checkThresholdDirection', [
            $device, 'in', 2_000_000, $device->alert_threshold_in_bps, $alertService, $incidentService,
        ]);
        $this->callProtectedMethod($command, 'checkThresholdDirection', [
            $device, 'in', 500_000, $device->alert_threshold_in_bps, $alertService, $incidentService,
        ]);

        $this->assertSame(2, DeviceEvent::count());

        $latestEvent = DeviceEvent::orderByDesc('created_at')->orderByDesc('id')->first();
        $this->assertSame('info', $latestEvent->severity);
        $this->assertSame('normal', $latestEvent->new_status);

        // Only ONE alert email — the original breach, not the recovery.
        Mail::assertQueued(BandwidthThresholdAlert::class, 1);
    }

    public function test_in_and_out_thresholds_are_tracked_independently(): void
    {
        Mail::fake();
        $this->fakeIdentityUsers(tenantId: 1);

        $device = Device::create([
            'tenant_id' => 1, 'name' => 'D', 'ip_address' => '127.0.0.1', 'type' => 'server', 'status' => 'up',
            'alert_threshold_in_bps' => 1_000_000,
            'alert_threshold_out_bps' => 500_000,
        ]);

        $command = $this->makeCommandWithOutput();
        $alertService = app(AlertService::class);
        $incidentService = app(IncidentService::class);

        // Only outbound breaches; inbound stays fine.
        $this->callProtectedMethod($command, 'checkBandwidthThresholds', [
            $device, 200_000, 800_000, $alertService, $incidentService,
        ]);

        $inEvent = DeviceEvent::where('type', 'bandwidth_threshold_in')->first();
        $outEvent = DeviceEvent::where('type', 'bandwidth_threshold_out')->first();

        $this->assertNull($inEvent);
        $this->assertNotNull($outEvent);
        $this->assertSame('breached', $outEvent->new_status);
    }
}
