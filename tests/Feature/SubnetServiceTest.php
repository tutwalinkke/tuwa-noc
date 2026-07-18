<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\IpAddress;
use App\Models\Subnet;
use App\Services\SubnetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SubnetServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SubnetService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SubnetService();
    }

    public function test_generates_correct_usable_addresses_for_slash_29(): void
    {
        $addresses = $this->service->generateUsableAddresses('10.0.0.0/29');

        $this->assertCount(6, $addresses);
        $this->assertSame('10.0.0.1', $addresses[0]);
        $this->assertSame('10.0.0.6', $addresses[5]);
        // Network (.0) and broadcast (.7) must both be excluded.
        $this->assertNotContains('10.0.0.0', $addresses);
        $this->assertNotContains('10.0.0.7', $addresses);
    }

    public function test_generates_correct_usable_addresses_for_slash_24(): void
    {
        $addresses = $this->service->generateUsableAddresses('192.168.1.0/24');

        $this->assertCount(254, $addresses);
        $this->assertSame('192.168.1.1', $addresses[0]);
        $this->assertSame('192.168.1.254', $addresses[253]);
    }

    public function test_slash_31_includes_both_addresses_with_no_exclusion(): void
    {
        // /31 is a special case (point-to-point links) — both addresses
        // are usable, unlike normal subnets which exclude network/broadcast.
        $addresses = $this->service->generateUsableAddresses('10.0.0.0/31');

        $this->assertCount(2, $addresses);
        $this->assertContains('10.0.0.0', $addresses);
        $this->assertContains('10.0.0.1', $addresses);
    }

    public function test_rejects_cidr_without_slash(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->generateUsableAddresses('10.0.0.0');
    }

    public function test_rejects_invalid_network_address(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->generateUsableAddresses('not-an-ip/24');
    }

    public function test_rejects_oversized_subnet(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->generateUsableAddresses('10.0.0.0/16');
    }

    public function test_create_subnet_with_addresses_persists_correct_count(): void
    {
        $subnet = $this->service->createSubnetWithAddresses(
            tenantId: 1,
            cidr: '10.0.0.0/29',
            description: 'Test',
            site: null,
            vlanId: null,
        );

        $this->assertSame(6, $subnet->ipAddresses()->count());
        $this->assertSame(6, IpAddress::where('status', 'available')->count());
    }

    public function test_allocate_next_available_returns_lowest_address_first(): void
    {
        $subnet = $this->service->createSubnetWithAddresses(1, '10.0.0.0/29', null, null, null);

        $allocated = $this->service->allocateNextAvailable($subnet, null, 'first');

        $this->assertSame('10.0.0.1', $allocated->address);
        $this->assertSame('allocated', $allocated->status);
    }

    public function test_allocate_next_available_skips_already_allocated_addresses(): void
    {
        $subnet = $this->service->createSubnetWithAddresses(1, '10.0.0.0/29', null, null, null);

        $first = $this->service->allocateNextAvailable($subnet, null, 'first');
        $second = $this->service->allocateNextAvailable($subnet, null, 'second');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('10.0.0.2', $second->address);
    }

    public function test_allocate_returns_null_when_subnet_is_exhausted(): void
    {
        $subnet = $this->service->createSubnetWithAddresses(1, '10.0.0.0/31', null, null, null);

        $this->service->allocateNextAvailable($subnet, null, 'a');
        $this->service->allocateNextAvailable($subnet, null, 'b');

        $result = $this->service->allocateNextAvailable($subnet, null, 'c');

        $this->assertNull($result);
    }

    public function test_allocation_can_be_linked_to_a_device(): void
    {
        $device = Device::create([
            'tenant_id' => 1, 'name' => 'Test Device', 'ip_address' => '192.168.0.1', 'type' => 'router', 'status' => 'up',
        ]);
        $subnet = $this->service->createSubnetWithAddresses(1, '10.0.0.0/29', null, null, null);

        $allocated = $this->service->allocateNextAvailable($subnet, $device->id, null);

        $this->assertSame($device->id, $allocated->device_id);
    }
}
