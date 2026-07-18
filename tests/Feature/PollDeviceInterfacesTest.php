<?php

namespace Tests\Feature;

use App\Console\Commands\PollDeviceInterfaces;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\DeviceInterfaceMetric;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
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
}
